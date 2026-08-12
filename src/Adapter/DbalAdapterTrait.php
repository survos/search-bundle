<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter;

use Doctrine\DBAL\Connection;
use Mezcalito\UxSearchBundle\Search\Filter\FilterInterface;
use Mezcalito\UxSearchBundle\Search\Filter\RangeFilter;
use Mezcalito\UxSearchBundle\Search\Filter\TermFilter;
use Mezcalito\UxSearchBundle\Search\Query;
use Mezcalito\UxSearchBundle\Search\SearchInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

trait DbalAdapterTrait
{
    /**
     * Facets are aggregated over the full filtered result set and depend only on the
     * query string + active filters — never on page, sort, or hits-per-page. A flood of
     * requests that only vary `page` (deep-pagination scraping is what took museado.org
     * down on 2026-07-21) would otherwise recompute the same expensive GROUP BY/MIN/MAX
     * aggregate on every single request. Cache by filter fingerprint so those requests
     * share one result instead of each re-running the query.
     *
     * @template T of array
     * @param callable(): T $compute
     * @return T
     */
    private function cachedFacetCompute(?CacheInterface $cache, string $kind, Query $query, SearchInterface $search, callable $compute): array
    {
        if ($cache === null) {
            return $compute();
        }

        $filters = [];
        foreach ($query->getActiveFilters() as $property => $filter) {
            $filters[$property] = match (true) {
                $filter instanceof TermFilter => ['term', array_values($filter->getValues())],
                $filter instanceof RangeFilter => ['range', $filter->getMin(), $filter->getMax()],
                default => ['other'],
            };
        }
        ksort($filters);

        // getResolvedAdapterParameters() is the one thing that fully determines what SQL
        // actually runs (table, ftsTable, where, bound params, facetColumns), so it also
        // captures which facets are configured. Hashing it (rather than getIndexName(),
        // which is a class-level literal shared by every folio search regardless of
        // dataset) means two different datasets/cores can never collide in the cache.
        $dataSource = serialize($search->getResolvedAdapterParameters());

        $key = sprintf(
            'survos_search_facet.%s.%s.%s.%s',
            $kind,
            substr(md5($dataSource), 0, 16),
            md5($query->getQueryString()),
            md5(serialize($filters)),
        );

        return $cache->get($key, function (ItemInterface $item) use ($compute) {
            $item->expiresAfter(60);

            return $compute();
        });
    }

    /**
     * @param array<string, mixed> $params
     * @param string[]             $where
     */
    private function applyFilters(Query $query, SearchInterface $search, array &$where, array &$params, ?string $skipProperty = null): void
    {
        foreach ($query->getActiveFilters() as $filter) {
            if ($filter->getProperty() === $skipProperty) {
                continue;
            }

            $this->applyFilter($filter, $search, $where, $params);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param string[]             $where
     */
    private function applyFilter(FilterInterface $filter, SearchInterface $search, array &$where, array &$params): void
    {
        $column = $this->columnFor($search, 'facetColumns', $filter->getProperty());

        if ($filter instanceof TermFilter && $filter->hasValues()) {
            $names = [];
            foreach (array_values($filter->getValues()) as $i => $value) {
                $name = $this->parameterName($filter->getProperty() . '_term_' . $i);
                $names[] = ':' . $name;
                $params[$name] = $value;
            }

            $facetValueTable = $this->optionalStringParameter($search, 'facetValueTable');
            if ($facetValueTable !== null) {
                $fieldParam = $this->parameterName($filter->getProperty() . '_facet_field');
                $params[$fieldParam] = $filter->getProperty();
                $alias = 'fv_' . $this->parameterName($filter->getProperty());
                $where[] = sprintf(
                    'EXISTS (SELECT 1 FROM %s %s WHERE %s.item_rowid = d.rowid AND %s.field = :%s AND %s.value IN (%s))',
                    $this->connection->quoteSingleIdentifier($facetValueTable),
                    $alias,
                    $alias,
                    $alias,
                    $fieldParam,
                    $alias,
                    implode(', ', $names),
                );
                return;
            }

            $where[] = sprintf('%s IN (%s)', $column, implode(', ', $names));
        }

        // Range bounds compare NUMERICALLY. The facet column is often a json_extract() whose value
        // has integer/real storage class, while the bound min/max arrive as strings (slider/URL).
        // SQLite orders integer < text across storage classes, so `year >= '1965'` is always false
        // → zero results. CAST both sides to REAL so the comparison is numeric (also correct on Postgres).
        if ($filter instanceof RangeFilter && null !== $filter->getMin()) {
            $name = $this->parameterName($filter->getProperty() . '_min');
            $where[] = sprintf('CAST(%s AS REAL) >= CAST(:%s AS REAL)', $column, $name);
            $params[$name] = $filter->getMin();
        }

        if ($filter instanceof RangeFilter && null !== $filter->getMax()) {
            $name = $this->parameterName($filter->getProperty() . '_max');
            $where[] = sprintf('CAST(%s AS REAL) <= CAST(:%s AS REAL)', $column, $name);
            $params[$name] = $filter->getMax();
        }
    }

    private function columnFor(SearchInterface $search, string $parameter, string $property): string
    {
        $columns = $search->getResolvedAdapterParameter($parameter);
        if (is_array($columns) && isset($columns[$property]) && is_string($columns[$property])) {
            return $columns[$property];
        }

        return 'd.' . $property;
    }

    private function selectList(Connection $connection, array $columns): string
    {
        if ($columns === []) {
            return 'd.*';
        }

        return implode(', ', array_map(
            static fn (string $column): string => str_contains($column, '(') || str_contains($column, ' ') || str_contains($column, '.') || str_contains($column, '*')
                ? $column
                : 'd.' . $connection->quoteSingleIdentifier($column),
            $columns,
        ));
    }

    private function parameterName(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? 'param';
    }

    private function optionalStringParameter(SearchInterface $search, string $parameter): ?string
    {
        try {
            $value = $search->getResolvedAdapterParameter($parameter);
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
