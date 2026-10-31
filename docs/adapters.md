# Placing code in SearchBundle

Two rules, both derived from the pre-absorption package boundary
([ADR-0002](architecture.md), [ADR-0003](ux-search-absorption.md)). They survived the merge
because they were never really about packaging — they're about which half of the bundle a
given change belongs in.

## Rule 1: the kernel and UI stay Survos-free

These directories must not import `Survos\FieldBundle`, `Survos\Kit`, or any other
`survos/` package:

```text
src/Search/            query, filters, facets, result set, URL state, searcher
src/Twig/Components/   the 15 Live components
src/Context/  src/Event/  src/Exception/
templates/    assets/
```

The Survos-coupled layer is small and deliberately so — at time of writing exactly seven
files touch `Survos\Field` or `Survos\Kit`:

```text
src/Compiler/AutoEntitySearchPass.php
src/Service/FieldSearchConfigurator.php
src/Controller/AutoSearchController.php
src/Menu/SearchMenuSubscriber.php
src/Model/UxSearchDescriptor.php
src/Twig/SearchExtension.php
src/SurvosSearchBundle.php
```

Check it hasn't drifted:

```bash
grep -rln 'Survos\\\(Field\|Kit\)' src/ | sort
```

**Why it matters now that it's one package.** The merge removed the boundary that used to
enforce this for free. Without the rule, field-bundle metadata leaks into the query kernel
and the components, and the bundle becomes a ball of mud. Keeping it means a UI-only
consumer could still be served by a re-split later — which ADR-0003's pre-flight found
nobody wants *today*, not that nobody ever will.

Worth enforcing mechanically (deptrac layer rule, or the grep above in CI) rather than by
memory.

## Rule 2: adapters are placed by index ownership

Six adapters ship in this bundle, and they are not interchangeable in kind.

**Read-only adapters** query an index someone else owns. None of them writes:
`grep -rn 'addDocuments\|createIndex\|saveObjects\|updateSettings' src/Adapter/{Doctrine,Meilisearch,Algolia}` finds
nothing. Doctrine queries live tables; Meilisearch and Algolia assume the index was
populated elsewhere (for us, usually `survos/meili-bundle`).

| | Adapters |
|---|---|
| Read-only over a foreign index | Doctrine, Meilisearch, Algolia |
| Owns the index lifecycle | SQLite FTS5, Postgres BM25, Elasticsearch |

**Index-owning adapters** build and maintain their own storage: Elasticsearch formally,
through `Contract/IndexingAdapterInterface` (`ensureIndex()` / `bulkIndex()` / `ping()`,
driven by `Command/SearchIndexCommand`); SQLite FTS5 and Postgres BM25 through
`Command/SearchCreateCommand` and `Adapter/DbalAdapterTrait`.

**When adding an adapter,** decide which kind it is first. An index-owning adapter must
implement `IndexingAdapterInterface` (or expose DB-native index parameters that
`SearchIndexCommand` recognises) or `survos:search:index` will silently skip it with a
"does not expose DB-native index parameters" note.

## See also

- [defining-a-search.md](defining-a-search.md) — entity vs DTO, automatic vs manual
  registration, and which adapters accept a non-Doctrine class
- [elasticsearch.md](elasticsearch.md) — configuring the newest adapter
- [consumer-migration.md](consumer-migration.md) — the outstanding post-absorption work

## Note on embeddings

`SearchIndexCommand::externalDocuments()` currently calls the embedding provider inline,
once per document, with no cache — so a full reindex re-embeds the whole corpus. That is
fine only because nothing enables vectors yet (`retrievalMode` defaults to `lexical` and
`vectorDimensions` to `null`). Before any app turns vectors on, the fingerprint-keyed
embedding cache described in the indexing architecture notes needs to land, or every
mapping change bills the corpus again.

## Backend-independent Smart Search

The LiveComponent layout offers Search (existing FTS/text behavior) and Smart
Search when a DuckDB or PostgreSQL search declares `vectorColumn`, positive `vectorDimensions`,
and a `queryEmbedder` implementing `QueryEmbedderInterface`. The old
`DuckDbQueryEmbedderInterface` extends this contract for compatibility. The consuming
application must only declare these when its selected dataset actually contains
vectors and must use the same model/revision and prompts that generated them.
The bridge does not pick an embedding provider or install extensions.

`minimumSimilarity` defaults to 0.3 and is configurable per search. This is a
cosine similarity cutoff in [-1, 1], not a probability or a universal relevance
threshold. Tune it for the model and corpus. Visitors can adjust it in Smart
Search. Query vectors are validated; dimension mismatches and zero/non-finite
vectors are rejected. No vectors are included in browser state.

Smart Search replaces the lexical text predicate with exact cosine similarity
at or above the cutoff. It retains the base scope and term/range filters, orders
by similarity descending (or the selected explicit sort), and uses document ID
as a stable tie-breaker. Counts and facets use the same semantic predicate as
hits; facets retain their existing self-excluding filter semantics. Pagination
uses SQL LIMIT/OFFSET after filtering, without an artificial top-K candidate cap.
An empty query browses the dataset without requesting an embedding.

`Query::searchMode` (`lexical` or `semantic`), `minimumSimilarity`, page size,
query text, page, and facets survive URL round trips when URL rewriting is on.
Other adapters and datasets remain lexical by default. This integration is for
the server-rendered LiveComponent layout; the separate InstantSearch transport
is unchanged.

Smart Search opens a description dialog and runs only on submission. The separate
keyword field remains live as the user types and switches back to lexical mode.
Both modes retain selected facets. The active smart description is displayed above
the results; clearing it restores the previous keyword search. Similarity settings
are available inside the dialog. Natural-language extraction of structured filters
is not implemented yet: a future interpreter should validate its proposed fields
and values against the search definition and expose applied filters as editable facets.

### PostgreSQL and portable vectors

Use `postgres-vector://connection_name` with a Doctrine DBAL PostgreSQL connection
and the pgvector extension installed in that database. This uses the existing
PostgreSQL adapter, with `vectorColumn` pointing to a pgvector column. Keep its
`matchExpression` and `scoreExpression` for lexical search; they may use native
PostgreSQL FTS rather than a BM25 extension. Semantic search uses exact cosine
distance (`<=>`) and does not require an approximate vector index. Configure
`facetColumns` and `sortColumns` explicitly; semantic requests reject unknown filters.

Adapters advertise availability through `SemanticSearchAdapterInterface`. The
layout and query embedder contract do not depend on DuckDB. Unsupported adapters
reject semantic requests instead of silently returning lexical results. Existing
Doctrine searches using an application semantic hook can retain
`smartSearchAvailable`. Elasticsearch's legacy retrieval modes are separate;
its shared Smart Search implementation and Meilisearch support remain future work.

Reuse persisted vectors across backends using `EmbeddingProfile`: model,
dimensions, revision, and text preprocessing version are part of its fingerprint,
together with the exact embedding input. Equal dimensions alone do not establish
compatibility. A projection must validate this fingerprint and vector dimensions
before copying a stored vector. Applications own persistence and cache lookup;
the shared contract does not automatically intercept every indexing command.
