<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\Elasticsearch;

use Survos\SearchBundle\Search\Filter\RangeFilter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\SearchInterface;
use Survos\SearchBundle\Contract\EmbeddingProviderInterface;

final readonly class ElasticsearchQueryBuilder
{
    /** @return array<string, mixed> */
    public function build(Query $query, SearchInterface $search, ?string $forcedMode = null): array
    {
        $mode = $forcedMode ?? $search->getResolvedAdapterParameter('retrievalMode');
        $queryString = trim($query->getQueryString());
        $filters = $this->filters($query, $search);
        $lexical = $this->lexicalQuery($queryString, $search, $filters);

        $body = [
            'size' => max(1, $query->getActiveHitsPerPage()),
            'from' => max(0, $query->getCurrentPage() - 1) * max(1, $query->getActiveHitsPerPage()),
            'track_total_hits' => true,
        ];

        $sourceFields = $search->getResolvedAdapterParameter('sourceFields');
        if ($sourceFields !== []) {
            $body['_source'] = $sourceFields;
        }

        if ($search->getResolvedAdapterParameter('highlight')) {
            $body['highlight'] = ['fields' => array_fill_keys($search->getResolvedAdapterParameter('searchFields'), new \stdClass())];
        }

        if ($search->getResolvedAdapterParameter('explain')) {
            $body['explain'] = true;
        }

        if ($mode === 'lexical' || $queryString === '') {
            $body['query'] = $lexical;
            $sort = $this->sort($query, $search);
            if ($sort !== []) {
                $body['sort'] = $sort;
            }
        } elseif ($mode === 'vector') {
            $vector = $this->queryVector($queryString, $search);
            $body['retriever'] = ['knn' => $this->knn($vector, $search, $filters)];
        } else {
            $vector = $this->queryVector($queryString, $search);
            $body['retriever'] = [
                'rrf' => [
                    'retrievers' => [
                        ['standard' => ['query' => $lexical]],
                        ['knn' => $this->knn($vector, $search, $filters)],
                    ],
                    'rank_constant' => $search->getResolvedAdapterParameter('rankConstant'),
                    'rank_window_size' => $search->getResolvedAdapterParameter('rankWindowSize'),
                ],
            ];
        }

        $aggs = [];
        foreach ($search->getFacets() as $facet) {
            $property = $facet->getProperty();
            $field = $search->getResolvedAdapterParameter('facetFields')[$property] ?? $property;
            $aggs[$property] = ['terms' => ['field' => $field, 'size' => $search->getResolvedAdapterParameter('maxFacetValues')]];
            $component = $facet->getDisplayComponent();
            if (is_string($component)
                && is_subclass_of($component, \Survos\SearchBundle\Twig\Components\Facet\AbstractFacet::class)
                && $component::usesFacetStats()) {
                $aggs[$property . '__stats'] = ['stats' => ['field' => $field]];
            }
        }
        if ($aggs !== []) {
            $body['aggs'] = $aggs;
        }

        return $body;
    }

    /** @param list<array<string, mixed>> $filters
     *  @return array<string, mixed>
     */
    private function lexicalQuery(string $query, SearchInterface $search, array $filters): array
    {
        $must = $query === ''
            ? [['match_all' => new \stdClass()]]
            : [['multi_match' => [
                'query' => $query,
                'fields' => $search->getResolvedAdapterParameter('searchFields'),
                'type' => 'best_fields',
            ]]];

        return ['bool' => ['must' => $must, 'filter' => $filters]];
    }

    /** @return list<float> */
    private function queryVector(string $query, SearchInterface $search): array
    {
        if ($query === '' || $search->getResolvedAdapterParameter('retrievalMode') === 'lexical') {
            return [];
        }

        $vector = $search->getResolvedAdapterParameter('queryVector');
        if (is_callable($vector)) {
            $vector = $vector($query);
        }
        if ($vector === null) {
            $provider = $search->getResolvedAdapterParameter('embeddingProvider');
            $vector = $provider instanceof EmbeddingProviderInterface
                ? $provider->embed($query)
                : (is_callable($provider) ? $provider($query) : null);
        }
        if (!is_array($vector) || $vector === []) {
            throw new \LogicException('Vector and hybrid retrieval require a queryVector array or callable.');
        }

        return array_map(static fn (mixed $value): float => (float) $value, array_values($vector));
    }

    /** @param list<float> $vector
     *  @param list<array<string, mixed>> $filters
     *  @return array<string, mixed>
     */
    private function knn(array $vector, SearchInterface $search, array $filters): array
    {
        $knn = [
            'field' => $search->getResolvedAdapterParameter('vectorField'),
            'query_vector' => $vector,
            'k' => $search->getResolvedAdapterParameter('k'),
            'num_candidates' => $search->getResolvedAdapterParameter('numCandidates'),
        ];
        if ($filters !== []) {
            $knn['filter'] = ['bool' => ['filter' => $filters]];
        }

        return $knn;
    }

    /** @return list<array<string, mixed>> */
    private function filters(Query $query, SearchInterface $search): array
    {
        $filters = [];
        $facetFields = $search->getResolvedAdapterParameter('facetFields');
        foreach ($query->getActiveFilters() as $filter) {
            $field = $facetFields[$filter->getProperty()] ?? $filter->getProperty();
            if ($filter instanceof TermFilter && $filter->getValues() !== []) {
                $filters[] = ['terms' => [$field => array_values($filter->getValues())]];
            }
            if ($filter instanceof RangeFilter) {
                $range = array_filter(
                    ['gte' => $filter->getMin(), 'lte' => $filter->getMax()],
                    static fn (mixed $value): bool => $value !== null,
                );
                if ($range !== []) {
                    $filters[] = ['range' => [$field => $range]];
                }
            }
        }

        return $filters;
    }

    /** @return list<array<string, array{order: string}>> */
    private function sort(Query $query, SearchInterface $search): array
    {
        $activeSort = $query->getActiveSort();
        if (!is_string($activeSort) || !str_contains($activeSort, ':')) {
            return [];
        }
        [$property, $direction] = explode(':', $activeSort, 2);
        $field = $search->getResolvedAdapterParameter('sortFields')[$property] ?? null;
        $direction = strtolower($direction);
        if (!is_string($field) || !in_array($direction, ['asc', 'desc'], true)) {
            return [];
        }

        return [[$field => ['order' => $direction]]];
    }
}
