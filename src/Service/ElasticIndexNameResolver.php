<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Service;

use Survos\SearchBundle\Search\SearchInterface;

/**
 * The single source of truth for an Elasticsearch index name.
 *
 * Never derive one anywhere else. Before this existed there were three implementations —
 * ElasticParameterTranslator set the default, ElasticsearchAdapter resolved it on the query path,
 * and elastic-bundle's ElasticIndexService resolved it again on the write path — and the last two
 * disagreed on both the fallback and the sanitiser. A manual `#[AsSearch]` without an explicit
 * `index` was queried at one name and populated at another: zero hits, no error. See
 * survos/mono#44.
 *
 * It lives in search-bundle rather than elastic-bundle because both the query adapter and the
 * index lifecycle must agree, and that shared ownership is the whole point.
 *
 * Three layers, modelled on meili-bundle's IndexNameResolver:
 *
 *     base    song            logical name, sanitised, no prefix, no locale
 *     raw     song_fr         base + locale, still unprefixed
 *     uid     kpa-song_fr     prefix applied ONCE, centrally, here
 *
 * The prefix landing in exactly one method is what let meilisearch introduce MEILI_PREFIX across
 * 47 call sites without touching any of them.
 *
 * On locale: Elasticsearch does **not** use per-locale indexes. Translated facets are handled at
 * query time — aggregate on a locale-independent code and translate labels at render, or use
 * keyword multi-fields (`category.en`) when the localised string itself must be the facet. The
 * `$locale` parameter exists for folio's translated builds; the default path never passes one.
 */
final readonly class ElasticIndexNameResolver
{
    /** Elasticsearch rejects uppercase index names outright, and several characters besides. */
    private const string SANITISE_PATTERN = '/[^a-z0-9_\-]+/';

    private const string FALLBACK = 'search';

    /**
     * @param string|null $prefix null means "nobody decided" and is a hard error the first time a
     *                            name is resolved — an unprefixed index silently joins a cluster
     *                            namespace shared with every other app, which is how two apps end
     *                            up writing one `article` index with incompatible mappings. Pass
     *                            '' to opt out deliberately.
     */
    public function __construct(
        private ?string $prefix = null,
    ) {
    }

    /**
     * Logical name: the configured `index` adapter parameter, else the search's own index name,
     * sanitised. Never prefixed — callers that want a real index name want {@see uid()}.
     */
    public function base(SearchInterface $search): string
    {
        $configured = $search->getResolvedAdapterParameter('index');
        $name = \is_string($configured) && '' !== $configured
            ? $configured
            : (string) $search->getIndexName();

        return $this->slug($name) ?: self::FALLBACK;
    }

    /** base + locale, still unprefixed. */
    public function raw(SearchInterface $search, ?string $locale = null): string
    {
        return $this->rawFor($this->base($search), $locale);
    }

    /** The actual Elasticsearch index name. */
    public function uid(SearchInterface $search, ?string $locale = null): string
    {
        return $this->uidForRaw($this->raw($search, $locale));
    }

    /** @param string $base an already-sanitised logical name */
    public function rawFor(string $base, ?string $locale = null): string
    {
        $locale = null === $locale ? '' : $this->slug($locale);

        return '' === $locale ? $base : \sprintf('%s_%s', $base, $locale);
    }

    /**
     * Applies the prefix, once.
     *
     * Idempotent, exactly as MeiliRegistry::uidFor() is: resolving an already-resolved name is a
     * no-op rather than `kpa-kpa-song`, so passing a uid where a raw was expected cannot corrupt
     * anything.
     */
    public function uidForRaw(string $raw): string
    {
        $prefix = $this->normalisedPrefix();

        if ('' === $prefix || str_starts_with($raw, $prefix)) {
            return $raw;
        }

        return $prefix.$raw;
    }

    /**
     * Index pattern covering everything this app owns, e.g. `kpa-*`.
     *
     * Derived from the same prefix the names are, so an admin page filtering by pattern cannot
     * drift from what is actually written. With no prefix configured this is `*` — every index in
     * the cluster, which is the honest answer: without a prefix the app owns no namespace.
     */
    public function pattern(): string
    {
        $prefix = $this->normalisedPrefix();

        return '' === $prefix ? '*' : $prefix.'*';
    }

    public function hasPrefix(): bool
    {
        return '' !== $this->normalisedPrefix();
    }

    /**
     * The concrete index an alias points at, e.g. `bench_movie_20260815213000`.
     *
     * Everything queries and indexes through the alias; the concrete name exists only so a rebuild
     * has somewhere to write while the old index keeps serving. The timestamp makes the generations
     * sortable and self-documenting, and keeps the app's own pattern matching them (`bench_*`
     * covers both the alias and its generations).
     */
    public function concreteFor(string $alias, ?string $generation = null): string
    {
        return \sprintf('%s_%s', $alias, $generation ?? date('YmdHis'));
    }

    /** True when $index looks like a generation of $alias rather than an unrelated index. */
    public function isGenerationOf(string $index, string $alias): bool
    {
        return str_starts_with($index, $alias.'_');
    }

    /**
     * The prefix, guaranteed to end in a separator.
     *
     * MEILI_PREFIX is reused verbatim, and apps write it either way — `kpa` or `kpa_`. Trimming
     * the separator off would silently produce `kpasong`, so a missing one is added rather than
     * assumed. An underscore is preserved when that is what was configured, which keeps the
     * Elasticsearch and Meilisearch index names identical for the same app.
     */
    private function normalisedPrefix(): string
    {
        if (null === $this->prefix) {
            throw new \LogicException(
                'No Elasticsearch index prefix is configured. Without one this app writes bare '
                .'index names (`article`, `song`) into a cluster namespace shared by every other '
                .'app pointed at the same node, so two apps can silently end up writing the same '
                .'index with incompatible mappings. Set survos_search.index_prefix — it defaults '
                .'to %env(default::MEILI_PREFIX)%, so setting MEILI_PREFIX is usually enough. '
                .'To share the namespace deliberately, set it to an empty string.',
            );
        }

        $prefix = strtolower(trim($this->prefix));
        if ('' === $prefix) {
            return '';
        }

        $prefix = ltrim((string) preg_replace(self::SANITISE_PATTERN, '-', $prefix), '-_');
        if ('' === $prefix) {
            return '';
        }

        return str_ends_with($prefix, '-') || str_ends_with($prefix, '_') ? $prefix : $prefix.'-';
    }

    /** Lowercase and strip whatever Elasticsearch will not accept. May legitimately return ''. */
    private function slug(string $name): string
    {
        // A namespace separator is the common case — an entity FQCN arrives here whenever a search
        // has no explicit `index` parameter.
        return trim((string) preg_replace(self::SANITISE_PATTERN, '-', strtolower(trim($name))), '-');
    }
}
