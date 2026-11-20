<?php

declare(strict_types=1);

namespace Survos\SearchBundle\ApiPlatform;

/** A search envelope, not an entity collection: facets belong to the whole result. */
final readonly class SearchResponse
{
    /** @param list<array<string, mixed>> $hits */
    public function __construct(
        public string $search,
        public string $query,
        public array $hits,
        public int $total,
        public int $page,
        public int $limit,
        public int $pages,
        public array $facets,
        public array $facetStats,
        public int $processingTimeMs,
    ) {
    }
}
