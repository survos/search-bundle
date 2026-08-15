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

namespace Survos\SearchBundle\Twig\Components;

use Survos\SearchBundle\Context\ContextProvider;
use Survos\SearchBundle\Search\Filter\RangeFilter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\Searcher;
use Survos\SearchBundle\Search\SearchInterface;
use Survos\SearchBundle\Search\SearchProvider;
use Survos\SearchBundle\Search\Url\CurrentRequest;
use Survos\SearchBundle\Search\Url\UrlFormaterInterface;
use Survos\SearchBundle\Search\Url\UrlFormaterProvider;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PreMount;

class Layout
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp(
        writable: ['queryString', 'activeSort', 'activeHitsPerPage'],
        useSerializerForHydration: true,
        onUpdated: ['queryString' => 'resetCurrentPage', 'activeSort' => 'resetCurrentPage', 'activeHitsPerPage' => 'resetCurrentPage']
    )]
    public ?Query $query = null;

    #[LiveProp]
    public ?string $name = null;

    /**
     * @var array<string, mixed>
     */
    #[LiveProp]
    public array $options = [];

    /**
     * Initial query state applied once when the component mounts.
     *
     * Supported keys: query/queryString, sort/activeSort, hitsPerPage/activeHitsPerPage, filters.
     *
     * @var array<string, mixed>
     */
    #[LiveProp]
    public array $initialQuery = [];

    /**
     * Query filters applied to every search without becoming public/removable refinements.
     *
     * @var array<string, mixed>
     */
    #[LiveProp]
    public array $fixedFilters = [];

    public ?SearchInterface $search = null;

    #[LiveProp(useSerializerForHydration: true)]
    public ?CurrentRequest $currentRequest = null;

    public function __construct(
        private readonly SearchProvider $searchConfigurationProvider,
        private readonly Searcher $searcher,
        private readonly ContextProvider $contextProvider,
        private readonly RequestStack $requestStack,
        private readonly UrlFormaterProvider $urlFormaterProvider,
        /** @var Serializer */
        private readonly SerializerInterface $serializer,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    #[PreMount]
    public function onInitialMount(array $data): void
    {
        $this->options = $data['options'] ?? [];
        $this->initialQuery = $data['initialQuery'] ?? [];
        $this->fixedFilters = $data['fixedFilters'] ?? [];
        $this->search = $this->getSearch($data['name'])->create($this->options);
        $this->query = $this->getSearch($data['name'])->createQuery();
        $this->applyInitialQuery($this->query, $this->initialQuery);

        if ($this->search->hasUrlRewriting()) {
            $mainRequest = $this->requestStack->getMainRequest();

            if ($mainRequest && $mainRequest->attributes->has('_route')) {
                $this->currentRequest = CurrentRequest::fromRequest($mainRequest);
                $this->getUrlFormater()->applyFilters($this->currentRequest, $this->search, $this->query);
            }
        }

        $this->performSearch();
    }

    #[PreReRender]
    public function onReRender(): void
    {
        $this->search = $this->getSearch($this->name)->create($this->options);
        $this->performSearch();

        $this->dispatchBrowserEvent('ux-search:query:update', $this->serializer->normalize($this->query));

        if ($this->search->hasUrlRewriting() && $this->currentRequest) {
            $this->dispatchBrowserEvent('ux-search:url:update', [
                'url' => $this->getUrlFormater()->generateUrl($this->currentRequest, $this->search, $this->query),
            ]);
        }
    }

    #[LiveAction]
    public function changeCurrentPage(#[LiveArg] int $page): void
    {
        $this->query->setCurrentPage($page);
    }

    public function resetCurrentPage(string|int|null $previousValue): void
    {
        $this->query->setCurrentPage(1);
    }

    #[LiveAction]
    public function switchFacetTerm(#[LiveArg] string $property, #[LiveArg] string $value): void
    {
        $filter = $this->query->getActiveFilter($property);

        if (!$filter instanceof TermFilter) {
            $filter = new TermFilter($property);
            $this->query->addActiveFilter($filter);
        }

        if ($filter->hasValue($value)) {
            $filter->setValues([]);
        } else {
            $filter->setValues([$value]);
        }

        if (!$filter->hasValues()) {
            $this->query->removeActiveFilter($filter);
        }

        $this->query->setCurrentPage(1);
    }

    #[LiveAction]
    public function toggleFacetTerm(#[LiveArg] string $property, #[LiveArg] string $value): void
    {
        $filter = $this->query->getActiveFilter($property);

        if (!$filter instanceof TermFilter) {
            $filter = new TermFilter($property);
            $this->query->addActiveFilter($filter);
        }

        $filter->toggleValue($value);

        if (!$filter->hasValues()) {
            $this->query->removeActiveFilter($filter);
        }

        $this->query->setCurrentPage(1);
    }

    #[LiveAction]
    public function updateFacetRange(#[LiveArg] string $property, #[LiveArg] float|int|null $min, #[LiveArg] float|int|null $max): void
    {
        $filter = $this->query->getActiveFilter($property);

        if (!$filter instanceof RangeFilter) {
            $filter = new RangeFilter($property);
            $this->query->addActiveFilter($filter);
        }

        $filter->setMin($min);
        $filter->setMax($max);

        if (!$filter->hasValues()) {
            $this->query->removeActiveFilter($filter);
        }

        $this->query->setCurrentPage(1);
    }

    #[LiveAction]
    public function clearRefinements(): void
    {
        $this->query->setActiveFilters([]);
    }

    private function performSearch(): void
    {
        $publicQuery = clone $this->query;
        $searchQuery = clone $this->query;
        $this->applyFilters($searchQuery, $this->fixedFilters);

        $this->searcher->search($searchQuery, $this->search);

        if ($this->fixedFilters !== [] && $this->contextProvider->hasCurrentContext()) {
            $this->contextProvider->getCurrentContext()->setQuery($publicQuery);
        }
    }

    /**
     * @param array<string, mixed> $initialQuery
     */
    private function applyInitialQuery(Query $query, array $initialQuery): void
    {
        $queryString = $initialQuery['queryString'] ?? $initialQuery['query'] ?? null;
        if (is_scalar($queryString)) {
            $query->setQueryString((string) $queryString);
        }

        $sort = $initialQuery['activeSort'] ?? $initialQuery['sort'] ?? null;
        if (is_scalar($sort)) {
            $query->setActiveSort((string) $sort);
        }

        $hitsPerPage = $initialQuery['activeHitsPerPage'] ?? $initialQuery['hitsPerPage'] ?? null;
        if (is_numeric($hitsPerPage)) {
            $query->setActiveHitsPerPage((int) $hitsPerPage);
        }

        $filters = $initialQuery['filters'] ?? null;
        if (is_array($filters)) {
            $this->applyFilters($query, $filters);
        }
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(Query $query, array $filters): void
    {
        foreach ($filters as $property => $filter) {
            if (!is_string($property) || $property === '') {
                continue;
            }

            if (is_array($filter) && (array_key_exists('min', $filter) || array_key_exists('max', $filter))) {
                $min = $this->numericOrNull($filter['min'] ?? null);
                $max = $this->numericOrNull($filter['max'] ?? null);
                if ($min !== null || $max !== null) {
                    $query->addActiveFilter(new RangeFilter($property, $min, $max));
                }

                continue;
            }

            $values = is_array($filter) && array_key_exists('values', $filter) ? $filter['values'] : $filter;
            $values = is_array($values) ? $values : [$values];
            $values = array_values(array_filter($values, static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== ''));
            if ($values !== []) {
                $query->addActiveFilter(new TermFilter($property, array_map('strval', $values)));
            }
        }
    }

    private function numericOrNull(mixed $value): int|float|null
    {
        if (!is_numeric($value)) {
            return null;
        }

        $number = $value + 0;

        return is_int($number) || is_float($number) ? $number : null;
    }

    private function getSearch(string $name): SearchInterface
    {
        return $this->searchConfigurationProvider->getSearch($name);
    }

    private function getUrlFormater(): UrlFormaterInterface
    {
        return $this->urlFormaterProvider->getUrlFormater($this->search->getUrlFormater());
    }
}
