# Elasticsearch adapter

Added 2026-08-15. Lexical retrieval only — see [Vectors](#vectors-not-yet) below.

## Configuration

```yaml
# config/packages/survos_search.yaml
survos_search:
    default_adapter: '%env(SEARCH_ADAPTER)%'
    adapters:
        pg: 'postgres-bm25://default'
        es: '%env(ELASTICSEARCH_DSN)%'
```

```dotenv
SEARCH_ADAPTER=es
ELASTICSEARCH_DSN=elasticsearch://127.0.0.1:9200
```

`Adapter/Elasticsearch/ElasticsearchFactory` accepts `elasticsearch://`,
`elasticsearch+https://`, and `elastic://`. Auth comes off the DSN — either
`elasticsearch+https://user:pass@host` or `?api_key=…`.

**Engine choice is per-app, not per-search.** `AutoEntitySearch` picks its configuration
branch by inspecting the *default* adapter's DSN, so an app's auto-searches all run on one
engine. Flipping `SEARCH_ADAPTER` between `pg` and `es` is how you compare backends on the
same data; you cannot serve both simultaneously from auto-searches.

## What gets derived automatically

For an auto-search, `AutoEntitySearch::configureElasticsearchAdapter()` builds the whole ES
configuration from Doctrine metadata:

| Doctrine | Elasticsearch |
|---|---|
| `string`, `text`, `ascii_string` | `text` + a `.keyword` subfield (`ignore_above: 512`) |
| `integer`, `smallint`, `bigint` | `long` |
| `float`, `decimal` | `double` |
| `boolean` | `boolean` |
| date/datetime types | `date` |
| table name | index name |
| single identifier | `idField` |

Facet and sort fields use the `.keyword` subfield for text columns. Which columns become
facets and sorts comes from the entity's `SEARCHABLE_FIELDS`, `FILTERABLE_FIELDS`, and
`SORTABLE_FIELDS` class constants (`AutoEntitySearch::applyConstantFields()`). Keep
`FILTERABLE_FIELDS` to low-cardinality columns — a terms aggregation over a free-text
column is slow and useless, and the adapter caps results at `maxFacetValues: 100`.

## Indexing

```bash
bin/console survos:search:index <code> --drop --limit=500   # while the mapping is churning
bin/console survos:search:index <code>                      # full
```

`ElasticsearchAdapter` implements `Contract/IndexingAdapterInterface`, so
`SearchIndexCommand` calls `ensureIndex()` then `bulkIndex()`.

**Known rough edge:** with no `documentProvider` configured, `externalDocuments()` falls
back to `$manager->getRepository($class)->findAll()`, which hydrates the entire table into
memory before bulk-indexing. Fine behind `--limit` while iterating; set a `documentProvider`
backed by `toIterable()` before running a large corpus.

See also [defining-a-search.md](defining-a-search.md) — manual `#[AsSearch]` classes are
not visible to `survos:search:index` yet, which matters if you back one with Elasticsearch.

## Vectors: not yet

`retrievalMode` defaults to `lexical` and `vectorDimensions` to `null`. Nothing enables
`dense_vector`, kNN, or hybrid ranking, and no app should turn them on yet.

The blocker is cost, not capability. `SearchIndexCommand::externalDocuments()` calls the
embedding provider inline, once per document, with **no cache** — so every reindex, and
every mapping change that forces one, re-embeds the whole corpus. The fix is a
fingerprint-keyed embedding cache (keyed on model + chunker version + normalizer version +
semantic text) living outside the index lifecycle, so rebuilding an index or switching
engines reuses vectors. `Contract/EmbeddingProviderInterface` and the
`embeddingText` / `embeddingProvider` / `documentProvider` adapter parameters are the seams
it will hook into; the cache itself doesn't exist.

## Rollout status

- **`packages`** — first pilot, and deliberately a playground rather than a commitment. It
  already has a working `survos/meili-bundle` integration and is a perfectly good candidate
  to **stay on Meilisearch**; the point of wiring it to Elasticsearch is that it's greenfield
  for SearchBundle (no `Mezcalito\` legacy, no template overrides), which makes it the
  cleanest canary for the post-absorption namespace, config root, and component names.
  Whether ES earns a permanent place there is a separate question, best answered by
  comparing the two backends on the same corpus.
- **Production infrastructure** — none yet. Local development uses the shared node in
  `~/sites/docker/docker-compose.yaml` (Elasticsearch 9.5.0, `single-node`,
  `xpack.security.enabled=false`, bound to `127.0.0.1:9200`). A production deployment would
  follow the `~/sites/meilisearch` pattern (Dockerfile as version pin, Dokku app, persistent
  mounts), with three differences: `vm.max_map_count=262144` is a host-level sysctl Dokku
  can't set, an unauthenticated node must never get a public domain, and JVM heap plus
  filesystem cache compete with Postgres on a shared host. Not worth building until
  something depends on it.
