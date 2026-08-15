<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Adapter\Elasticsearch;

use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\SearchInterface;
use PHPUnit\Framework\TestCase;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchAdapter;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchClientInterface;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchQueryBuilder;

final class ElasticsearchAdapterTest extends TestCase
{
    public function testSearchPreservesScoresAndBackendMetadata(): void
    {
        $client = $this->createMock(ElasticsearchClientInterface::class);
        $client->expects(self::once())->method('search')->with('packages')->willReturn([
            'took' => 7,
            'timed_out' => false,
            '_shards' => ['successful' => 1],
            'hits' => [
                'total' => ['value' => 1],
                'hits' => [[
                    '_id' => 'survos--json-rpc-bundle',
                    '_index' => 'packages',
                    '_score' => 0.91,
                    '_source' => ['name' => 'survos/json-rpc-bundle'],
                    'highlight' => ['description' => ['JSON <em>RPC</em>']],
                ]],
            ],
        ]);

        $results = (new ElasticsearchAdapter($client, new ElasticsearchQueryBuilder()))
            ->search((new Query())->setQueryString('json rpc'), $this->search());

        self::assertSame(1, $results->getTotalResults());
        self::assertSame(7, $results->getMetadata()['took']);
        self::assertSame('elasticsearch', $results->getMetadata()['engine']);
        self::assertSame(0.91, $results->getHits()[0]->getScore());
        self::assertSame('elasticsearch', $results->getHits()[0]->getMetadata()['engine']);
        $data = $results->getHits()[0]->getData();
        self::assertIsArray($data);
        self::assertSame('survos--json-rpc-bundle', $data['_id']);
    }

    public function testBulkIndexBatchesAndRefreshes(): void
    {
        $client = $this->createMock(ElasticsearchClientInterface::class);
        $client->expects(self::exactly(2))->method('bulk')->willReturn(['errors' => false]);
        $client->expects(self::once())->method('refresh')->with('packages');

        $adapter = new ElasticsearchAdapter($client, new ElasticsearchQueryBuilder());
        $count = $adapter->bulkIndex('packages', [
            ['id' => 'one', 'document' => ['name' => 'One']],
            ['id' => 'two', 'document' => ['name' => 'Two']],
            ['id' => 'three', 'document' => ['name' => 'Three']],
        ], 2);

        self::assertSame(3, $count);
    }

    public function testHybridFallsBackToClientSideRrfOnLicenseError(): void
    {
        $client = $this->createMock(ElasticsearchClientInterface::class);
        $calls = 0;
        $client->expects(self::exactly(3))->method('search')
            ->willReturnCallback(static function () use (&$calls): array {
                ++$calls;
                if ($calls === 1) {
                    throw new \RuntimeException('current license is non-compliant for [Reciprocal Rank Fusion (RRF)]');
                }
                if ($calls === 2) {
                    return [
                        'took' => 2,
                        'hits' => ['hits' => [
                            ['_id' => 'lexical', '_score' => 10.0, '_source' => ['name' => 'Lexical']],
                            ['_id' => 'both', '_score' => 5.0, '_source' => ['name' => 'Both']],
                        ]],
                    ];
                }
                return [
                    'took' => 3,
                    'hits' => ['hits' => [
                        ['_id' => 'both', '_score' => 0.99, '_source' => ['name' => 'Both']],
                        ['_id' => 'vector', '_score' => 0.9, '_source' => ['name' => 'Vector']],
                    ]],
                ];
            });

        $parameters = [
            'retrievalMode' => 'hybrid',
            'queryVector' => [1.0, 0.0],
        ] + $this->parameters();
        $search = $this->search($parameters);
        $results = (new ElasticsearchAdapter($client, new ElasticsearchQueryBuilder()))
            ->search((new Query())->setQueryString('exchange data'), $search);

        self::assertSame('client_rrf', $results->getMetadata()['hybridImplementation']);
        $data = $results->getHits()[0]->getData();
        self::assertIsArray($data);
        self::assertSame('both', $data['_id']);
        self::assertSame(5, $results->getMetadata()['took']);
        self::assertArrayHasKey('lexical', $results->getHits()[0]->getMetadata()['rrf']);
        self::assertArrayHasKey('vector', $results->getHits()[0]->getMetadata()['rrf']);
    }

    /** @param array<string, mixed>|null $parameters */
    private function search(?array $parameters = null): SearchInterface
    {
        $parameters ??= $this->parameters();
        $search = $this->createStub(SearchInterface::class);
        $search->method('getIndexName')->willReturn('packages');
        $search->method('getResolvedAdapterParameter')
            ->willReturnCallback(static fn (string $name): mixed => $parameters[$name]);
        $search->method('getFacets')->willReturn([]);

        return $search;
    }

    /** @return array<string, mixed> */
    private function parameters(): array
    {
        return [
            'index' => 'packages',
            'retrievalMode' => 'lexical',
            'searchFields' => ['name', 'description'],
            'sourceFields' => ['name', 'description'],
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
            'highlight' => true,
            'explain' => false,
        ];
    }
}
