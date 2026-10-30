<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\DuckDb;

use Survos\SearchBundle\Adapter\AdapterInterface;
use Survos\SearchBundle\Profiler\DuckDbQueryRecorder;
use Survos\SearchBundle\Search\Filter\RangeFilter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\ResultSet\FacetStat;
use Survos\SearchBundle\Search\ResultSet\FacetTermDistribution;
use Survos\SearchBundle\Search\ResultSet\Hit;
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Survos\SearchBundle\Search\SearchInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Native PDO queries. SQL expressions are trusted application configuration, never request data. */
final readonly class DuckDbAdapter implements AdapterInterface
{
    public function __construct(private \PDO|DuckDbConnectionProviderInterface $connection, private ?DuckDbQueryRecorder $recorder = null)
    {
    }

    public function configureParameters(OptionsResolver $resolver): void
    {
        $resolver->setRequired('table');
        $resolver->setAllowedTypes('table', 'string');
        foreach ([
            'idColumn' => 'd.id', 'selectColumns' => ['d.*'], 'facetColumns' => [],
            'listFacets' => [], 'rangeFacets' => [], 'sortColumns' => [],
            'where' => '', 'params' => [], 'textColumns' => [], 'ftsFunction' => '',
            'connectionOptions' => [], 'maxFacetValues' => 100,
        ] as $key => $value) {
            $resolver->setDefault($key, $value)->setAllowedTypes($key, get_debug_type($value));
        }
        $resolver->setAllowedValues('maxFacetValues', static fn (int $value): bool => $value >= 1 && $value <= 10000);
    }

    public function search(Query $query, SearchInterface $search): ResultSet
    {
        $p = $search->getResolvedAdapterParameters();
        $dataset = (string) ($p['connectionOptions']['datasetCode'] ?? $search->getIndexName() ?? 'duckdb');
        $db = $this->connection instanceof \PDO ? $this->connection : $this->connection->connect($p['connectionOptions']);
        if ('duckdb' !== $db->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            throw new \InvalidArgumentException('DuckDbAdapter requires native PDO DuckDB.');
        }
        [$prefix, $parameters, $textPredicate] = $this->base($query, $p);
        [$where, $bindings] = $this->filters($query, $p, $textPredicate, $parameters);
        $from = ' FROM candidates d'.$where;
        $total = (int) $this->execute($db, $prefix.'SELECT count(*)'.$from, $bindings, $dataset)->fetchColumn();
        $sort = $query->getActiveSort();
        if ('' === $sort) {
            $sort = null;
        }
        if (null !== $sort && !array_key_exists($sort, $p['sortColumns'])) {
            throw new \InvalidArgumentException('Unknown DuckDB sort: '.$sort);
        }
        $order = null !== $sort ? $p['sortColumns'][$sort].', ' : ('' !== $p['ftsFunction'] && '' !== trim($query->getQueryString()) ? 'd._duckScore DESC, ' : '');
        $limit = max(1, $query->getActiveHitsPerPage());
        $offset = $limit * max(0, $query->getCurrentPage() - 1);
        $rows = $this->execute($db, $prefix.'SELECT '.implode(', ', $p['selectColumns']).', d._duckScore AS _score'.$from.' ORDER BY '.$order.$p['idColumn'].' LIMIT '.$limit.' OFFSET '.$offset, $bindings, $dataset)->fetchAll(\PDO::FETCH_ASSOC);
        $hits = array_map(static function (array $row): Hit {
            $score = (float) $row['_score'];
            unset($row['_score'], $row['_duckScore']);

            return new Hit($row, $score);
        }, $rows);
        $distributions = $stats = [];
        foreach ($search->getFacets() as $facet) {
            $name = $facet->getProperty();
            $column = $this->column($p, $name);
            [$facetWhere, $facetBindings] = $this->filters($query, $p, $textPredicate, $parameters, $name);
            $filter = $query->getActiveFilter($name);
            if (in_array($name, $p['rangeFacets'], true)) {
                $range = $this->execute($db, $prefix.'SELECT min('.$column.') AS lo, max('.$column.') AS hi FROM candidates d'.$facetWhere, $facetBindings, $dataset)->fetch(\PDO::FETCH_ASSOC);
                if (null !== $range['lo']) {
                    $stats[$name] = new FacetStat($name, $range['lo'], $range['hi'], $filter instanceof RangeFilter ? $filter->getMin() : null, $filter instanceof RangeFilter ? $filter->getMax() : null);
                }
                $values = [];
            } else {
                $expression = in_array($name, $p['listFacets'], true) ? 'unnest('.$column.')' : $column;
                // COUNT DISTINCT prevents duplicate list members from counting a document twice.
                $sql = $prefix.'SELECT value, count(DISTINCT record_id) AS total FROM (SELECT '.$p['idColumn'].' AS record_id, '.$expression.' AS value FROM candidates d'.$facetWhere.') f WHERE value IS NOT NULL GROUP BY value ORDER BY total DESC, value LIMIT '.$p['maxFacetValues'];
                $values = [];
                foreach ($this->execute($db, $sql, $facetBindings, $dataset)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $values[$row['value']] = (int) $row['total'];
                }
            }
            $distributions[$name] = (new FacetTermDistribution())->setProperty($name)->setValues($values)->setCheckedValues($filter instanceof TermFilter ? $filter->getValues() : []);
        }

        return (new ResultSet())->setIndexUid($search->getIndexName())->setHits($hits)->setTotalResults($total)->setFacetDistributions($distributions)->setFacetStats($stats);
    }

    private function base(Query $query, array $p): array
    {
        if (!array_is_list($p['params'])) {
            throw new \InvalidArgumentException('DuckDB scope params must be a positional list.');
        }
        $bindings = $p['params'];
        $scope = '' !== $p['where'] ? ' WHERE '.$p['where'] : '';
        $text = trim($query->getQueryString());
        $predicate = '';
        $score = '0.0';
        if ('' !== $text && '' !== $p['ftsFunction']) {
            $score = $p['ftsFunction'].'('.$p['idColumn'].', ?)';
            array_unshift($bindings, $text);
            $predicate = 'd._duckScore IS NOT NULL';
        } elseif ('' !== $text) {
            if ([] === $p['textColumns']) {
                throw new \LogicException('Configure textColumns or a built FTS function for text queries.');
            }
            $terms = [];
            foreach ($p['textColumns'] as $column) {
                $terms[] = 'contains(lower(coalesce('.$column.', \'\')), lower(?))';
                $bindings[] = $text;
            }
            $predicate = '('.implode(' OR ', $terms).')';
        }

        return ['WITH candidates AS (SELECT d.*, '.$score.' AS _duckScore FROM '.$p['table'].' d'.$scope.') ', $bindings, $predicate];
    }

    private function filters(Query $query, array $p, string $text, array $bindings, ?string $skip = null): array
    {
        $clauses = '' === $text ? [] : [$text];
        foreach ($query->getActiveFilters() as $filter) {
            if ($filter->getProperty() === $skip || !$filter->hasValues()) {
                continue;
            }
            $column = $this->column($p, $filter->getProperty());
            $list = in_array($filter->getProperty(), $p['listFacets'], true);
            if ($filter instanceof TermFilter) {
                $values = array_values($filter->getValues());
                if ($list) {
                    $clauses[] = 'EXISTS (SELECT 1 FROM unnest('.$column.') v(value) WHERE v.value IN ('.implode(', ', array_fill(0, count($values), '?')).'))';
                } else {
                    $clauses[] = $column.' IN ('.implode(', ', array_fill(0, count($values), '?')).')';
                }
                array_push($bindings, ...$values);
            } elseif ($filter instanceof RangeFilter && !$list) {
                foreach ([['>=', $filter->getMin()], ['<=', $filter->getMax()]] as [$operator, $bound]) {
                    if (null !== $bound) {
                        $clauses[] = $column.' '.$operator.' ?';
                        $bindings[] = $bound;
                    }
                }
            } else {
                throw new \InvalidArgumentException('Unsupported DuckDB filter for '.$filter->getProperty());
            }
        }

        return [!$clauses ? '' : ' WHERE '.implode(' AND ', $clauses), $bindings];
    }

    private function column(array $parameters, string $property): string
    {
        return $parameters['facetColumns'][$property] ?? throw new \InvalidArgumentException('Unknown DuckDB facet/filter: '.$property);
    }

    private function execute(\PDO $db, string $sql, array $parameters, string $dataset): \PDOStatement
    {
        $start = hrtime(true);
        $error = null;
        try {
            $statement = $db->prepare($sql);
            $statement->execute($parameters);

            return $statement;
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
            throw $exception;
        } finally {
            $this->recorder?->record($dataset, $sql, $parameters, (hrtime(true) - $start) / 1e6, $error);
        }
    }
}
