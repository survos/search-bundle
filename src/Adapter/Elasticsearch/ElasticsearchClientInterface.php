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

    /**
     * The whole `mappings` block Elasticsearch actually holds — `properties`, `dynamic`, `_source`.
     *
     * Not the same thing as the mapping the app declared: with `dynamic: true` (ES's default, and
     * what createIndex() currently leaves in force) Elasticsearch adds fields it inferred from
     * indexed documents, permanently. Comparing declared against actual is the point — it is the
     * schema validation elastic-bundle's README lists as missing. See survos/mono#42.
     *
     * @return array<string, mixed> empty when the index does not exist
     */
    public function getMapping(string $index): array;

    /**
     * Index settings, flattened to dotted keys (`index.number_of_shards`, `index.analysis.*`, …).
     *
     * @return array<string, mixed> empty when the index does not exist
     */
    public function getSettings(string $index): array;

    /**
     * Alias names pointing at this index.
     *
     * An empty list means the index is addressed directly, so any reindex needs a delete first
     * and serves nothing while it runs.
     *
     * @return list<string>
     */
    public function getAliases(string $index): array;

    /**
     * `_stats` for the index — doc counts, store size, segments.
     *
     * @return array<string, mixed> empty when the index does not exist
     */
    public function getStats(string $index): array;
}
