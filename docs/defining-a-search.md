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
