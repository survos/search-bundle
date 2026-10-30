<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\DuckDb;

/** The application selects the matching model/revision and caches query embeddings. */
interface DuckDbQueryEmbedderInterface
{
    /** @return list<float> */
    public function embedQuery(string $text): array;
}
