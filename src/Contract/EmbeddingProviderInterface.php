<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Contract;

interface EmbeddingProviderInterface
{
    /** @return list<float> */
    public function embed(string $text): array;
}
