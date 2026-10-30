<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Adapter\DuckDb;

use PHPUnit\Framework\TestCase;
use Survos\SearchBundle\Adapter\DuckDb\DuckDbAdapter;
use Survos\SearchBundle\Search\AbstractSearch;
use Survos\SearchBundle\Search\Filter\RangeFilter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DuckDbAdapterTest extends TestCase
{
    private \PDO $db;
    private ?\Survos\SearchBundle\Profiler\DuckDbQueryRecorder $recorder = null;

    protected function setUp(): void
    {
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Native PDO DuckDB required.');
        }
        $this->db = new \PDO('duckdb::memory:');
        $this->db->exec('CREATE TABLE works (id VARCHAR, title VARCHAR, kind VARCHAR, year BIGINT, tags VARCHAR[], tenant VARCHAR)');
        $this->db->exec("INSERT INTO works VALUES ('1','red tree','painting',1900,['red','red','nature'],'a'), ('2','blue tree','painting',1950,['blue','nature'],'a'), ('3','red stone','sculpture',2000,['red'],'a'), ('4','bare','sculpture',NULL,NULL,'a'), ('5','outside','painting',1800,['red'],'b')");
    }

    private function search(Query $query, array $options = []): ResultSet
    {
        $search = new class extends AbstractSearch {
            public function build(array $options = []): void
            {
                $this->addFacet('kind', 'Kind')->addFacet('tags', 'Tags')->addFacet('year', 'Year');
            }
        };
        $search->build();
        $adapter = new DuckDbAdapter($this->db, $this->recorder);
        $resolver = new OptionsResolver();
        $adapter->configureParameters($resolver);
        $search->setResolvedAdapterParameters($resolver->resolve($options + [
            'table' => 'works', 'where' => 'tenant = ?', 'params' => ['a'],
            'selectColumns' => ['d.id', 'd.title'], 'facetColumns' => ['kind' => 'd.kind', 'tags' => 'd.tags', 'year' => 'd.year'],
            'listFacets' => ['tags'], 'rangeFacets' => ['year'], 'sortColumns' => ['newest' => 'd.year DESC NULLS LAST'],
            'textColumns' => ['d.title'],
        ]));

        return $adapter->search($query, $search);
    }

    public function testFullResultFacetsNotPageCountsAndListDuplicatesCountOnce(): void
    {
        $result = $this->search((new Query())->setActiveSort('')->setActiveHitsPerPage(1)->setCurrentPage(2));
        self::assertSame(4, $result->getTotalResults());
        self::assertCount(1, $result->getHits());
        self::assertSame(['painting' => 2, 'sculpture' => 2], $result->getFacetDistributions()['kind']->getValues());
        self::assertSame(['nature' => 2, 'red' => 2, 'blue' => 1], $result->getFacetDistributions()['tags']->getValues());
        self::assertSame(1900, $result->getFacetStats()['year']->getMin());
        self::assertSame(2000, $result->getFacetStats()['year']->getMax());
    }

    public function testOrWithinFacetAndAcrossFacetsWithSelfExcludingCounts(): void
    {
        $result = $this->search((new Query())->addActiveFilter(new TermFilter('tags', ['red', 'blue']))->addActiveFilter(new TermFilter('kind', ['painting'])));
        self::assertSame(2, $result->getTotalResults());
        self::assertSame(['painting' => 2, 'sculpture' => 1], $result->getFacetDistributions()['kind']->getValues());
        self::assertSame(['nature' => 2, 'blue' => 1, 'red' => 1], $result->getFacetDistributions()['tags']->getValues());
        self::assertSame(['red', 'blue'], $result->getFacetDistributions()['tags']->getCheckedValues());
    }

    public function testTextRangeAndPreparedValues(): void
    {
        $result = $this->search((new Query())->setQueryString('tree')->addActiveFilter(new RangeFilter('year', 1920)));
        self::assertSame(1, $result->getTotalResults());
        self::assertSame(['blue' => 1, 'nature' => 1], $result->getFacetDistributions()['tags']->getValues());
        self::assertSame(1900, $result->getFacetStats()['year']->getMin());
        self::assertSame(0, $this->search((new Query())->setQueryString("' OR true --"))->getTotalResults());
        self::assertSame(0, $this->search((new Query())->setQueryString('%'))->getTotalResults());
    }

    public function testFtsRankingAndFacetsUseTheSameMatches(): void
    {
        try {
            $this->db->exec('LOAD fts');
        } catch (\PDOException) {
            self::markTestSkipped('Install the optional DuckDB fts extension to run this test.');
        }
        $this->db->exec("PRAGMA create_fts_index('works', 'id', 'title')");
        $result = $this->search((new Query())->setQueryString('tree'), ['ftsFunction' => 'fts_main_works.match_bm25']);
        self::assertSame(2, $result->getTotalResults());
        self::assertSame(['painting' => 2], $result->getFacetDistributions()['kind']->getValues());
        self::assertSame(['nature' => 2, 'blue' => 1, 'red' => 1], $result->getFacetDistributions()['tags']->getValues());
    }

    public function testProfilerRecordsBoundQueriesErrorsAndResets(): void
    {
        $this->recorder = new \Survos\SearchBundle\Profiler\DuckDbQueryRecorder();
        $this->search((new Query())->setQueryString('tree'));
        self::assertCount(5, $this->recorder->all());
        self::assertSame(['a', 'tree'], $this->recorder->all()[0]['parameters']);
        self::assertStringContainsString('?', $this->recorder->all()[0]['sql']);
        self::assertGreaterThanOrEqual(0, $this->recorder->all()[0]['milliseconds']);
        try {
            $this->search(new Query(), ['table' => 'missing_table']);
            self::fail('Expected query failure.');
        } catch (\PDOException) {
            self::assertNotNull($this->recorder->all()[5]['error']);
        }
        $this->recorder->reset();
        self::assertSame([], $this->recorder->all());
    }

    public function testUnknownFacetCannotBecomeSql(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->search((new Query())->addActiveFilter(new TermFilter('id) OR true --', ['x'])));
    }
}
