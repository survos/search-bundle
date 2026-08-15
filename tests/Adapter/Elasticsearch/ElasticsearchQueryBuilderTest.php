<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Adapter\Elasticsearch;

use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\SearchInterface;
use PHPUnit\Framework\TestCase;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchQueryBuilder;

final class ElasticsearchQueryBuilderTest extends TestCase
{
    public function testLexicalQueryUsesMultiMatchAndPagination(): void
    {
        $query = (new Query())
            ->setQueryString('json rpc')
            ->setCurrentPage(2)
            ->setActiveHitsPerPage(10);

        $body = (new ElasticsearchQueryBuilder())->build($query, $this->search([
            'retrievalMode' => 'lexical',
            'searchFields' => ['name^3', 'description'],
        ]));

        self::assertSame(10, $body['size']);
        self::assertSame(10, $body['from']);
        self::assertSame('json rpc', $body['query']['bool']['must'][0]['multi_match']['query']);
        self::assertArrayNotHasKey('retriever', $body);
    }

    public function testVectorQueryUsesProvidedVector(): void
    {
        $query = (new Query())->setQueryString('exchange data');

        $body = (new ElasticsearchQueryBuilder())->build($query, $this->search([
            'retrievalMode' => 'vector',
            'queryVector' => [0.1, 0.2, 0.3],
        ]));

        self::assertSame([0.1, 0.2, 0.3], $body['retriever']['knn']['query_vector']);
        self::assertSame('embedding', $body['retriever']['knn']['field']);
        self::assertArrayNotHasKey('query', $body);
    }

    public function testHybridQueryBuildsRrfRetriever(): void
    {
        $query = (new Query())->setQueryString('message between applications');

        $body = (new ElasticsearchQueryBuilder())->build($query, $this->search([
            'retrievalMode' => 'hybrid',
            'queryVector' => static fn (string $text): array => $text === '' ? [] : [1.0, 0.0],
        ]));

        $retrievers = $body['retriever']['rrf']['retrievers'];
        self::assertSame('message between applications', $retrievers[0]['standard']['query']['bool']['must'][0]['multi_match']['query']);
        self::assertSame([1.0, 0.0], $retrievers[1]['knn']['query_vector']);
        self::assertSame(60, $body['retriever']['rrf']['rank_constant']);
    }

    /** @param array<string, mixed> $overrides */
    private function search(array $overrides): SearchInterface
    {
        $parameters = $overrides + [
            'retrievalMode' => 'lexical',
            'searchFields' => ['name', 'description'],
            'sourceFields' => [],
            'facetFields' => [],
            'sortFields' => [],
            'queryVector' => null,
            'embeddingProvider' => null,
            'vectorField' => 'embedding',
            'k' => 20,
            'numCandidates' => 100,
            'rankConstant' => 60,
            'rankWindowSize' => 100,
            'maxFacetValues' => 100,
            'highlight' => false,
            'explain' => false,
        ];

        $search = $this->createStub(SearchInterface::class);
        $search->method('getResolvedAdapterParameter')
            ->willReturnCallback(static fn (string $name): mixed => $parameters[$name]);
        $search->method('getFacets')->willReturn([]);

        return $search;
    }
}
