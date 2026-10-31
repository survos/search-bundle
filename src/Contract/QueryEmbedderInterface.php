<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Contract;

/** Resolve the dataset's embedding profile and reuse cached query embeddings. */
interface QueryEmbedderInterface
{
    /** @return list<float> */
    public function embedQuery(string $text): array;
}
