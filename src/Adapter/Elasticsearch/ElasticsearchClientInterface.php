<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\Elasticsearch;

interface ElasticsearchClientInterface
{
    /** @param array<string, mixed> $body
     *  @return array<string, mixed>
     */
    public function search(string $index, array $body): array;

    /** @param array<string, mixed> $mappings */
    public function createIndex(string $index, array $mappings): void;

    public function deleteIndex(string $index): void;

    public function indexExists(string $index): bool;

    /**
     * @param list<array<string, mixed>> $body
     * @return array<string, mixed>
     */
    public function bulk(array $body): array;

    public function refresh(string $index): void;

    public function ping(): bool;
}
