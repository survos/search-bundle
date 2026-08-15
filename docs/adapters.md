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
