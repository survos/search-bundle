<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter;

use Survos\SearchBundle\Search\SearchInterface;

/** Semantic filtering must apply to hits, totals and facets before pagination. */
interface SemanticSearchAdapterInterface
{
    public function supportsSemanticSearch(SearchInterface $search): bool;
}
