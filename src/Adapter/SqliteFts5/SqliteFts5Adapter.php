<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\SqliteFts5;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Survos\SearchBundle\Adapter\AdapterInterface;
use Survos\SearchBundle\Search\Filter\RangeFilter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\ResultSet\FacetStat;
use Survos\SearchBundle\Search\ResultSet\FacetTermDistribution;
use Survos\SearchBundle\Search\ResultSet\Hit;
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Survos\SearchBundle\Search\SearchInterface;
use Survos\SearchBundle\Adapter\DbalAdapterTrait;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class SqliteFts5Adapter implements AdapterInterface
{
    use DbalAdapterTrait;

    public function __construct(
        private Connection $connection,
        private ?CacheInterface $facetCache = null,
        /** Ids from textMatcher for the search in flight, so hits, count and every facet share one call. */
        private \ArrayObject $textMatches = new \ArrayObject(),
    ) {}

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
            'textFallbackColumns' => [],
            'facetCountTable' => null,
            'facetValueTable' => null,
            'columnFilters' => [],
            'liveFacets' => true,
            'textMatcher' => null,
        ]);

        $resolver->setRequired(['table', 'ftsTable']);
        $resolver->setAllowedTypes('table', 'string');
        // Null for a search whose FTS index was skipped or is missing: text queries then use
        // textFallbackColumns (LIKE) instead of MATCH. Required all the same, so leaving it out
        // stays a configuration error rather than a silent downgrade.
        $resolver->setAllowedTypes('ftsTable', ['null', 'string']);
        $resolver->setAllowedTypes('textFallbackColumns', 'string[]');
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
        $resolver->setAllowedTypes('columnFilters', 'string[]');
        $resolver->setAllowedTypes('liveFacets', 'bool');
        // For a search with no FTS table whose text lives in another engine: called with the query
        // string, returns the matching idColumn values best first, or null when that engine cannot
        // answer (down, or this data not indexed there yet) — then textFallbackColumns apply.
        $resolver->setAllowedTypes('textMatcher', ['null', 'callable']);
    }

    public function search(Query $query, SearchInterface $search): ResultSet
    {
        $this->textMatches->exchangeArray([]);
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

        $usesFts = $this->usesFts($query, $search);
        $matched = $this->matchedIds($query, $search) !== null;
        $score = match (true) {
            $matched => 'f.rank',
            $usesFts => sprintf('bm25(%s)', $this->connection->quoteSingleIdentifier($search->getResolvedAdapterParameter('ftsTable'))),
            default => '0',
        };
        // Matched ids come in as a CTE, which the hit and count queries need as much as the facets.
        $prefix = $matched ? $this->ftsCtePrefix($query, $search, true) : '';

        $sql = sprintf(
            '%sSELECT %s, %s AS _score FROM %s%s %s LIMIT :limit OFFSET :offset',
            $prefix,
            $this->selectList($this->connection, $search->getResolvedAdapterParameter('selectColumns')),
            $score,
            $this->fromClause($query, $search, $usesFts),
            $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
            $orderBy,
        );

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();
        $hits = array_map(
            static fn (array $row): Hit => new Hit($row, isset($row['_score']) ? (float) $row['_score'] : 0.0),
            $rows,
        );

        $countSql = sprintf(
            '%sSELECT COUNT(*) FROM %s%s',
            $prefix,
            $this->fromClause($query, $search, $usesFts),
            $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
        );

        return (new ResultSet())
            ->setIndexUid($search->getIndexName())
            ->setHits($hits)
            ->setTotalResults((int) $this->connection->executeQuery($countSql, $params)->fetchOne())
            ->setFacetDistributions($this->cachedFacetCompute($this->facetCache, 'distributions', $query, $search, fn () => $this->facetDistributions($query, $search)))
            ->setFacetStats($this->cachedFacetCompute($this->facetCache, 'stats', $query, $search, fn () => $this->facetStats($query, $search)));
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

        if ($search->getResolvedAdapterParameter('ftsTable') === null) {
            $ids = $this->matchedIds($query, $search);
            if ($ids !== null) {
                $params['textMatchIds'] = json_encode($ids, JSON_THROW_ON_ERROR);

                return $where;
            }

            return [...$where, ...$this->fallbackWhere($query, $search, $params)];
        }

        $ftsQuery = Fts5MatchQuery::build($query->getQueryString());
        if ($ftsQuery !== '') {
            $params['ftsQuery'] = $ftsQuery;
            // Facet aggregations materialize the MATCH in a CTE (see ftsCtePrefix) so
            // the highly-selective FTS match drives the join; they bind :ftsQuery but
            // do not want the MATCH predicate inlined here.
            if ($ftsInWhere) {
                $where[] = sprintf('%s MATCH :ftsQuery', $this->connection->quoteSingleIdentifier($search->getResolvedAdapterParameter('ftsTable')));
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
        if ($this->usesFts($query, $search)) {
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
        // A table too large to facet live (liveFacets=false) still gets a distribution per facet,
        // holding the active filter's values and no counts: every facet the template asked for is
        // present — an absent one throws "Facet distribution ... is not found" — and a checked box
        // still renders as checked. Only the numbers are missing, and they are missing rather than
        // wrong, since the precomputed per-core totals do not describe this query's matches.
        // Matched ids are exempt: they are capped by whoever produced them, so the aggregation is
        // over a bounded set however large the table.
        $countsOnlyFromFilters = !$search->getResolvedAdapterParameter('liveFacets') && $this->usesFts($query, $search)
            && $this->matchedIds($query, $search) === null;
        $distributions = [];
        foreach ($search->getFacets() as $facet) {
            $filter = $query->getActiveFilter($facet->getProperty());
            $checkedValues = $filter instanceof TermFilter ? $filter->getValues() : [];
            if ($countsOnlyFromFilters) {
                $distributions[$facet->getProperty()] = (new FacetTermDistribution())
                    ->setProperty($facet->getProperty())
                    ->setValues([])
                    ->setCheckedValues($checkedValues);

                continue;
            }
            $column = $this->columnFor($search, 'facetColumns', $facet->getProperty());

            $params = $this->baseParams($search);
            $where = $this->baseWhere($query, $search, $params, ftsInWhere: false);
            $this->applyFilters($query, $search, $where, $params, $facet->getProperty());
            $params['maxFacetValues'] = $search->getResolvedAdapterParameter('maxFacetValues');

            $countTable = $search->getResolvedAdapterParameter('facetCountTable');
            $valueTable = $search->getResolvedAdapterParameter('facetValueTable');
            $coreScope = $this->usesFts($query, $search) ? null : $this->soleCoreScope($query, $facet->getProperty());
            if (is_string($countTable) && $coreScope !== null) {
                // Precomputed fast path: the only constraint is the structural core scope, so read the
                // per-core aggregate directly (core='' when no core is selected) — no JOIN, no EXISTS.
                $sql = sprintf(
                    'SELECT value, total FROM %s WHERE core = :facetCore AND field = :facetField ORDER BY total DESC LIMIT :maxFacetValues',
                    $this->connection->quoteSingleIdentifier($countTable),
                );
                $params['facetField'] = $facet->getProperty();
                $params['facetCore'] = $coreScope;
            } elseif (is_string($valueTable)) {
                $usesFts = $this->usesFts($query, $search);
                $params['facetField'] = $facet->getProperty();
                $where[] = 'fv.field = :facetField';
                $sql = sprintf(
                    '%sSELECT fv.value AS value, COUNT(*) AS total FROM %s fv JOIN %s ON d.rowid = fv.item_rowid%s%s GROUP BY fv.value ORDER BY total DESC LIMIT :maxFacetValues',
                    $this->ftsCtePrefix($query, $search, $usesFts),
                    $this->connection->quoteSingleIdentifier($valueTable),
                    $this->connection->quoteSingleIdentifier($search->getResolvedAdapterParameter('table')) . ' d',
                    $this->joinClause($query, $search, $usesFts, '__fts'),
                    ' WHERE ' . implode(' AND ', $where),
                );
            } else {
                $usesFts = $this->usesFts($query, $search);
                $sql = sprintf(
                    '%sSELECT %s AS value, COUNT(*) AS total FROM %s%s%s GROUP BY %s ORDER BY total DESC LIMIT :maxFacetValues',
                    $this->ftsCtePrefix($query, $search, $usesFts),
                    $column,
                    $this->connection->quoteSingleIdentifier($search->getResolvedAdapterParameter('table')) . ' d',
                    $this->joinClause($query, $search, $usesFts, '__fts'),
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
        // Same gate as facetDistributions(): each stat is a MIN/MAX over the matching rows, which is
        // the aggregation being avoided. Callers already handle a facet with no stat — the loop
        // below skips any facet whose column has no numeric range.
        if (!$search->getResolvedAdapterParameter('liveFacets') && $this->usesFts($query, $search)
            && $this->matchedIds($query, $search) === null) {
            return [];
        }

        $stats = [];
        foreach ($search->getFacets() as $facet) {
            $component = $facet->getDisplayComponent();
            if ($component === null
                || !is_subclass_of($component, \Survos\SearchBundle\Twig\Components\Facet\AbstractFacet::class)
                || !$component::usesFacetStats()) {
                continue;
            }

            $filter = $query->getActiveFilter($facet->getProperty());
            $column = $this->columnFor($search, 'facetColumns', $facet->getProperty());
            $params = $this->baseParams($search);
            $where = $this->baseWhere($query, $search, $params, ftsInWhere: false);
            $this->applyFilters($query, $search, $where, $params, $facet->getProperty());

            $usesFts = $this->usesFts($query, $search);
            // Two scalar subqueries, not MIN(x), MAX(x) in one SELECT: SQLite answers a lone MIN or
            // MAX from an index in one probe, but the pair scans every matching row. On a 1.08M-row
            // folio with (core_id, sort_key) indexed that is ~0 ms against 1.6 s.
            $from = sprintf(
                ' FROM %s%s%s',
                $this->connection->quoteSingleIdentifier($search->getResolvedAdapterParameter('table')) . ' d',
                $this->joinClause($query, $search, $usesFts, '__fts'),
                $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
            );
            $sql = sprintf(
                '%sSELECT (SELECT MIN(%s)%s) AS min_value, (SELECT MAX(%s)%s) AS max_value',
                $this->ftsCtePrefix($query, $search, $usesFts),
                $column,
                $from,
                $column,
                $from,
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


    /**
     * Whether this query runs through the FTS table. A search configured without one (ftsTable
     * null: a folio whose index was skipped or never finished building) never does; its text
     * query is answered by {@see fallbackWhere()} instead.
     */
    private function usesFts(Query $query, SearchInterface $search): bool
    {
        if ($search->getResolvedAdapterParameter('ftsTable') === null) {
            return $this->matchedIds($query, $search) !== null;
        }

        return Fts5MatchQuery::build($query->getQueryString()) !== '';
    }

    /**
     * The textMatcher's answer for this query — ids best first — or null when there is no text
     * query, no matcher, or the matcher could not answer. Asked once per search: every facet
     * re-derives its WHERE, and a round trip to another engine per facet would be the whole cost.
     *
     * The ids play the part of an FTS match: they become the `__fts` CTE (rowid, rank), so the
     * FTS-first join order, the facet aggregations and relevance order all apply unchanged.
     *
     * @return list<string|int>|null
     */
    private function matchedIds(Query $query, SearchInterface $search): ?array
    {
        $matcher = $search->getResolvedAdapterParameter('textMatcher');
        $text = trim((string) $query->getQueryString());
        if ($matcher === null || $text === '' || $search->getResolvedAdapterParameter('ftsTable') !== null) {
            return null;
        }
        $key = spl_object_id($search) . "\0" . $text;
        if (!$this->textMatches->offsetExists($key)) {
            $ids = $matcher($text);
            $this->textMatches[$key] = is_array($ids) ? array_values($ids) : null;
        }

        return $this->textMatches[$key];
    }

    /**
     * A text query against a search with no FTS table: every term must appear (LIKE, case-folded
     * for ASCII as SQLite does) in one of the configured textFallbackColumns. With none configured
     * it matches nothing — reporting no results is honest, returning every row unfiltered is not.
     *
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function fallbackWhere(Query $query, SearchInterface $search, array &$params): array
    {
        $terms = array_slice(preg_split('/\s+/u', trim((string) $query->getQueryString()), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 8);
        if ($terms === []) {
            return [];
        }
        $columns = $search->getResolvedAdapterParameter('textFallbackColumns');
        if ($columns === []) {
            return ['0 = 1'];
        }
        $where = [];
        foreach ($terms as $i => $term) {
            $params['textTerm' . $i] = '%' . addcslashes(trim($term, '"'), '%_\\') . '%';
            $where[] = '(' . implode(' OR ', array_map(
                static fn (string $column): string => sprintf("%s LIKE :textTerm%d ESCAPE '\\'", $column, $i),
                $columns,
            )) . ')';
        }

        return $where;
    }

    /**
     * FROM for the hit and count queries: the FTS table first, then the rows it matched.
     *
     * `CROSS JOIN` is how SQLite is told the join order; it never reorders one. Without it, a
     * WHERE on the row table (a core scope, a filter) lets the planner drive from that index and
     * probe the FTS table once per row: on a 966k-row newspaper folio a count for "snap bean" took
     * 45 s this way and 0.00 s FTS-first, and inkstory.org's search 502'd (2026-09-25). A MATCH is
     * always the most selective thing in the query, so it should always be the outer loop — the
     * same reasoning the facet queries already follow with {@see ftsCtePrefix()}.
     */
    private function fromClause(Query $query, SearchInterface $search, bool $usesFts): string
    {
        $table = $this->connection->quoteSingleIdentifier($search->getResolvedAdapterParameter('table')) . ' d';
        if (!$usesFts) {
            return $table;
        }
        if ($this->matchedIds($query, $search) !== null) {
            return sprintf('__fts f CROSS JOIN %s ON f.rowid = d.rowid', $table);
        }

        return sprintf(
            '%s f CROSS JOIN %s ON %s',
            $this->connection->quoteSingleIdentifier($search->getResolvedAdapterParameter('ftsTable')),
            $table,
            $search->getResolvedAdapterParameter('joinExpression'),
        );
    }

    private function joinClause(Query $query, SearchInterface $search, bool $usesFts, ?string $ftsSource = null): string
    {
        if (!$usesFts) {
            return '';
        }
        if ($this->matchedIds($query, $search) !== null) {
            return ' JOIN __fts f ON f.rowid = d.rowid';
        }

        // The CTE in ftsCtePrefix() is aliased back to `f`, so the configured
        // joinExpression (default `f.rowid = d.rowid`) works against either source.
        $source = $ftsSource ?? $this->connection->quoteSingleIdentifier($search->getResolvedAdapterParameter('ftsTable'));

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
    private function ftsCtePrefix(Query $query, SearchInterface $search, bool $usesFts): string
    {
        if (!$usesFts) {
            return '';
        }
        if ($this->matchedIds($query, $search) !== null) {
            // json_each's key is the array position, so rank ascending is the matcher's order.
            return sprintf(
                'WITH __fts AS MATERIALIZED (SELECT m0.rowid AS rowid, j.key AS rank FROM json_each(:textMatchIds) j CROSS JOIN %s m0 ON m0.%s = j.value) ',
                $this->connection->quoteSingleIdentifier($search->getResolvedAdapterParameter('table')),
                $this->connection->quoteSingleIdentifier($search->getResolvedAdapterParameter('idColumn')),
            );
        }

        $fts = $this->connection->quoteSingleIdentifier($search->getResolvedAdapterParameter('ftsTable'));

        return sprintf('WITH __fts AS MATERIALIZED (SELECT rowid FROM %1$s WHERE %1$s MATCH :ftsQuery) ', $fts);
    }

    /**
     * The precomputed facet-count table is partitioned by core (core='' = all cores). It can serve a
     * facet only when no other constraint narrows the set: the active filters must reduce to the
     * structural core scope alone — either none (→ '') or a single-value `core` term filter (→ that
     * core). Returns the core to read, or null to fall back to the live aggregation.
     *
     * The `core` filter mirrors the search's core_id where-scope, which constrains every facet query
     * (it is not dropped when computing the core facet itself), so it always sets the partition even
     * when $skipProperty === 'core'. Any other active filter would make the precomputed totals wrong.
     */
    private function soleCoreScope(Query $query, ?string $skipProperty): ?string
    {
        $core = '';
        foreach ($query->getActiveFilters() as $filter) {
            if ($filter->getProperty() === 'core'
                && $filter instanceof TermFilter
                && count($filter->getValues()) === 1) {
                $core = (string) array_values($filter->getValues())[0];
                continue;
            }

            if ($filter->getProperty() === $skipProperty) {
                continue;
            }

            if ($filter instanceof TermFilter && $filter->hasValues()) {
                return null;
            }

            if ($filter instanceof RangeFilter && ($filter->getMin() !== null || $filter->getMax() !== null)) {
                return null;
            }

            if (!$filter instanceof TermFilter && !$filter instanceof RangeFilter) {
                return null;
            }
        }

        return $core;
    }
}
