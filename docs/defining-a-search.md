# Defining a search: entity, DTO, or projection

Short answer: **yes, a search can be defined on a DTO** — but only via the manual path, and
with one indexing caveat. The details matter, so they're spelled out below.

## Two paths

### Automatic — Doctrine entities only

`Compiler/AutoEntitySearchPass` creates one `Search/AutoEntitySearch` service per entity
registered in field-bundle's `EntityMetaRegistry`, which `EntityMetaPass` builds by
scanning entity directories for `#[EntityMeta]`. `AutoEntitySearch` then reads Doctrine
`ClassMetadata` for field types, table name, and identifier.

This path is **entity-only, end to end** — there is no DTO equivalent. It buys you a
zero-code search plus the `/entity/{code}/search` route and an admin menu entry.

### Manual — any class

Write a class, tag it with `#[AsSearch]`, and `DependencyInjection/RegisterSearchPass`
registers it into `SearchProvider`. Two bases:

- **`Search/AbstractSearch`** — you declare facets and sorts yourself. `folio-bundle`'s
  `FolioRowSearch` works this way, with `#[AsSearch(index: 'folio_row', adapter: 'folio_fts')]`
  — note `index` is a free-form string, not necessarily a class.
- **`Search/AbstractFieldSearch`** — facets and sorts are derived from `#[Field]` metadata
  on whatever class `getFieldClass()` returns. Its docblock is explicit:

  > Return the class carrying `#[Field]` metadata. This may be a Doctrine entity, a DTO,
  > or a search-facing projection class.

  `Service/FieldSearchConfigurator` reads that metadata through field-bundle's
  `FieldReader`, which doesn't care whether the class is managed by Doctrine.

So a DTO-backed search is:

```php
#[AsSearch(index: 'package', name: 'package', adapter: 'es')]
final class PackageSearch extends AbstractFieldSearch
{
    protected function getFieldClass(array $options = []): string
    {
        return PackageSearchDocument::class;   // a plain DTO with #[Field] attributes
    }
}
```

## Which adapters accept a DTO

| Adapter | DTO-friendly | Why |
|---|---|---|
| Elasticsearch | yes | document store; `documentProvider` / `documentMapper` are arbitrary callables |
| Meilisearch, Algolia | yes | document stores; the index is populated elsewhere |
| SQLite FTS5, Postgres BM25 | no | need a real `table` / `idColumn` to query |
| Doctrine | no | builds a `QueryBuilder` against a managed entity |

## The caveat: manual searches are invisible to `survos:search:index`

`Registry/UxSearchRegistry` is populated **only** by `AutoEntitySearchPass`, which
overwrites its `$descriptors` argument outright
([`AutoEntitySearchPass.php:114`](../src/Compiler/AutoEntitySearchPass.php)). A manual
`#[AsSearch]` class is registered in `SearchProvider` but never appears in the registry, so
three things don't see it:

- `Command/SearchIndexCommand` — iterates `uxSearchRegistry->all()`, so it cannot build or
  refresh the index for a manual search
- `Controller/AutoSearchController` — the `/entity/{code}/search` route
- `Menu/SearchMenuSubscriber` — the admin menu entry

For a DBAL-backed manual search this doesn't bite, because the index is built elsewhere
(folio-bundle builds its own FTS5 tables during the folio build). For a **DTO + Elasticsearch**
search it does: querying and rendering work, but nothing will populate the ES index.

Until that's addressed, either populate the index from your own command, or give
`UxSearchRegistry` a tag-driven source so manual searches can contribute descriptors
alongside the auto ones. The second is the better fix and is not yet done.

## Update: manual searches now reach the registry — but still can't target Elasticsearch

The `UxSearchRegistry` gap described above is **fixed**. `AutoEntitySearchPass` now also builds
descriptors for hand-written `#[AsSearch]` classes whose `index` is a real class, so
`survos:search:index`, `AutoSearchController`, the admin menu, and survos/elastic-bundle's
commands and postFlush reconcile can all see them. (An `index` that is a free-form string, like
folio-bundle's `folio_row`, is still skipped — there's no class to load documents from.)

A second, separate gap remains, and it's what actually blocks Elasticsearch for apps like
`ff` and `news`:

**`AbstractFieldSearch` produces DBAL-shaped adapter parameters.** `FieldSearchConfigurator`
sets `facetColumns`, `sortColumns`, `searchFields`; `configureDbalAdapter()` adds `table`,
`idColumn`, `matchExpression`. The translation into Elasticsearch's shape — `facetFields`,
`sortFields`, `mappings`, `sourceFields`, `idField` — exists **only** as
`AutoEntitySearch::configureElasticsearchAdapter()`, a private method on the automatic path.

So pointing a hand-written search at an `elasticsearch://` adapter fails at
`configureParameters()`:

```
The options "facetColumns", "idColumn", "matchExpression", "scoreExpression",
"selectColumns", "sortColumns", "table" do not exist.
```

**What it would take:** lift that translation out of `AutoEntitySearch` into something any
search can use — a service that, given a search whose resolved adapter is Elasticsearch,
rewrites the DBAL-shaped parameters into ES ones. `AbstractFieldSearch::build()` would call it
the same way it already calls `FieldSearchConfigurator`. The awkward part is that
`AbstractFieldSearch` doesn't currently know its adapter's *type* (only its name), so the
translator needs `AdapterProvider` to resolve name → adapter before deciding whether to run.

Until then, Elasticsearch works on the automatic path only (`#[EntityMeta]` + the
SEARCHABLE/FILTERABLE/SORTABLE_FIELDS constants), which is how `packages` and `searchbench`
use it.


## Update 2: the automatic and hand-written paths are now the same path

Both gaps above are closed (survos/mono#41). `AbstractFieldSearch::build()` resolves the
adapter, picks a `ParameterTranslatorInterface`, and applies it — so a hand-written search and
an auto-entity one are configured identically:

| Adapter | Translator | Column prefix |
|---|---|---|
| Doctrine ORM | `DoctrineParameterTranslator` | `o.` (DQL needs the alias) |
| SQLite FTS5, Postgres BM25 | `DbalParameterTranslator` | none |
| Elasticsearch | `ElasticParameterTranslator` | none |

`AutoEntitySearch` no longer contains engine knowledge — it derives field names from Doctrine
metadata, applies `SEARCHABLE_FIELDS` / `FILTERABLE_FIELDS` / `SORTABLE_FIELDS`, and delegates.

Two consequences worth knowing:

- **`#[AsSearch(adapter: ...)]` works on auto-entity searches.** The compiler pass no longer
  hardcodes `adapter => null`, so entities can use different engines in one app.
- **`survos_search.default_adapter` may be an `%env()%` placeholder again.** It had to be a
  literal only because it was read at *compile* time to choose a branch; an env var there
  produced a null DSN and silently fell back to Doctrine. Resolution is by adapter type at
  runtime now.

To add an engine: implement `ParameterTranslatorInterface`, tag it
`survos_search.parameter_translator` (autoconfigured), and both paths pick it up.

### Writing a search that serves two engines

`usesElasticsearch()` is `protected`, so a search that hand-writes engine-specific parameters
can skip them:

```php
public function build(array $options = []): void
{
    parent::build($options);
    if ($this->usesElasticsearch()) {
        return;              // mappings/facets came from the translator
    }
    // ...Postgres to_tsvector expressions
}
```

`news/src/Search/ArticleSearch.php` does exactly this.
