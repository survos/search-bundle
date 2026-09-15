# InstantSearch with named searches

The optional `instantsearch` Stimulus controller uses actual InstantSearch.js widgets with
SearchBundle's named-query layer. Elasticsearch is the first evaluated backend (SearchBench),
not a mandatory engine. Search/index lifecycle remains in each engine's own bundle.

Enable the controller in `assets/controllers.json`. Install the InstantSearch and twig-browser
imports declared in this bundle's assets/package.json, and use `survos/js-twig-bundle` to generate
`@survos/js-twig/generated/fos_routes.js`. FOS JSRouting supplies browser `path()`; this does not
require FOSElasticaBundle. Add result detail routes to js-twig's `routes_to_expose`.

```yaml
survos_search:
    default_adapter: es
    entity_adapters:
        app_tenant: meili
    public_searches: [app_movie]
```

Both adapter names must be configured. `entity_adapters` keys are auto-entity search codes;
explicit search classes can continue to declare their adapter through `#[AsSearch]`.
The default is a fallback. Multiple engines over different entities are supported; deliberate
comparison searches over the same entity are also allowed.

The controller receives endpoint, name, template, sorts, and context values. Its targets are
query, hits, stats, pagination, current, clear, sort, facet, range, and error. Each facet/range
container declares `data-field`; a range can supply `data-label` for accessible input names.
The template URL must return **plain Twig source**, not server-rendered hits. Browser Twig
renders each hit with `{hit, ...context}`. Escape source values; only use raw highlight fragments
when the selected adapter guarantees HTML encoding. Elasticsearch highlights use HTML encoding.
See SearchBench's `templates/search/browse.html.twig` and `card.browser.twig` for a complete app.

## HTTP contract

`POST /instant-search` (route `survos_search_instant`; respects the bundle route configuration)
accepts `{requests: [{indexName: "app_movie", params: {...}}]}` and returns `{results: [...]}`.
Nothing is exposed by default. Only names in `public_searches` are accepted. Do not allowlist
searches containing private documents without application authorization and tenant filtering.

Supported parameters: query, zero-based page, hitsPerPage (0–100), facetFilters (OR groups
within one facet, AND across facets), and numericFilters (`>=`, `<=`, `=`). A virtual index name
such as `app_movie::year:desc` selects an already-declared sort. Arbitrary index names, raw DSL,
cross-field OR groups and negative facet selections are rejected. Requests are bounded to ten
searches, 64 KiB body, 300 query characters, and the first 10,000 results. Facet-only requests
with zero hits are supported because InstantSearch sends them for disjunctive refinements.

Responses include hits/objectID, totals, paging, facets, numeric bounds, and safe ES highlights.
`Query::hydrateEntities=false` keeps the index projection intact and avoids loading Doctrine
entities for every browser request. Adapters must return document arrays for this endpoint;
object-returning Doctrine searches need an explicit projection before using it.

This implements the lexical widgets used by Bench, not every Algolia API feature. There is no
analytics/Insights, arbitrary filter language, geo search, search-for-facet-values, or semantic
parity promise. Other Elasticsearch UI clients can use the existing Query/Searcher layer;
indexing and event synchronization are independent of the browser library.
