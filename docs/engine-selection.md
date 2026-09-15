# Engine selection and Elasticsearch evaluation

Decision: 2026-09-15. Supersedes the single-engine retirement proposal in
[mono#53](https://github.com/survos/mono/issues/53).

## Policy

Preserve Meilisearch, Elasticsearch, Postgres BM25, SQLite FTS5, and the other existing
adapters. An application selects a default adapter; a named search or dataset may select
another. The default is a fallback, not a restriction on which engines an app can use.

Normally one engine owns a dataset's search and indexing. An app may use Meili for tenants
and Elasticsearch for aggregated Folio records. Do not prohibit multiple engines for
architectural purity. Explicit comparison/migration tooling may index the same frozen
dataset into both engines. Routine applications need not do so, and searches must not
automatically fan out across engines.

Keep Query/ResultSet and the existing adapter contract. Expose advanced capabilities and
adapter-specific tuning explicitly, without promising identical relevance or requiring all
adapters to implement semantic retrieval. Unsupported configurations must fail clearly.
Read and write paths must agree on adapter, index identity, and application namespace.

## GlobalGiving as reference

`survos-sites/global-giving` currently bypasses SearchBundle. Its catalog SearchService
derives Meili settings from Field metadata, builds temporary indexes, and swaps them after
successful indexing. Its separate ComparisonService/LabGateway uses a checksummed JSONL
snapshot, cached shared embeddings with model digest checks, country prefilters, and
application-side reciprocal rank fusion for both engines. It saves runs and relevance
judgments scoped to query, country, and snapshot.

Use that application to validate reusable request translation, supplied-vector support,
embedding provenance, result diagnostics, and safe publication. Keep experiment snapshots,
judgments, and the comparison UI in the evaluation app. Do not copy its fixed top-50/top-10
experiment into the general pagination contract. Its per-document ES writes versus batched
Meili writes do not provide a balanced indexing-throughput comparison.

## Source-review gaps to verify and fix

- Named AsSearch adapters and AdapterProvider's default fallback already exist.
- AutoEntitySearchPass records entity adapter overrides on service tags, but the inspected
  AutoEntitySearch inherits getAdapterName(), which reads class attributes. Ensure the
  configured override reaches runtime search and indexing, with a mixed-dataset test.
- FieldSearchConfigurator emits neutral searchFields/facetColumns/sortColumns; provide a
  Meili translation path rather than passing unsupported parameters to its options resolver.
- Meili's SearchBundle adapter needs the supplied-vector and retrieval-mode support already
  demonstrated by GlobalGiving's lab.
- Verify multi-select facet semantics, count scope, paging limits, highlights, deterministic
  sorting, and native versus application-side fusion behavior before claiming parity.
- External engine lifecycle belongs in the engine bundles; share publication guarantees and
  metadata rather than putting raw HTTP or engine switches into every application.

These are source-review observations, not completed fixes or live benchmark results.
GlobalGiving's comparison tests passed on 2026-09-15: 6 tests, 20 assertions.

## Rollout order

1. Release the current SearchBench baseline before further migration work.
2. Use `survos-sites/bench` (Composer name `survos-sites/searchbench`) as the principal
   Elasticsearch integration testcase. It already selects ES as SearchBundle's default,
   while retaining direct Meili and API Platform demonstrations. Complete and validate that
   path instead of assuming this is an untouched Meili-only app.
3. Begin with Movie (text, array facets, numeric ranges and sorting); expand to Car, Marvel,
   and museum datasets. Record actual corpus counts, representative judged queries, typo
   and prefix cases, facet/filter semantics, end-to-end latency, and indexing resources.
   Preserve working Meili examples and existing adapters throughout.
4. Fix shared behavior in the bundles and use the results for KPA, packages, and other
   consumers. Bench success is evidence to reuse, not automatic validation of their corpora.
5. Address Folio searching in ES separately afterward: aggregated record identity, tenant
   scoping, multilingual/OCR fields, and eventually chunked retrieval need dedicated tests.

Semantic modes follow a working lexical baseline. Record deployment/version/license and
inference costs explicitly. No Meili retirement, production deployment, or automatic paid
embedding run is implied by this evaluation.

## Implemented selection and browser entry point

Auto-entity searches now receive the configured `entity_adapters` override directly. For example,
`entity_adapters: {app_tenant: meili}` overrides an ES default for tenant browsing. Lifecycle
selection skips non-ES descriptors and continues looking for an ES search of the entity.
See [InstantSearch](instantsearch.md) for the client-rendered lexical UI and explicit public-search allowlist.
