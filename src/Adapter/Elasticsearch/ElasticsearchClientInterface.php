<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\Elasticsearch;

interface ElasticsearchClientInterface
{
    /** @param array<string, mixed> $body
     *  @return array<string, mixed>
     */
    public function search(string $index, array $body): array;

    /**
     * @param array<string, mixed> $mappings the whole `mappings` block — `properties`, `dynamic`, …
     *                                       rather than just the properties, so callers can set
     *                                       `dynamic: strict` and (later) analyzers without another
     *                                       signature change
     * @param array<string, mixed> $settings index settings; analysis config is static and can only
     *                                       be set at creation
     */
    public function createIndex(string $index, array $mappings, array $settings = []): void;

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

    /**
     * Indices in the cluster, optionally narrowed by an index pattern such as `kpa-*`.
     *
     * The cluster's index namespace is flat and shared by every app pointed at the node, so this
     * is how an app finds indices it owns but never declared — a leftover from a rename, a locale
     * variant, an index created outside the app. The registry alone cannot see those.
     *
     * @param string $pattern index pattern; `*` for everything
     *
     * @return list<array{index: string, health: ?string, status: ?string, docs: int, size: ?string, primaries: ?int, replicas: ?int}>
     */
    public function listIndices(string $pattern = '*'): array;

    /**
     * Mappings for every index matching the pattern, in one request.
     *
     * The per-index getters are fine for a detail page, but a page covering N indexes must not
     * make 5N round trips — Elasticsearch answers `_mapping`, `_settings` and `_alias` for a whole
     * pattern just as happily as for one index.
     *
     * @return array<string, array<string, mixed>> index name => its `mappings` block
     */
    public function listMappings(string $pattern = '*'): array;

    /** @return array<string, array<string, mixed>> index name => its flat settings */
    public function listSettings(string $pattern = '*'): array;

    /** @return array<string, list<string>> index name => aliases pointing at it */
    public function listAliases(string $pattern = '*'): array;

    /**
     * Apply alias actions in one request.
     *
     * Elasticsearch applies every action in a single `_aliases` call atomically, which is the only
     * way to move an alias without a window where it points at nothing. Removing the old and adding
     * the new as two calls is exactly the downtime this exists to avoid.
     *
     * @param list<array<string, mixed>> $actions e.g. [['remove' => [...]], ['add' => [...]]]
     */
    public function updateAliases(array $actions): void;

    /**
     * Concrete indices behind an alias, newest name last. Empty when the alias does not exist.
     *
     * @return list<string>
     */
    public function indicesForAlias(string $alias): array;
}
