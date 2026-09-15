<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Http;

use Survos\SearchBundle\Search\SearchProvider;
use Survos\SearchBundle\Search\Searcher;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Survos\SearchBundle\Search\Filter\RangeFilter;

/** The deliberately small InstantSearch contract supported by the browser widgets. */
final readonly class InstantSearchGateway
{
    /** @param list<string> $allowedSearches Explicitly public search names; empty disables HTTP search. */
    public function __construct(private SearchProvider $provider, private Searcher $searcher, private array $allowedSearches = []) {}

    public function search(array $requests): array
    {
        if (count($requests) > 10) { throw new \InvalidArgumentException('At most ten searches per request.'); }
        $results = [];
        foreach ($requests as $request) {
            if (!is_array($request) || !is_string($request['indexName'] ?? null)) {
                throw new \InvalidArgumentException('A named search is required.');
            }
            [$name, $sort] = array_pad(explode('::', $request['indexName'], 2), 2, null);
            if (!in_array($name, $this->allowedSearches, true)) {
                throw new \InvalidArgumentException('Search is not available through this endpoint.');
            }
            $search = $this->provider->getSearch($name)->create();
            $params = $request['params'] ?? [];
            if (!is_array($params)) { throw new \InvalidArgumentException('Invalid search parameters.'); }
            $text = $params['query'] ?? '';
            if (!is_string($text) || mb_strlen($text) > 300) { throw new \InvalidArgumentException('Query must be at most 300 characters.'); }
            $size = $params['hitsPerPage'] ?? 24;
            $page = $params['page'] ?? 0;
            if (!is_int($size) || $size < 0 || $size > 100 || !is_int($page) || $page < 0 || ($size === 0 && $page !== 0) || ($page + 1) * $size > 10000) {
                throw new \InvalidArgumentException('Invalid page or page size.');
            }
            $query = (new Query())->setQueryString($text)->setCurrentPage($page + 1)->setActiveHitsPerPage(max(1, $size));
            $query->hydrateEntities = false;
            if ($sort !== null) {
                $sorts = array_map(static fn ($item) => $item->getKey(), $search->getAvailableSorts());
                if (!in_array($sort, $sorts, true)) { throw new \InvalidArgumentException('Unsupported sort.'); }
                $query->setActiveSort($sort);
            }
            $facets = array_map(static fn ($facet) => $facet->getProperty(), $search->getFacets());
            $filters = $params['facetFilters'] ?? [];
            if (!is_array($filters) || count($filters) > 50) { throw new \InvalidArgumentException('Invalid facet filters.'); }
            foreach ($filters as $group) {
                $values = []; $property = null;
                foreach (is_array($group) ? $group : [$group] as $term) {
                    if (!is_string($term) || !str_contains($term, ':')) { throw new \InvalidArgumentException('Invalid facet value.'); }
                    [$field, $value] = explode(':', $term, 2);
                    if (!in_array($field, $facets, true) || ($property !== null && $property !== $field) || str_starts_with($value, '-')) {
                        throw new \InvalidArgumentException('Unsupported facet filter.');
                    }
                    $property = $field; $values[] = $value;
                }
                if ($property !== null) {
                    if ($query->hasActiveFilter($property)) { throw new \InvalidArgumentException('Use one OR group per facet.'); }
                    $query->addActiveFilter(new TermFilter($property, $values));
                }
            }
            $ranges = [];
            $numericFilters = $params['numericFilters'] ?? [];
            if (!is_array($numericFilters) || count($numericFilters) > 50) { throw new \InvalidArgumentException('Invalid numeric filters.'); }
            foreach ($numericFilters as $filter) {
                if (!is_string($filter) || !preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(>=|<=|=)(-?\d+(?:\.\d+)?)$/D', $filter, $m) || !in_array($m[1], $facets, true)) {
                    throw new \InvalidArgumentException('Unsupported numeric filter.');
                }
                if ($m[2] !== '<=') { $ranges[$m[1]]['min'] = (float) $m[3]; }
                if ($m[2] !== '>=') { $ranges[$m[1]]['max'] = (float) $m[3]; }
            }
            foreach ($ranges as $field => $range) {
                if (isset($range['min'], $range['max']) && $range['min'] > $range['max']) { throw new \InvalidArgumentException('Invalid numeric range.'); }
                if ($query->hasActiveFilter($field)) { throw new \InvalidArgumentException('Cannot combine term and range filters on one field.'); }
                $query->addActiveFilter(new RangeFilter($field, $range['min'] ?? null, $range['max'] ?? null));
            }
            $started = microtime(true);
            $result = $this->searcher->search($query, $search);
            $hits = [];
            foreach ($size === 0 ? [] : $result->getHits() as $hit) {
                $data = $hit->getData();
                if (!is_array($data)) { throw new \LogicException('InstantSearch requires an adapter that returns document arrays.'); }
                $data['objectID'] = (string) ($data[$search->getResolvedAdapterParameter('idField') ?? 'id'] ?? $data['_id'] ?? '');
                foreach ($hit->getMetadata()['highlight'] ?? [] as $field => $fragments) {
                    $data['_highlightResult'][$field] = ['value' => implode(' … ', $fragments), 'matchLevel' => 'full', 'matchedWords' => []];
                }
                $hits[] = $data;
            }
            $distribution = [];
            foreach ($result->getFacetDistributions() as $facet) { $distribution[$facet->getProperty()] = (object) $facet->getValues(); }
            $stats = [];
            foreach ($result->getFacetStats() as $stat) { $stats[$stat->getProperty()] = ['min' => $stat->getMin(), 'max' => $stat->getMax()]; }
            $total = $result->getTotalResults();
            $results[] = [
                'hits' => $hits, 'nbHits' => $total, 'page' => $page, 'hitsPerPage' => $size,
                'nbPages' => $size === 0 ? 0 : min((int) ceil($total / $size), intdiv(10000, $size)), 'query' => $text,
                'index' => $request['indexName'], 'params' => '',
                'processingTimeMS' => (int) round((microtime(true) - $started) * 1000),
                'facets' => (object) $distribution, 'facets_stats' => (object) $stats,
                'exhaustiveNbHits' => true, 'exhaustiveFacetsCount' => false,
            ];
        }
        return ['results' => $results];
    }
}
