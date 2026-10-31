<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Adapter\PostgresBm25;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Survos\SearchBundle\Adapter\PostgresBm25\PostgresBm25Adapter;
use Survos\SearchBundle\Contract\QueryEmbedderInterface;
use Survos\SearchBundle\Search\AbstractSearch;
use Survos\SearchBundle\Search\Filter\RangeFilter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Survos\SearchBundle\Twig\Components\Facet\RangeInput;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PostgresVectorTest extends TestCase
{
    private Connection $db;
    private PostgresBm25Adapter $adapter;
    private QueryEmbedderInterface $embedder;

    protected function setUp(): void
    {
        $url = getenv('POSTGRES_VECTOR_TEST_URL');
        if (!$url) {
            self::markTestSkipped('Set POSTGRES_VECTOR_TEST_URL to a PostgreSQL database with pgvector.');
        }
        $this->db = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        $this->db->executeStatement('CREATE TEMPORARY TABLE vector_works (id TEXT PRIMARY KEY, title TEXT, kind TEXT, year INTEGER, tenant TEXT, embedding vector(2))');
        $this->db->executeStatement("INSERT INTO vector_works VALUES ('1','red tree','painting',1900,'a','[1,0]'), ('2','blue tree','painting',1950,'a','[0.8,0.6]'), ('3','red stone','sculpture',2000,'a','[0.6,0.8]'), ('4','bare','sculpture',NULL,'a','[-1,0]'), ('5','outside','painting',1800,'b','[1,0]'), ('6','no vector','painting',1980,'a',NULL), ('7','tied','painting',1910,'a','[1,0]')");
        $this->adapter = new PostgresBm25Adapter($this->db, new ArrayAdapter());
        $this->embedder = new class implements QueryEmbedderInterface {
            public int $calls = 0;

            public function embedQuery(string $text): array
            {
                ++$this->calls;

                return [1.0, 0.0];
            }
        };
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    private function search(Query $query, array $options = []): ResultSet
    {
        $search = new class extends AbstractSearch {
            public function build(array $options = []): void
            {
                $this->addFacet('kind', 'Kind')->addFacet('year', 'Year', RangeInput::class);
            }
        };
        $search->build();
        $resolver = new OptionsResolver();
        $this->adapter->configureParameters($resolver);
        $search->setResolvedAdapterParameters($resolver->resolve($options + [
            'table' => 'vector_works', 'where' => 'd.tenant = :tenant', 'params' => ['tenant' => 'a'],
            'selectColumns' => ['d.id', 'd.title'], 'facetColumns' => ['kind' => 'd.kind', 'year' => 'd.year'],
            'sortColumns' => ['year' => 'd.year'], 'matchExpression' => 'd.title ILIKE :bm25Query', 'scoreExpression' => '1',
            'vectorColumn' => 'd.embedding', 'vectorDimensions' => 2, 'minimumSimilarity' => 0.5, 'queryEmbedder' => $this->embedder,
        ]));

        return $this->adapter->search($query, $search);
    }

    public function testCutoffFiltersCountsFacetsAndStablePagination(): void
    {
        $q = (new Query())->setSearchMode('semantic')->setQueryString('tech gifts')->setActiveHitsPerPage(1)
            ->addActiveFilter(new RangeFilter('year', 1900, 1970))->addActiveFilter(new TermFilter('kind', ['painting']));
        $first = $this->search($q);
        self::assertSame(1, $this->embedder->calls, 'Embed once per request, not again for every facet.');
        $second = $this->search((clone $q)->setCurrentPage(2));
        self::assertSame(3, $first->getTotalResults());
        self::assertSame(3, $second->getTotalResults());
        self::assertSame('1', $first->getHits()[0]->getData()['id']);
        self::assertSame('7', $second->getHits()[0]->getData()['id'], 'Equal scores must paginate by stable ID.');
        self::assertSame(['painting' => 3], $second->getFacetDistributions()['kind']->getValues());
        self::assertSame(1950.0, $second->getFacetStats()['year']->getMax());
        self::assertSame(2, $this->search((clone $q)->setMinimumSimilarity(0.9))->getTotalResults());
        self::assertSame(0, $this->search((clone $q)->setMinimumSimilarity(0.9)->addActiveFilter(new RangeFilter('year', 1920)))->getTotalResults());
        self::assertSame(4, $this->search((clone $q)->setActiveFilters([]))->getTotalResults());
        self::assertCount(0, $this->search((clone $q)->setCurrentPage(4))->getHits());
        self::assertSame('2', $this->search((clone $q)->setActiveSort('year:desc'))->getHits()[0]->getData()['id']);
        self::assertSame(6, $this->search((clone $q)->setActiveFilters([])->setQueryString(''))->getTotalResults());
        self::assertSame(0, $this->search((clone $q)->setSearchMode('lexical'))->getTotalResults());
    }

    public function testFacetSelfExclusionKeepsSemanticCutoff(): void
    {
        $q = (new Query())->setSearchMode('semantic')->setQueryString('objects')->addActiveFilter(new TermFilter('kind', ['sculpture']));
        $r = $this->search($q);
        self::assertSame(1, $r->getTotalResults());
        self::assertSame(['painting' => 3, 'sculpture' => 1], $r->getFacetDistributions()['kind']->getValues());
        self::assertSame(['sculpture'], $r->getFacetDistributions()['kind']->getCheckedValues());
        $high = $this->search((clone $q)->setMinimumSimilarity(0.9));
        self::assertSame(0, $high->getTotalResults());
        self::assertSame(['painting' => 2], $high->getFacetDistributions()['kind']->getValues());
    }

    public function testMissingCapabilityIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->search((new Query())->setSearchMode('semantic')->setQueryString('gifts'), ['queryEmbedder' => null]);
    }

    public function testMismatchedDimensionsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->search((new Query())->setSearchMode('semantic')->setQueryString('gifts'), ['vectorDimensions' => 3]);
    }
}
