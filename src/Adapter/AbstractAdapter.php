<?php

/*
 * This file is part of the UxSearch project.
 *
 * (c) Mezcalito (https://www.mezcalito.fr)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter;

use Survos\SearchBundle\Search\Facet;
use Survos\SearchBundle\Search\Filter\FilterInterface;
use Survos\SearchBundle\Search\Filter\RangeFilter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\ResultSet\FacetStat;
use Survos\SearchBundle\Search\ResultSet\FacetTermDistribution;
use Survos\SearchBundle\Search\SearchInterface;

abstract class AbstractAdapter implements AdapterInterface
{
    abstract public function getFacetDistributionKey(): string;

    abstract public function getFacetStatsKey(): string;

    /**
     * @param array<string, mixed> $results
     *
     * @return array{0: array<string, FacetTermDistribution>, 1: array<int, FacetStat>}
     */
    protected function getFacets(array $results, SearchInterface $search, Query $query): array
    {
        $facetDistributionKey = $this->getFacetDistributionKey();
        $facetStatsKey = $this->getFacetStatsKey();

        $mergedFacetDistribution = array_reduce($results['results'], static function ($carry, $result) use ($facetDistributionKey) {
            if (isset($result[$facetDistributionKey])) {
                foreach ($result[$facetDistributionKey] as $facetKey => $facetValues) {
                    $carry[$facetKey] = $facetValues;
                }
            }

            return $carry;
        }, []);

        $mergedFacetStats = array_reduce($results['results'], static function ($carry, $result) use ($facetStatsKey) {
            if (isset($result[$facetStatsKey])) {
                foreach ($result[$facetStatsKey] as $facetKey => $facetStat) {
                    $carry[$facetKey] = $facetStat;
                }
            }

            return $carry;
        }, []);

        $facetsDistributions = [];

        foreach ($search->getFacets() as $facet) {
            $filter = $query->getActiveFilter($facet->getProperty());
            $facetsDistributions[$facet->getProperty()] = $this->hydrateTermDistribution($mergedFacetDistribution, $facet, $filter);

            if (!isset($mergedFacetStats[$facet->getProperty()])) {
                $mergedFacetStats[$facet->getProperty()] = ['min' => 0, 'max' => 0];
            }
        }

        foreach ($facetsDistributions as $property => $distribution) {
            $values = $distribution->getValues();
            $checkedValues = $distribution->getCheckedValues();

            $checkedFacets = [];
            $uncheckedFacets = [];

            foreach ($values as $key => $value) {
                if (\in_array($key, $checkedValues)) {
                    $checkedFacets[$key] = $value;
                } else {
                    $uncheckedFacets[$key] = $value;
                }
            }

            $sortedFacets = $checkedFacets + $uncheckedFacets;

            $distribution->setValues($sortedFacets);
        }

        $facetStats = [];
        foreach ($mergedFacetStats as $property => $values) {
            $userMin = null;
            $userMax = null;

            $filter = $query->getActiveFilter($property);
            if ($filter instanceof RangeFilter) {
                $userMin = $filter->getMin();
                $userMax = $filter->getMax();
            }

            $facetStats[] = new FacetStat($property, $values['min'], $values['max'], $userMin, $userMax);
        }

        return [$facetsDistributions, $facetStats];
    }

    /**
     * @param array<string, array<mixed, int>> $mergedFacetDistribution
     */
    protected function hydrateTermDistribution(array $mergedFacetDistribution, Facet $facet, ?FilterInterface $filter): FacetTermDistribution
    {
        $values = $mergedFacetDistribution[$facet->getProperty()] ?? [];

        $termDistribution = (new FacetTermDistribution())
            ->setProperty($facet->getProperty())
            ->setValues($values)
        ;

        if ($filter instanceof TermFilter) {
            $termDistribution->setCheckedValues($filter->getValues());
        }

        return $termDistribution;
    }
}
