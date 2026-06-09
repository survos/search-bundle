<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\SqliteFts5;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\SyntaxErrorException;
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
            'facetCountTable' => null,
            'facetValueTable' => null,
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
        $resolver->setAllowedTypes('facetCountTable', ['null', 'string']);
        $resolver->setAllowedTypes('facetValueTable', ['null', 'string']);
    }

    public function search(Query $query, SearchInterface $search): ResultSet
    {
        try {
            return $this->doSearch($query, $search);
        } catch (SyntaxErrorException $e) {
            // A malformed MATCH expression (notably from the `#` raw escape hatch)
            // returns no results rather than killing the page. Genuine SQL syntax
            // errors are programming bugs, so let those keep propagating.
            if (!str_contains($e->getMessage(), 'fts5')) {
                throw $e;
            }

            return $this->emptyResultSet($query, $search);
        }
    }

    /**
     * A fully-formed empty result set: zero hits, but with an empty distribution
     * for every configured facet (preserving the user's checked values) so the
     * facet templates still render. Returned when a malformed MATCH is swallowed;
     * without the facet entries the template throws "Facet distribution … not found".
     */
    private function emptyResultSet(Query $query, SearchInterface $search): ResultSet
    {
        $distributions = [];
        foreach ($search->getFacets() as $facet) {
            $filter = $query->getActiveFilter($facet->getProperty());
            $distributions[$facet->getProperty()] = (new FacetTermDistribution())
                ->setProperty($facet->getProperty())
                ->setValues([])
                ->setCheckedValues($filter instanceof TermFilter ? $filter->getValues() : []);
        }

        return (new ResultSet())
            ->setIndexUid($search->getIndexName())
            ->setHits([])
            ->setTotalResults(0)
            ->setFacetDistributions($distributions);
    }

    private function doSearch(Query $query, SearchInterface $search): ResultSet
    {
        $params = $this->baseParams($search);
        $where = $this->baseWhere($query, $search, $params);
        $this->applyFilters($query, $search, $where, $params);

        $orderBy = $this->orderBy($query, $search);
        $limit = max(1, $query->getActiveHitsPerPage());
        $offset = $limit * max(0, $query->getCurrentPage() - 1);
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $usesFts = $this->usesFts($query);
        $score = $usesFts ? sprintf('bm25(%s)', $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('ftsTable'))) : '0';

        $sql = sprintf(
            'SELECT %s, %s AS _score FROM %s%s%s %s LIMIT :limit OFFSET :offset',
            $this->selectList($this->connection, $search->getResolvedAdapterParameter('selectColumns')),
            $score,
            $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('table')) . ' d',
            $this->joinClause($search, $usesFts),
            $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
            $orderBy,
        );

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();
        $hits = array_map(
            static fn (array $row): Hit => new Hit($row, isset($row['_score']) ? (float) $row['_score'] : 0.0),
            $rows,
        );

        $countSql = sprintf(
            'SELECT COUNT(*) FROM %s%s%s',
            $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('table')) . ' d',
            $this->joinClause($search, $usesFts),
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
    private function baseWhere(Query $query, SearchInterface $search, array &$params, bool $ftsInWhere = true): array
    {
        $where = [];
        if (is_string($search->getResolvedAdapterParameter('where'))) {
            $where[] = $search->getResolvedAdapterParameter('where');
        }

        $ftsQuery = Fts5MatchQuery::build($query->getQueryString());
        if ($ftsQuery !== '') {
            $params['ftsQuery'] = $ftsQuery;
            // Facet aggregations materialize the MATCH in a CTE (see ftsCtePrefix) so
            // the highly-selective FTS match drives the join; they bind :ftsQuery but
            // do not want the MATCH predicate inlined here.
            if ($ftsInWhere) {
                $where[] = sprintf('%s MATCH :ftsQuery', $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('ftsTable')));
            }
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
        // With a text query, order by relevance (FTS5 bm25() ranks lower = better).
        // Browse (no query) uses the column sort.
        if ($this->usesFts($query)) {
            return 'ORDER BY _score ASC';
        }

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

        return '';
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
            $where = $this->baseWhere($query, $search, $params, ftsInWhere: false);
            $this->applyFilters($query, $search, $where, $params, $facet->getProperty());
            $params['maxFacetValues'] = $search->getResolvedAdapterParameter('maxFacetValues');

            $countTable = $search->getResolvedAdapterParameter('facetCountTable');
            $valueTable = $search->getResolvedAdapterParameter('facetValueTable');
            if (is_string($countTable) && !$this->hasActiveFilters($query, $facet->getProperty()) && !$this->usesFts($query)) {
                $sql = sprintf(
                    'SELECT value, total FROM %s WHERE field = :facetField ORDER BY total DESC LIMIT :maxFacetValues',
                    $this->connection->quoteIdentifier($countTable),
                );
                $params['facetField'] = $facet->getProperty();
            } elseif (is_string($valueTable)) {
                $usesFts = $this->usesFts($query);
                $params['facetField'] = $facet->getProperty();
                $where[] = 'fv.field = :facetField';
                $sql = sprintf(
                    '%sSELECT fv.value AS value, COUNT(*) AS total FROM %s fv JOIN %s ON d.rowid = fv.item_rowid%s%s GROUP BY fv.value ORDER BY total DESC LIMIT :maxFacetValues',
                    $this->ftsCtePrefix($search, $usesFts),
                    $this->connection->quoteIdentifier($valueTable),
                    $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('table')) . ' d',
                    $this->joinClause($search, $usesFts, '__fts'),
                    ' WHERE ' . implode(' AND ', $where),
                );
            } else {
                $usesFts = $this->usesFts($query);
                $sql = sprintf(
                    '%sSELECT %s AS value, COUNT(*) AS total FROM %s%s%s GROUP BY %s ORDER BY total DESC LIMIT :maxFacetValues',
                    $this->ftsCtePrefix($search, $usesFts),
                    $column,
                    $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('table')) . ' d',
                    $this->joinClause($search, $usesFts, '__fts'),
                    $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
                    $column,
                );
            }

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
            $component = $facet->getDisplayComponent();
            if ($component === null
                || !is_subclass_of($component, \Mezcalito\UxSearchBundle\Twig\Components\Facet\AbstractFacet::class)
                || !$component::usesFacetStats()) {
                continue;
            }

            $filter = $query->getActiveFilter($facet->getProperty());
            $column = $this->columnFor($search, 'facetColumns', $facet->getProperty());
            $params = $this->baseParams($search);
            $where = $this->baseWhere($query, $search, $params, ftsInWhere: false);
            $this->applyFilters($query, $search, $where, $params, $facet->getProperty());

            $usesFts = $this->usesFts($query);
            $sql = sprintf(
                '%sSELECT MIN(%s) AS min_value, MAX(%s) AS max_value FROM %s%s%s',
                $this->ftsCtePrefix($search, $usesFts),
                $column,
                $column,
                $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('table')) . ' d',
                $this->joinClause($search, $usesFts, '__fts'),
                $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
            );
            $row = $this->connection->executeQuery($sql, $params)->fetchAssociative();
            if (!$row) {
                continue;
            }

            $min = $row['min_value'];
            $max = $row['max_value'];
            if ($min === null || $max === null) {
                $min = 0;
                $max = 0;
            }
            if (!is_numeric($min) || !is_numeric($max)) {
                continue;
            }

            $stats[$facet->getProperty()] = new FacetStat(
                $facet->getProperty(),
                (float) $min,
                (float) $max,
                $filter instanceof RangeFilter ? $filter->getMin() : null,
                $filter instanceof RangeFilter ? $filter->getMax() : null,
            );
        }

        return $stats;
    }


    private function usesFts(Query $query): bool
    {
        return Fts5MatchQuery::build($query->getQueryString()) !== '';
    }

    private function joinClause(SearchInterface $search, bool $usesFts, ?string $ftsSource = null): string
    {
        if (!$usesFts) {
            return '';
        }

        // The CTE in ftsCtePrefix() is aliased back to `f`, so the configured
        // joinExpression (default `f.rowid = d.rowid`) works against either source.
        $source = $ftsSource ?? $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('ftsTable'));

        return sprintf(
            ' JOIN %s f ON %s',
            $source,
            $search->getResolvedAdapterParameter('joinExpression'),
        );
    }

    /**
     * Prefix that materializes the matching FTS rowids once, so the selective MATCH
     * drives facet GROUP BY aggregations. Without it SQLite drives those queries from
     * the broad facet(field) index and probes the FTS virtual table per row, which is
     * orders of magnitude slower (measured ~30x on cleveland.folio).
     */
    private function ftsCtePrefix(SearchInterface $search, bool $usesFts): string
    {
        if (!$usesFts) {
            return '';
        }

        $fts = $this->connection->quoteIdentifier($search->getResolvedAdapterParameter('ftsTable'));

        return sprintf('WITH __fts AS MATERIALIZED (SELECT rowid FROM %1$s WHERE %1$s MATCH :ftsQuery) ', $fts);
    }

    private function hasActiveFilters(Query $query, ?string $skipProperty = null): bool
    {
        foreach ($query->getActiveFilters() as $filter) {
            if ($filter->getProperty() === $skipProperty) {
                continue;
            }

            if ($filter instanceof TermFilter && $filter->hasValues()) {
                return true;
            }

            if ($filter instanceof RangeFilter && ($filter->getMin() !== null || $filter->getMax() !== null)) {
                return true;
            }

            if (!$filter instanceof TermFilter && !$filter instanceof RangeFilter) {
                return true;
            }
        }

        return false;
    }
}
