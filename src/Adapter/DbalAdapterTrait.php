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
            $where[] = sprintf('%s IN (%s)', $column, implode(', ', $names));
        }

        if ($filter instanceof RangeFilter && null !== $filter->getMin()) {
            $name = $this->parameterName($filter->getProperty() . '_min');
            $where[] = sprintf('%s >= :%s', $column, $name);
            $params[$name] = $filter->getMin();
        }

        if ($filter instanceof RangeFilter && null !== $filter->getMax()) {
            $name = $this->parameterName($filter->getProperty() . '_max');
            $where[] = sprintf('%s <= :%s', $column, $name);
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
}
