<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Adapter\Doctrine;

use Doctrine\ORM\QueryBuilder;
use Survos\SearchBundle\Adapter\Doctrine\DoctrineAdapter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Survos\SearchBundle\Tests\Fixtures\Adapter\Doctrine\Foo;

final class MultiValueFacetTest extends AbstractDoctrineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $a = new Foo('A', '1', 10);
        $a->metadata = ['tags' => ['newspaper-source', 'history', 'history']];
        $b = new Foo('A', '2', 20);
        $b->metadata = ['tags' => ['history']];
        $c = new Foo('B', '3', 30);
        $c->metadata = ['tags' => ['smith']];
        $this->createDatabase([$a, $b, $c, new Foo('B', '4', 40)]);
        $this->search->addFacet('tags', 'Tags');
        $this->search->setResolvedAdapterParameters([
            ...$this->search->getResolvedAdapterParameters(),
            DoctrineAdapter::MULTI_VALUE_FACETS => [
                'tags' => static function (QueryBuilder $qb): array {
                    $tags = [];
                    foreach ($qb->select('DISTINCT o.id AS id, o.metadata AS metadata')->getQuery()->getArrayResult() as $row) {
                        $tags[$row['id']] = $row['metadata']['tags'] ?? [];
                    }
                    return $tags;
                },
            ],
        ]);
    }

    public function testCountsArePerHitAndFilteringPrecedesPagination(): void
    {
        $this->query->addActiveFilter(new TermFilter('tags', ['history']));
        $this->query->setActiveHitsPerPage(1)->setCurrentPage(2);
        $result = $this->adapter->search($this->query, $this->search);
        self::assertSame(2, $result->getTotalResults());
        self::assertCount(1, $result->getHits());
        self::assertSame(['history' => 2, 'newspaper-source' => 1, 'smith' => 1], $result->getFacetDistribution('tags')->getValues());
        self::assertSame(['A' => 2], $result->getFacetDistribution('o.type')->getValues());
    }

    public function testMultipleTagsUseOrAndOtherFacetsConstrainCounts(): void
    {
        $this->query->addActiveFilter(new TermFilter('tags', ['history', 'smith']));
        $this->query->addActiveFilter(new TermFilter('o.type', ['A']));
        $result = $this->adapter->search($this->query, $this->search);
        self::assertSame(2, $result->getTotalResults());
        self::assertSame(['history' => 2, 'smith' => 0, 'newspaper-source' => 1], $result->getFacetDistribution('tags')->getValues());
    }

    public function testUnknownTagReturnsNoHitsAndRemainsClearable(): void
    {
        $this->query->addActiveFilter(new TermFilter('tags', ['missing']));
        $result = $this->adapter->search($this->query, $this->search);
        self::assertSame(0, $result->getTotalResults());
        self::assertSame(0, $result->getFacetDistribution('tags')->getValues()['missing']);
        self::assertSame(['missing'], $result->getFacetDistribution('tags')->getCheckedValues());
    }
}
