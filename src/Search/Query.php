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

namespace Survos\SearchBundle\Search;

use Survos\SearchBundle\Search\Filter\FilterInterface;

class Query
{
    private string $queryString = '';

    private string $searchMode = 'lexical';
    private ?float $minimumSimilarity = null;

    public function getSearchMode(): string
    {
        return $this->searchMode;
    }

    public function setSearchMode(string $mode): static
    {
        if (!in_array($mode, ['lexical', 'semantic'], true)) {
            throw new \InvalidArgumentException('Unknown search mode.');
        }
        $this->searchMode = $mode;

        return $this;
    }

    public function getMinimumSimilarity(): ?float
    {
        return $this->minimumSimilarity;
    }

    public function setMinimumSimilarity(?float $value): static
    {
        if (null !== $value && (!is_finite($value) || $value < -1 || $value > 1)) {
            throw new \InvalidArgumentException('Minimum cosine similarity must be between -1 and 1.');
        }
        $this->minimumSimilarity = $value;

        return $this;
    }

    /** Browser search consumes the index projection without loading Doctrine entities. */
    public bool $hydrateEntities = true;

    /** @var array<string, FilterInterface> */
    private array $activeFilters = [];

    private int $currentPage = 1;

    private ?string $activeSort = null;

    private int $activeHitsPerPage = 12;

    public function getQueryString(): string
    {
        return $this->queryString;
    }

    public function setQueryString(string $queryString): static
    {
        $this->queryString = $queryString;

        return $this;
    }

    /**
     * @return array<string, FilterInterface>
     */
    public function getActiveFilters(): array
    {
        return $this->activeFilters;
    }

    /**
     * @param array<array-key, FilterInterface> $activeFilters keys are rebuilt from each filter property
     */
    public function setActiveFilters(array $activeFilters): static
    {
        $this->activeFilters = [];
        foreach ($activeFilters as $filter) {
            $this->activeFilters[$filter->getProperty()] = $filter;
        }

        return $this;
    }

    public function getActiveFilter(string $property): ?FilterInterface
    {
        return $this->activeFilters[$property] ?? null;
    }

    public function addActiveFilter(FilterInterface $filter): static
    {
        $this->activeFilters[$filter->getProperty()] = $filter;

        return $this;
    }

    public function hasActiveFilter(string $property): bool
    {
        return isset($this->activeFilters[$property]);
    }

    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    public function setCurrentPage(int $currentPage): static
    {
        $this->currentPage = $currentPage;

        return $this;
    }

    public function setActiveSort(?string $sort): static
    {
        $this->activeSort = $sort;

        return $this;
    }

    public function getActiveSort(): ?string
    {
        return $this->activeSort;
    }

    public function getActiveHitsPerPage(): int
    {
        return $this->activeHitsPerPage;
    }

    public function setActiveHitsPerPage(int $activeHitsPerPage): static
    {
        $this->activeHitsPerPage = $activeHitsPerPage;

        return $this;
    }

    public function removeActiveFilter(FilterInterface $filter): void
    {
        unset($this->activeFilters[$filter->getProperty()]);
    }
}
