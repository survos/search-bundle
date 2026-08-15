# Create Your Own Adapter

This guide explains how to create a custom adapter for SearchBundle. Before starting, read [adapters.md](../adapters.md) — it covers where an adapter belongs depending on whether it owns its index or only reads one someone else owns.

## When to Create a Custom Adapter

Create a custom adapter when you need to integrate with:
- External REST APIs
- Search engines not yet supported (Elasticsearch, Solr, etc.)
- Custom data sources (CSV, XML, external databases)
- Third-party search services

## Understanding the Architecture

An adapter consists of two parts:
1. **Adapter** (implements `AdapterInterface`) - Performs the actual search
2. **Factory** (implements `AdapterFactoryInterface`) - Creates adapter instances from DSN strings

### The Search Flow

1. User submits search query
2. Bundle calls `factory->createAdapter($dsn)` to get adapter instance
3. Bundle calls `adapter->search($query, $search)` with search parameters
4. Adapter returns `ResultSet` with hits, facets, and pagination data
5. Bundle renders results in Twig components

## Step-by-Step Implementation

### Step 1: Create the Adapter Class

The adapter implements `AdapterInterface` with two methods:

```php
<?php

declare(strict_types=1);

namespace App\Search\Adapter;

use Survos\SearchBundle\Adapter\AdapterInterface;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Survos\SearchBundle\Search\SearchInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final readonly class MyAdapter implements AdapterInterface
{
    public function search(Query $query, SearchInterface $search): ResultSet
    {
        // Your logic need to return a ResultSet
    }

    public function configureParameters(OptionsResolver $resolver): void
    {
        // Define available parameters that users can set via setAdapterParameters()
        $resolver->setDefaults([
            'my_param' => 'my_value',
        ]);

        $resolver->setAllowedTypes('my_param', 'string');
    }
}
```

### Step 2: Create the Factory Class

The factory creates adapter instances from DSN strings:

```php
<?php

declare(strict_types=1);

namespace App\Search\Adapter;

use Survos\SearchBundle\Adapter\AdapterFactoryInterface;
use Survos\SearchBundle\Adapter\AdapterInterface;

final readonly class MyAdapterFactory implements AdapterFactoryInterface
{
    public function support(string $dsn): bool
    {
        return str_starts_with($dsn, 'myAdapter'); // Or your own logic
    }

    public function createAdapter(string $dsn): AdapterInterface
    {
        // Your own logic

        return new MyAdapter();
    }
}
```

### Step 3: Configure the Bundle

Add your adapter to the configuration:

```yaml
# config/packages/survos_search.yaml
survos_search:
    default_adapter: 'myAdapter'
    adapters:
        myAdapter: 'myAdapter://what_you_need'
```

### Step 4: Use the Adapter in a Search

```php
<?php

namespace App\Search;

use App\Search\Adapter\RestApiAdapter;
use Survos\SearchBundle\Attribute\AsSearch;
use Survos\SearchBundle\Search\AbstractSearch;

#[AsSearch('products', adapter: 'my_api')]
class ProductSearch extends AbstractSearch
{
    public function build(array $options = []): void
    {
        // Your logic
    }
}
```

## Required Data Structures

### Hit

Represents a single search result:

```php
use Survos\SearchBundle\Search\ResultSet\Hit;

$hit = new Hit(
    data: ['id' => 1, 'name' => 'Product', 'price' => 29.99],
    score: 0.95
);
```

### FacetTermDistribution

For facets with discrete values (brands, categories, etc.):

```php
use Survos\SearchBundle\Search\ResultSet\FacetTermDistribution;

$facet = new FacetTermDistribution();
$facet->setProperty('brand');
$facet->setValues([
    'Nike' => 45,      // 45 products
    'Adidas' => 32,    // 32 products
    'Puma' => 18,      // 18 products
]);
$facet->setCheckedValues(['Nike']); // User selected Nike
```

### FacetStat

For numeric range facets (price, rating, etc.):

```php
use Survos\SearchBundle\Search\ResultSet\FacetStat;

$stat = new FacetStat(
    property: 'price',
    min: 0,           // Minimum value in dataset
    max: 999,         // Maximum value in dataset
    userMin: 10,      // User selected minimum (optional)
    userMax: 500,     // User selected maximum (optional)
);
```

### ResultSet

The complete search result:

```php
use Survos\SearchBundle\Search\ResultSet\ResultSet;

$resultSet = new ResultSet();
$resultSet->setTotalResults(150);
$resultSet->setHits($hits);
$resultSet->setFacetDistributions($facetDistributions);
$resultSet->setFacetStats($facetStats);
```

## DSN Format Guidelines

Use a clear, consistent DSN format:

```
scheme://credentials@host:port/path?options
```

Examples:
- `elasticsearch://user:pass@localhost:9200/myindex`
- `solr://admin:secret@solr.example.com:8983/solr/products`
- `csv://path/to/file.csv`
- `api://apikey@api.example.com/v1`

## Need Help?

- Check existing adapter implementations in `src/Adapter/`
- Ask questions in [GitHub Issues](https://github.com/survos/search-bundle/issues)
- See [adapters.md](../adapters.md) for the index-ownership rule that decides how your
  adapter is wired into `survos:search:index`
