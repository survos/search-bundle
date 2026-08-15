# Survos Search Bundle

Reusable field-driven search for Symfony applications. SearchBundle owns the complete query/result kernel, faceted Live Component UI, AssetMapper-ready JavaScript and CSS, field-driven configuration, and portable search adapters.

Applications install one package and can choose Doctrine, Meilisearch, Algolia, SQLite FTS5, PostgreSQL BM25, or Elasticsearch without duplicating UI infrastructure.

## Search From Fields

Create a search class and point it at an entity or DTO class with `#[Field]` metadata:

```php
use Survos\FolioBundle\Entity\Row;
use Survos\SearchBundle\Search\AbstractFieldSearch;
use Survos\SearchBundle\Attribute\AsSearch;

#[AsSearch(index: Row::class, adapter: 'folio_fts')]
final class FolioRowSearch extends AbstractFieldSearch
{
    protected function getFieldClass(array $options = []): string
    {
        return Row::class;
    }

    public function build(array $options = []): void
    {
        parent::build($options);

        $this->setAdapterParameters([
            'table' => 'item',
            'ftsTable' => 'item_fts',
            'idColumn' => 'id',
            'labelColumn' => 'label',
            'contentColumns' => ['label', 'dto_data', 'extras'],
            'where' => 'core_id = :core',
            'params' => ['core' => $options['coreId']],
        ]);
    }
}
```

Render the first-party component:

```twig
<twig:Survos:Search:Layout name="folio_row" :options="{ coreId: core.id }"/>
```

## SQLite FTS5

Configure the SearchBundle adapter:

```yaml
survos_search:
    default_adapter: folio_fts
    adapters:
        folio_fts: 'sqlite-fts5://folio'
```

The DSN host is the Doctrine connection name. The adapter uses DBAL and SQLite FTS5:

- `MATCH` for full-text filtering
- `bm25(fts_table)` for score
- optional facet counts through normal SQL aggregation

Applications remain responsible for creating and maintaining the FTS5 virtual table. That is deliberate: folios, entities, and denormalized JSON payloads need different indexing strategies.

## PostgreSQL BM25

Configure:

```yaml
survos_search:
    adapters:
        pg_bm25: 'postgres-bm25://default'
```

The first target is `pg_textsearch`, which provides PostgreSQL BM25 indexes. This bundle keeps the SQL configurable because `pg_textsearch` and ParadeDB `pg_search` expose different operators.

## Elasticsearch 9

Installations can add Elasticsearch as another UX Search adapter without
coupling application code to the official client:

```yaml
# config/packages/survos_search.yaml
survos_search:
    default_adapter: elastic
    adapters:
        elastic: '%env(ELASTICSEARCH_DSN)%'
```

```dotenv
ELASTICSEARCH_DSN=elasticsearch://127.0.0.1:9200
```

Supported DSNs are:

- `elasticsearch://host:port` for HTTP
- `elasticsearch+https://host:port` for HTTPS
- basic authentication in the URL
- `?api_key=...` for API-key authentication

The adapter uses `elasticsearch/elasticsearch:^9.0`, Elastic's official PHP
client. Connections are created lazily by the adapter factory. The indexing
command pings the backend and reports a useful failure before attempting index
work.

An explicit search can control document shape and retrieval:

```php
public function build(array $options = []): void
{
    $this->setAdapterParameters([
        'index' => 'packages',
        'idField' => 'id',
        'searchFields' => ['name^3', 'description', 'searchText'],
        'sourceFields' => ['id', 'name', 'description', 'keywords'],
        'facetFields' => ['vendor' => 'vendor', 'keywords' => 'keywords'],
        'sortFields' => ['name' => 'name.keyword'],
        'mappings' => [
            'id' => ['type' => 'keyword'],
            'name' => [
                'type' => 'text',
                'fields' => ['keyword' => ['type' => 'keyword']],
            ],
            'description' => ['type' => 'text'],
            'keywords' => ['type' => 'keyword'],
            'searchText' => ['type' => 'text'],
        ],
        'retrievalMode' => $options['retrievalMode'] ?? 'lexical',
        'vectorField' => 'embedding',
        'vectorDimensions' => 1536,
        'embeddingProvider' => $this->embeddingProvider,
        'embeddingText' => static fn (array $document): string =>
            implode("\n", array_filter([
                $document['name'] ?? null,
                $document['description'] ?? null,
                $document['searchText'] ?? null,
            ])),
    ]);
}
```

`embeddingProvider` may implement
`Survos\SearchBundle\Contract\EmbeddingProviderInterface` or be a callable
that maps text to a list of floats. Applications choose the embedding model;
SearchBundle only stores and queries the vectors. `queryVector` can instead be
an explicit vector or callable for experiments and tests.

Create/recreate and bulk-populate registered searches with:

```bash
bin/console survos:search:index packages --drop --batch-size=250
bin/console survos:search:index packages --limit=25
```

The command uses an explicit `documentProvider`/`documentMapper` when
configured. Otherwise, automatic entity searches stream Doctrine records and
derive JSON-safe documents from their mapped fields. Dates, enums, nested
arrays, and stringable values are normalized before bulk indexing.

### Retrieval modes

- `lexical`: Elasticsearch multi-match/BM25
- `vector`: indexed `dense_vector` kNN retrieval
- `hybrid`: lexical plus vector retrieval fused with reciprocal rank fusion

The adapter first tries Elasticsearch's native RRF retriever. Native RRF is a
paid Elastic feature and returns HTTP 403 on the free self-managed license. In
that specific case the adapter automatically performs the two searches and
applies the same RRF formula client-side. Result-set metadata reports
`hybridImplementation` as `native_rrf` or `client_rrf`.

Every result set exposes backend-neutral diagnostic metadata including engine,
mode, elapsed Elasticsearch time, shard status, and hybrid implementation.
Each hit preserves its score plus optional highlight, explanation, index, and
per-retriever RRF rank details.

## Folio Direction

Do not add the search code to `folio-bundle`. Folio should consume this bundle by:

1. Creating/maintaining FTS5 tables next to folio SQLite tables.
2. Marking searchable/filterable folio row fields with `#[Field]` on DTOs or search-facing row models.
3. Creating small search classes that bind folio context (`core_id`, `dto_type`) into adapter parameters.

## Credits

The UX layer is derived from and inspired by [Mezcalito UX Search](https://github.com/Mezcalito/ux-search), released under the MIT license. SearchBundle retains attribution in the imported source and now maintains the integrated implementation under the `Survos\SearchBundle` namespace.
