<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Adapter\SqliteFts5;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Survos\SearchBundle\Adapter\SqliteFts5\SqliteFts5Adapter;
use Survos\SearchBundle\Search\AbstractSearch;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A MATCH must drive the hit and count queries, whatever else the WHERE holds.
 *
 * With a scope on the row table (Ink scopes a paper's search to its article core), SQLite drove
 * from the core_id index and probed FTS once per row: 45 s for a two-word count on a 966k-row
 * folio, and inkstory.org's search 502'd (2026-09-25). FTS-first it took 0.00 s.
 */
final class SqliteFts5AdapterJoinOrderTest extends TestCase
{
    private Connection $connection;

    /** @var list<array{sql: string, params: array<mixed>}> statements the adapter ran, as DBAL logged them */
    private array $statements = [];

    protected function setUp(): void
    {
        $logger = new class($this->statements) extends AbstractLogger {
            public function __construct(private array &$sink) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    $this->sink[] = ['sql' => $context['sql'], 'params' => $context['params'] ?? []];
                }
            }
        };
        $config = (new \Doctrine\DBAL\Configuration())->setMiddlewares([new Middleware($logger)]);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->connection->executeStatement('CREATE TABLE item (id TEXT PRIMARY KEY, core_id TEXT NOT NULL, title TEXT)');
        $this->connection->executeStatement('CREATE INDEX idx_item_core ON item (core_id)');
        $this->connection->executeStatement("CREATE VIRTUAL TABLE item_fts USING fts5(title, content='item', content_rowid='rowid')");
        foreach (['snap bean crop acreage', 'county fair results', 'snap bean prices', 'school board meeting'] as $i => $title) {
            $this->connection->insert('item', ['id' => "r$i", 'core_id' => $i === 3 ? 'other' : 'article', 'title' => $title]);
        }
        $this->connection->executeStatement("INSERT INTO item_fts(item_fts) VALUES ('rebuild')");
    }

    public function testMatchWithARowScopeRunsFtsFirstAndStillFindsTheRows(): void
    {
        $search = new class extends AbstractSearch {
            public function build(array $options = []): void {}
        };
        $search->setAdapterParameters([
            'table' => 'item',
            'ftsTable' => 'item_fts',
            'selectColumns' => ['d.id', 'd.title'],
            'where' => "d.core_id = 'article'",
        ]);
        $adapter = new SqliteFts5Adapter($this->connection);
        $resolver = new OptionsResolver();
        $adapter->configureParameters($resolver);
        $search->setResolvedAdapterParameters($resolver->resolve($search->getAdapterParameters()));

        $query = $search->createQuery();
        $query->setQueryString('snap bean');
        $result = $adapter->search($query, $search);

        self::assertSame(2, $result->getTotalResults());
        self::assertEqualsCanonicalizing(['r0', 'r2'], array_map(fn ($h) => $h->getData()['id'], $result->getHits()));

        // Every hit/count statement must name the FTS table first and pin the order with CROSS JOIN.
        $matching = array_values(array_filter($this->statements, fn (array $s) => str_contains($s['sql'], 'MATCH') && !str_contains($s['sql'], '__fts')));
        self::assertNotEmpty($matching);
        foreach ($matching as ['sql' => $sql, 'params' => $params]) {
            self::assertMatchesRegularExpression('/FROM\s+"?item_fts"?\s+f\s+CROSS JOIN\s+"?item"?\s+d/i', $sql);
            $plan = $this->connection->fetchAllAssociative('EXPLAIN QUERY PLAN ' . $sql, $params);
            self::assertStringContainsString('VIRTUAL TABLE', $plan[0]['detail'], 'the FTS table must be the outer loop: ' . json_encode($plan));
        }
    }

    /** @param array<string, mixed> $parameters */
    private function searchWithout(array $parameters, string $text): \Survos\SearchBundle\Search\ResultSet\ResultSet
    {
        $search = new class extends AbstractSearch {
            public function build(array $options = []): void {}
        };
        $search->setAdapterParameters(['table' => 'item', 'selectColumns' => ['d.id', 'd.title']] + $parameters);
        $adapter = new SqliteFts5Adapter($this->connection);
        $resolver = new OptionsResolver();
        $adapter->configureParameters($resolver);
        $search->setResolvedAdapterParameters($resolver->resolve($search->getAdapterParameters()));
        $query = $search->createQuery();
        $query->setQueryString($text);

        return $adapter->search($query, $search);
    }

    /** A folio whose FTS index is missing (skipped, or a build that died) still answers text queries. */
    public function testWithoutAnFtsTableATextQueryFallsBackToTheConfiguredColumns(): void
    {
        $this->connection->executeStatement('DROP TABLE item_fts');
        $this->statements = [];

        $result = $this->searchWithout(['ftsTable' => null, 'textFallbackColumns' => ['d.title']], 'SNAP bean');

        self::assertSame(2, $result->getTotalResults());
        self::assertEqualsCanonicalizing(['r0', 'r2'], array_map(fn ($h) => $h->getData()['id'], $result->getHits()));
        foreach ($this->statements as ['sql' => $sql]) {
            self::assertStringNotContainsString('item_fts', $sql);
        }
    }

    /** With nowhere to look, say "nothing matched" — never return every row unfiltered. */
    public function testWithoutAnFtsTableOrFallbackATextQueryMatchesNothing(): void
    {
        $this->connection->executeStatement('DROP TABLE item_fts');

        self::assertSame(0, $this->searchWithout(['ftsTable' => null], 'snap')->getTotalResults());
        self::assertSame(4, $this->searchWithout(['ftsTable' => null], '')->getTotalResults());
    }
}
