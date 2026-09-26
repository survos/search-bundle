<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Adapter\SqliteFts5;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Survos\SearchBundle\Adapter\SqliteFts5\SqliteFts5Adapter;
use Survos\SearchBundle\Search\AbstractSearch;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * `liveFacets: false` — the size gate a folio too large to facet live sets.
 *
 * Facet counts are aggregated over the rows a query matches, so a text query cannot read them from
 * a precomputed table. On news/rappnews4909 (966,590 rows) that aggregation is ~5 s of a ~6 s
 * first-seen query. Gated, a text query returns its hits and no counts; a filter-only query, which
 * the precomputed path does answer, keeps them.
 */
final class SqliteFts5AdapterLiveFacetsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE item (id TEXT PRIMARY KEY, core_id TEXT NOT NULL, title TEXT)');
        $this->connection->executeStatement("CREATE VIRTUAL TABLE item_fts USING fts5(title, content='item', content_rowid='rowid')");
        foreach (['snap bean crop acreage', 'snap bean prices', 'school board meeting'] as $i => $title) {
            $this->connection->insert('item', ['id' => "r$i", 'core_id' => $i === 2 ? 'other' : 'article', 'title' => $title]);
        }
        $this->connection->executeStatement("INSERT INTO item_fts(item_fts) VALUES ('rebuild')");
    }

    public function testATextQueryOnAGatedTableReturnsHitsAndNoFacetCounts(): void
    {
        $result = $this->search('snap bean', liveFacets: false);

        self::assertSame(2, $result->getTotalResults());
        // The facet is still there — an absent distribution throws "Facet distribution ... is not
        // found" in the template — it just carries no numbers.
        self::assertArrayHasKey('core', $result->getFacetDistributions());
        self::assertSame([], $result->getFacetDistributions()['core']->getValues());
    }

    public function testAFilterOnlyQueryKeepsItsFacetCounts(): void
    {
        $result = $this->search('', liveFacets: false);

        self::assertSame(['article' => 2, 'other' => 1], $result->getFacetDistributions()['core']->getValues());
    }

    public function testUngatedIsUnchanged(): void
    {
        $result = $this->search('snap bean', liveFacets: true);

        self::assertSame(['article' => 2], $result->getFacetDistributions()['core']->getValues());
    }

    private function search(string $queryString, bool $liveFacets): \Survos\SearchBundle\Search\ResultSet\ResultSet
    {
        $search = new class extends AbstractSearch {
            public function build(array $options = []): void
            {
                $this->addFacet('core', 'Core');
            }
        };
        $search->build();
        $search->setAdapterParameters([
            'table' => 'item',
            'ftsTable' => 'item_fts',
            'selectColumns' => ['d.id', 'd.title'],
            'facetColumns' => ['core' => 'd.core_id'],
            'liveFacets' => $liveFacets,
        ]);
        $adapter = new SqliteFts5Adapter($this->connection);
        $resolver = new OptionsResolver();
        $adapter->configureParameters($resolver);
        $search->setResolvedAdapterParameters($resolver->resolve($search->getAdapterParameters()));

        $query = $search->createQuery();
        $query->setQueryString($queryString);

        return $adapter->search($query, $search);
    }
}
