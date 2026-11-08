# Elasticsearch: lexical, semantic, and hybrid search

This is the consumer integration guide for the current SearchBundle source. The historical
[engine evaluation](engine-selection.md) records earlier decisions, not the current API.
KPA exercises these modes locally; that does not establish production readiness for another
corpus or node configuration. Install a package revision containing
`SemanticSearchAdapterInterface` and `HybridSearchAdapterInterface` support in the ES adapter.

## Responsibilities and setup

| Owner | Responsibility |
|---|---|
| Consuming app/bundle | Generic Field metadata, document text, embedding provider/profile, durable vector storage, invalidation, search registration, hit template |
| SearchBundle | Query embeddings via the configured provider, retrieval, scores, facets, pagination, LiveComponent tabs |
| ElasticBundle | Index mappings/generations, document projection, bulk indexing, alias swaps |

```yaml
survos_search:
    default_adapter: es
    index_prefix: catalog_
    adapters:
        es: '%env(ELASTICSEARCH_DSN)%'
```

```dotenv
ELASTICSEARCH_DSN=elasticsearch://127.0.0.1:9200
```

Named searches may select a different adapter. Supported schemes include `elasticsearch://`,
`elastic://`, and `elasticsearch+https://`. Authentication comes from the DSN's user/password
or `api_key` query parameter. `ca=/path/to/ca.crt` selects a private CA; keep credentials out
of templates and browser state. The official PHP client is `elasticsearch/elasticsearch` 9.x.

## Configure a vector-aware search

For `AbstractFieldSearch`, call `parent::build($options)` first, then extend the resulting
adapter parameters. `FieldSearchConfigurator` reads generic Field descriptors and
`ElasticParameterTranslator` derives scalar mappings from Doctrine. Add vector mapping
**after** translation, because translation rebuilds the mappings. A typical extension is:

```php
public function build(array $options = []): void
{
    $this->setAdapterParameters([]); // Repeated LiveComponent builds must start clean.
    parent::build($options);
    $parameters = $this->getAdapterParameters();
    $parameters['mappings']['embedding'] = [
        'type' => 'dense_vector',
        'dims' => 768, // Must match this consumer's model, not a universal default.
        'index' => true,
        'similarity' => 'cosine',
    ];
    $parameters['vectorField'] = 'embedding';
    $parameters['vectorDimensions'] = 768;
    $parameters['vectorSimilarity'] = 'cosine';
    $parameters['retrievalMode'] = 'lexical';
    $parameters['minimumSimilarity'] = 0.35;
    $parameters['embeddingProvider'] = $this->queryEmbeddings;
    $parameters['documentMapper'] = $this->documents->map(...);
    $this->setAdapterParameters($parameters);
}
```

This is a method sketch: inject the application's query-embedding and document-projection
services. Register the search with `#[AsSearch(index: Record::class, name: 'catalog_search',
adapter: 'es')]` and implement `getFieldClass()` for a field-driven search. Give an explicit
search a distinct name from auto-generated entity searches; do not assume a duplicate name
replaces the auto-generated service. Route/render the explicit name you registered.

The provider implements `Contract\EmbeddingProviderInterface::embed(string): array`, or is
a callable returning query-vector floats. `queryVector` may instead be a fixed vector or
callable. PostgreSQL/DuckDB use the separate `QueryEmbedderInterface::embedQuery()` contract;
implement both methods or bridge them when sharing a provider. They are not interchangeable
configuration keys. Cosine configuration plus a query provider enables the ES semantic and
hybrid tabs; this capability check does not verify index coverage or provider availability.

The mapper must return the ordinary indexed document **and** an `embedding` containing the
valid persisted vector. Do not put vectors or fingerprints in `sourceFields`: that option
controls returned hit data. For cached vectors, the default document mapper is not enough.
Validate dimensions, finite values, nonzero norm, and the content/model fingerprint before
copying a vector. Return `embedding: null` when stale, so an incremental upsert clears an old
value. Regenerate embeddings separately, then reindex.

`embeddingText` is retained as an adapter option but does not generate document embeddings.
`vectorDimensions` does not create a dense-vector mapping or a Doctrine column. JSON/JSONB
columns are otherwise opt-in in field-derived ES mappings; do not mark an embedding array
as a normal searchable/facet field. Keep it out of inferred admin fields as well.

Generic fields still drive keyword search and facets. Currently `FieldSearchConfigurator`
skips descriptors with `visible: false`; explicitly include hidden-but-searchable content
in the search configuration when needed. Computed properties also need a matching ES mapping
and document projection. Text facets/sorts normally use `.keyword`; array-valued facets
need a keyword mapping and scalar array values, not arbitrary JSON objects.

## Generate once, index repeatedly

Use `Search\EmbeddingProfile(model, dimensions, revision, textVersion)` to fingerprint
normalized source text and its embedding profile. Consumers own generation, storage,
resume/retry behavior, and query caching. Document and query vectors must share compatible
models/revisions and preprocessing; model-specific query/document prefixes may differ.
Equal vector dimensions alone do not establish compatibility.

Persist vectors outside Elasticsearch if reindexing should not call the model. PostgreSQL
JSONB is one possible cache; PostgreSQL's native pgvector type is appropriate when SQL also
needs to query vectors. SearchBundle does not migrate one representation into another.

With `survos/elastic-bundle` installed, rebuild a registered search code:

```bash
# First run the consumer's embedding-generation job (there is no universal command).
bin/console elastic:index:rebuild <search-code> --keep-old --batch-size=250
```

This builds a generation and swaps its alias. `survos:search:index` skips external engines.
ElasticBundle streams Doctrine entities unless an explicit `documentProvider` supplies the
records. Changing vector dimensions requires a new compatible index and regenerated vectors.

## UI modes versus adapter retrieval defaults

Render the registered search in `Survos:Search:Layout` with `urlRewriting: true` on dedicated
search pages. Facets are shared across tabs. Keyword input searches as typed; semantic and
hybrid descriptions run on submission. Merely opening a description tab does not execute it.

| API | Values | Meaning |
|---|---|---|
| Adapter `retrievalMode` | `lexical`, `vector`, `hybrid` | Default/explicit low-level retrieval; `vector` is approximate top-k |
| `Query::searchMode` | `lexical`, `semantic`, `hybrid` | User's submitted tab; non-lexical modes override the adapter default |

Set `retrievalMode: lexical` for an honest Lexical tab. `semantic` is **not** an accepted
`retrievalMode` value. Programmatic semantic requests use:

```php
$search = $searchProvider->getSearch('catalog_search')->create();
$query = $search->createQuery()
    ->setSearchMode('semantic')
    ->setQueryString('friendship and belonging')
    ->setMinimumSimilarity(0.6);
$results = $searcher->search($query, $search);
```

For hybrid, call `setSearchMode('hybrid')->setSemanticCandidates(50)`. Add normal term/range
filters to the same Query. `minimumSimilarity` is cosine in [-1, 1], not a probability.
ES defaults to 0.35; the UI's candidate limit defaults to 50 and accepts 1–1000. Tune both
for the corpus. Hybrid's cutoff constrains only its semantic branch, not keyword matches.
An empty query browses using lexical behavior without calling the embedder.

URL state includes `query`, `searchMode`, `minimumSimilarity`, `semanticCandidates` (hybrid),
page, page size, sort, and facets. These tabs are for the LiveComponent UI; the separate
InstantSearch HTTP/browser transport is not automatically upgraded.

## Counts, ranking, scores, and limits

- **Lexical:** keyword relevance, with optional explicit field sorting. Facets exclude
  their own active filter while retaining text, base scope, and other filters.
- **Semantic:** exact cosine `script_score`, filtered by its cutoff before pagination and
  aggregation. Same self-excluding facet behavior. It scans vectors in the base scope;
  selected facets do not necessarily reduce the scoring workload. Benchmark large corpora.
  Missing vectors are excluded. Semantic results rank by similarity, not the lexical sort.
- **Hybrid:** lexical and approximate kNN candidates fused with RRF. The ES adapter tries
  native RRF, then falls back on the specific RRF license error. Basic-license fallback
  ranks a bounded candidate union, paginates it, and counts/aggregates that union. Facets
  remain constrained by active filters; this is not the semantic tab's full-corpus,
  self-excluding count behavior. Native ES aggregation behavior is engine-defined; do not
  assume identical totals/facet populations between implementations. The fallback's window
  is at least `rankWindowSize` (default 100) or the requested semantic candidate count.

Score scales differ: lexical relevance has no fixed probability range; semantic raw score
is `cosine + 1`; cosine kNN uses ES's vector scoring scale; hybrid is an RRF rank score.
Do not compare raw scores across modes or label them as confidence percentages.
Custom hit templates receive `hit`, `score`, and `hitMetadata`, for example:

```twig
<h3>{{ hit.title }}</h3>
<p>Score: {{ score|number_format(6) }}</p>
{% if hitMetadata.mode|default('') == 'semantic' %}
    <p>Cosine similarity: {{ (score - 1)|number_format(4) }}</p>
{% endif %}
```

Backend diagnostics include mode, timings, shard status, and available RRF implementation
or branch-rank details. Consult [Elastic's script-score documentation](https://www.elastic.co/docs/reference/query-languages/query-dsl/query-dsl-script-score-query)
for exact cutoff semantics and engine restrictions. No natural-language extraction of
structured facet values is performed; users select facets explicitly.

## Consumer verification

Before adopting the integration, verify these against the consumer's actual index:

1. Query and document model profiles match, dimensions are correct, and stale vectors are excluded.
2. A second generation job reuses persisted vectors; an index rebuild does not call the model.
3. A known keyword and a meaning-based query return expected records with labeled scores.
4. Raising the semantic cutoff reduces the matching set; selecting a facet narrows it further.
5. All three tabs, facet changes, URL reloads, and pagination work through live browser requests.
6. Native or fallback hybrid behavior is identified, and its bounded counts are acceptable.
