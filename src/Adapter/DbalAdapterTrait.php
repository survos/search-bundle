<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter;

use Doctrine\DBAL\Connection;
use Mezcalito\UxSearchBundle\Search\Filter\FilterInterface;
use Mezcalito\UxSearchBundle\Search\Filter\RangeFilter;
use Mezcalito\UxSearchBundle\Search\Filter\TermFilter;
use Mezcalito\UxSearchBundle\Search\Query;
use Mezcalito\UxSearchBundle\Search\SearchInterface;

trait DbalAdapterTrait
{
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
                    $this->connection->quoteIdentifier($facetValueTable),
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
                : 'd.' . $connection->quoteIdentifier($column),
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
