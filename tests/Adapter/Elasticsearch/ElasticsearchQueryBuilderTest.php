<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Adapter\Elasticsearch;

use PHPUnit\Framework\TestCase;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchQueryBuilder;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\SearchInterface;

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
        self::assertSame('json rpc', $body['query']['bool']['must'][0]['dis_max']['queries'][0]['multi_match']['query']);
        self::assertArrayNotHasKey('retriever', $body);
    }

    public function testFuzzyClauseKeepsTheFirstLetterAndShortWordsExact(): void
    {
        $body = (new ElasticsearchQueryBuilder())->build((new Query())->setQueryString('geo'), $this->search([]));

        $fuzzy = $body['query']['bool']['must'][0]['dis_max']['queries'][1]['multi_match'];
        self::assertSame('AUTO:5,9', $fuzzy['fuzziness']);
        self::assertSame(1, $fuzzy['prefix_length']);
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
            'queryVector' => static fn (string $text): array => '' === $text ? [] : [1.0, 0.0],
        ]));

        $retrievers = $body['retriever']['rrf']['retrievers'];
        self::assertSame('message between applications', $retrievers[0]['standard']['query']['bool']['must'][0]['dis_max']['queries'][0]['multi_match']['query']);
        self::assertSame([1.0, 0.0], $retrievers[1]['knn']['query_vector']);
        self::assertSame(60, $body['retriever']['rrf']['rank_constant']);
    }

    /** A search scoped within a shared index carries its base filters on every query, text or not. */
    public function testBaseFiltersScopeEveryQuery(): void
    {
        $scope = [['term' => ['role' => 'record']]];
        foreach (['', 'portrait'] as $text) {
            $body = (new ElasticsearchQueryBuilder())->build((new Query())->setQueryString($text), $this->search(['baseFilters' => $scope]));
            self::assertSame($scope, $body['query']['bool']['filter'], 'query "'.$text.'"');
        }
    }

    private function search(array $overrides): SearchInterface
    {
        $parameters = $overrides + [
            'retrievalMode' => 'lexical',
            'baseFilters' => [],
            'fuzziness' => 'AUTO:5,9',
            'fuzzyPrefixLength' => 1,
            'prefixSearch' => true,
            'searchFields' => ['name', 'description'],
            'sourceFields' => [],
            'facetFields' => [],
            'sortFields' => [],
            'idField' => 'id',
            'mappings' => [],
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
