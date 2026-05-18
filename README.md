# Survos Search Bundle

Reusable field-driven search for Symfony applications.

This bundle is intentionally a bridge:

- `survos/field-bundle` remains the source of truth for field metadata.
- `mezcalito/ux-search` provides the Live Component UI, query state, facets, pagination, and result rendering.
- `survos/search-bundle` adds field-driven configuration and database-native BM25 adapters.

## Why not `ux-search-extras`?

The bundle is not only an extension pack for Mezcalito UX Search. The center of gravity is Survos field metadata and portable search backends. `survos/search-bundle` leaves room for API/Grid/Meili bridges without implying that every feature is tied to Mezcalito internals.

## Search From Fields

Create a search class and point it at an entity or DTO class with `#[Field]` metadata:

```php
use Survos\FolioBundle\Entity\Row;
use Survos\SearchBundle\Search\AbstractFieldSearch;
use Mezcalito\UxSearchBundle\Attribute\AsSearch;

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

Then render with Mezcalito's component:

```twig
<twig:Mezcalito:UxSearch:Layout name="folio_row" :options="{ coreId: core.id }"/>
```

## SQLite FTS5

Configure Mezcalito UX Search with this bundle's adapter:

```yaml
mezcalito_ux_search:
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
mezcalito_ux_search:
    adapters:
        pg_bm25: 'postgres-bm25://default'
```

The first target is `pg_textsearch`, which provides PostgreSQL BM25 indexes. This bundle keeps the SQL configurable because `pg_textsearch` and ParadeDB `pg_search` expose different operators.

## Folio Direction

Do not add the search code to `folio-bundle`. Folio should consume this bundle by:

1. Creating/maintaining FTS5 tables next to folio SQLite tables.
2. Marking searchable/filterable folio row fields with `#[Field]` on DTOs or search-facing row models.
3. Creating small search classes that bind folio context (`core_id`, `dto_type`) into adapter parameters.
