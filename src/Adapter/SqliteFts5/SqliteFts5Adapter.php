<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\SqliteFts5;

use Doctrine\DBAL\Connection;
use Mezcalito\UxSearchBundle\Adapter\AdapterInterface;
use Mezcalito\UxSearchBundle\Search\Filter\RangeFilter;
use Mezcalito\UxSearchBundle\Search\Filter\TermFilter;
use Mezcalito\UxSearchBundle\Search\Query;
use Mezcalito\UxSearchBundle\Search\ResultSet\FacetStat;
use Mezcalito\UxSearchBundle\Search\ResultSet\FacetTermDistribution;
use Mezcalito\UxSearchBundle\Search\ResultSet\Hit;
use Mezcalito\UxSearchBundle\Search\ResultSet\ResultSet;
use Mezcalito\UxSearchBundle\Search\SearchInterface;
use Survos\SearchBundle\Adapter\DbalAdapterTrait;
use Symfony\Component\OptionsResolver\OptionsResolver;

final readonly class SqliteFts5Adapter implements AdapterInterface
{
    use DbalAdapterTrait;

    public function __construct(private Connection $connection) {}

    public function configureParameters(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'idColumn' => 'id',
            'selectColumns' => [],
            'searchFields' => [],
            'facetColumns' => [],
            'sortColumns' => [],
            'joinExpression' => 'f.rowid = d.rowid',
            'where' => null,
            'params' => [],
            'maxFacetValues' => 100,
        ]);

        $resolver->setRequired(['table', 'ftsTable']);
        $resolver->setAllowedTypes('table', 'string');
        $resolver->setAllowedTypes('ftsTable', 'string');
        $resolver->setAllowedTypes('idColumn', 'string');
        $resolver->setAllowedTypes('selectColumns', 'string[]');
        $resolver->setAllowedTypes('searchFields', 'string[]');
        $resolver->setAllowedTypes('facetColumns', 'array');
        $resolver->setAllowedTypes('sortColumns', 'array');
        $resolver->setAllowedTypes('joinExpression', 'string');
        $resolver->setAllowedTypes('where', ['null', 'string']);
        $resolver->setAllowedTypes('params', 'array');
        $resolver->setAllowedTypes('maxFacetValues', 'int');
    }

    public function search(Query $query, SearchInterface $search): ResultSet
    {
        $params = $this->baseParams($search);
        $where = $this->baseWhere($query, $search, $params);
        $this->applyFilters($query, $search, $where, $params);

        $orderBy = $this->orderBy($query, $search);
        $limit = max(1, $query->getActiveHitsPerPage());
        $offset = $limit * max(0, $query->getCurrentPage() - 1);
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $sql = sprintf(
            'SELECT %s, bm25(f) AS _score FROM %s d JOIN %s f ON %s%s %s LIMIT :limit OFFSET :offset',
            $this->selectList($this->connection, $search->getResolvedAdapterParameter('selectColumns')),
            $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('table')),
            $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('ftsTable')),
            $search->getResolvedAdapterParameter('joinExpression'),
            $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
            $orderBy,
        );

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();
        $hits = array_map(
            static fn (array $row): Hit => new Hit($row, isset($row['_score']) ? (float) $row['_score'] : 0.0),
            $rows,
        );

        $countSql = sprintf(
            'SELECT COUNT(*) FROM %s d JOIN %s f ON %s%s',
            $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('table')),
            $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('ftsTable')),
            $search->getResolvedAdapterParameter('joinExpression'),
            $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
        );

        return (new ResultSet())
            ->setIndexUid($search->getIndexName())
            ->setHits($hits)
            ->setTotalResults((int) $this->connection->executeQuery($countSql, $params)->fetchOne())
            ->setFacetDistributions($this->facetDistributions($query, $search))
            ->setFacetStats($this->facetStats($query, $search));
    }

    /**
     * @param array<string, mixed> $params
     * @return string[]
     */
    private function baseWhere(Query $query, SearchInterface $search, array &$params): array
    {
        $where = [];
        if (is_string($search->getResolvedAdapterParameter('where'))) {
            $where[] = $search->getResolvedAdapterParameter('where');
        }

        if ($query->getQueryString() !== '') {
            $where[] = sprintf('%s MATCH :ftsQuery', $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('ftsTable')));
            $params['ftsQuery'] = $this->escapeMatchQuery($query->getQueryString());
        }

        return $where;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseParams(SearchInterface $search): array
    {
        return $search->getResolvedAdapterParameter('params');
    }

    private function orderBy(Query $query, SearchInterface $search): string
    {
        $activeSort = $query->getActiveSort();
        if (is_string($activeSort) && str_contains($activeSort, ':')) {
            [$property, $direction] = explode(':', $activeSort, 2);
            $sortColumns = $search->getResolvedAdapterParameter('sortColumns');
            $column = is_array($sortColumns) && isset($sortColumns[$property]) ? $sortColumns[$property] : null;
            $direction = strtoupper($direction);
            if (is_string($column) && in_array($direction, ['ASC', 'DESC'], true)) {
                return sprintf('ORDER BY %s %s', $column, $direction);
            }
        }

        return $query->getQueryString() === '' ? '' : 'ORDER BY _score ASC';
    }

    /**
     * @return array<string, FacetTermDistribution>
     */
    private function facetDistributions(Query $query, SearchInterface $search): array
    {
        $distributions = [];
        foreach ($search->getFacets() as $facet) {
            $filter = $query->getActiveFilter($facet->getProperty());
            $checkedValues = $filter instanceof TermFilter ? $filter->getValues() : [];
            $column = $this->columnFor($search, 'facetColumns', $facet->getProperty());

            $params = $this->baseParams($search);
            $where = $this->baseWhere($query, $search, $params);
            $this->applyFilters($query, $search, $where, $params, $facet->getProperty());
            $params['maxFacetValues'] = $search->getResolvedAdapterParameter('maxFacetValues');

            $sql = sprintf(
                'SELECT %s AS value, COUNT(*) AS total FROM %s d JOIN %s f ON %s%s GROUP BY %s ORDER BY total DESC LIMIT :maxFacetValues',
                $column,
                $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('table')),
                $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('ftsTable')),
                $search->getResolvedAdapterParameter('joinExpression'),
                $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
                $column,
            );

            $values = [];
            foreach ($this->connection->executeQuery($sql, $params)->fetchAllAssociative() as $row) {
                if ($row['value'] !== null && $row['value'] !== '') {
                    $values[$row['value']] = (int) $row['total'];
                }
            }

            $distributions[$facet->getProperty()] = (new FacetTermDistribution())
                ->setProperty($facet->getProperty())
                ->setValues($values)
                ->setCheckedValues($checkedValues);
        }

        return $distributions;
    }

    /**
     * @return array<string, FacetStat>
     */
    private function facetStats(Query $query, SearchInterface $search): array
    {
        $stats = [];
        foreach ($search->getFacets() as $facet) {
            $filter = $query->getActiveFilter($facet->getProperty());
            if (!$filter instanceof RangeFilter) {
                continue;
            }

            $column = $this->columnFor($search, 'facetColumns', $facet->getProperty());
            $params = $this->baseParams($search);
            $where = $this->baseWhere($query, $search, $params);
            $this->applyFilters($query, $search, $where, $params, $facet->getProperty());

            $sql = sprintf(
                'SELECT MIN(%s) AS min_value, MAX(%s) AS max_value FROM %s d JOIN %s f ON %s%s',
                $column,
                $column,
                $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('table')),
                $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('ftsTable')),
                $search->getResolvedAdapterParameter('joinExpression'),
                $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
            );
            $row = $this->connection->executeQuery($sql, $params)->fetchAssociative();
            if ($row && is_numeric($row['min_value']) && is_numeric($row['max_value'])) {
                $stats[$facet->getProperty()] = new FacetStat(
                    $facet->getProperty(),
                    (float) $row['min_value'],
                    (float) $row['max_value'],
                    $filter->getMin(),
                    $filter->getMax(),
                );
            }
        }

        return $stats;
    }

    private function escapeMatchQuery(string $query): string
    {
        return str_replace('"', '""', trim($query));
    }
}
