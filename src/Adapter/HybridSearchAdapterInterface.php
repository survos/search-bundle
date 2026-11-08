<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter;

use Survos\SearchBundle\Search\SearchInterface;

interface HybridSearchAdapterInterface
{
    public function supportsHybridSearch(SearchInterface $search): bool;
}
