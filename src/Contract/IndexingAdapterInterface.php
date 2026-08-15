<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Contract;

interface IndexingAdapterInterface
{
    /** @param array<string, mixed> $mappings */
    public function ensureIndex(string $index, array $mappings, bool $drop = false): void;

    /**
     * @param iterable<array{id: string, document: array<string, mixed>}> $documents
     */
    public function bulkIndex(string $index, iterable $documents, int $batchSize = 250): int;

    public function ping(): bool;
}
