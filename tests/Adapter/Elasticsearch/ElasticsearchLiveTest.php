<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Adapter\Elasticsearch;

use Elastic\Elasticsearch\ClientBuilder;
use Survos\SearchBundle\Search\AbstractSearch;
use Survos\SearchBundle\Search\Query;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchAdapter;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchClient;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchQueryBuilder;
use Symfony\Component\OptionsResolver\OptionsResolver;

#[Group('integration')]
final class ElasticsearchLiveTest extends TestCase
{
    public const string INDEX = 'search-bundle-integration-test';

    private ?ElasticsearchClient $client = null;

    protected function tearDown(): void
    {
        if ($this->client?->indexExists(self::INDEX)) {
            $this->client->deleteIndex(self::INDEX);
        }
    }

    public function testLexicalVectorAndHybridRetrievalAgainstElasticsearch9(): void
    {
        $url = getenv('ELASTICSEARCH_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set ELASTICSEARCH_URL to run the live Elasticsearch test.');
        }

        $this->client = new ElasticsearchClient(ClientBuilder::create()->setHosts([$url])->build());
        $adapter = new ElasticsearchAdapter($this->client, new ElasticsearchQueryBuilder());
        self::assertTrue($adapter->ping());

        $adapter->ensureIndex(self::INDEX, [
            'name' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
            'description' => ['type' => 'text'],
            'embedding' => ['type' => 'dense_vector', 'dims' => 3, 'index' => true, 'similarity' => 'cosine'],
        ], true);
        self::assertSame(3, $adapter->bulkIndex(self::INDEX, [
            ['id' => 'rpc', 'document' => [
                'name' => 'JSON RPC',
                'description' => 'Remote procedure calls between Symfony services',
                'embedding' => [1.0, 0.0, 0.0],
            ]],
            ['id' => 'images', 'document' => [
                'name' => 'Image tools',
                'description' => 'Generate photo thumbnails and image previews',
                'embedding' => [0.0, 1.0, 0.0],
            ]],
            ['id' => 'search', 'document' => [
                'name' => 'Search tools',
                'description' => 'Full text indexing and document search',
                'embedding' => [0.0, 0.0, 1.0],
            ]],
        ], 2));

        $lexical = $adapter->search(
            (new Query())->setQueryString('photo thumbnails')->setActiveHitsPerPage(3),
            $this->search('lexical'),
        );
        $lexicalData = $lexical->getHits()[0]->getData();
        self::assertIsArray($lexicalData);
        self::assertSame('images', $lexicalData['_id']);

        $vector = $adapter->search(
            (new Query())->setQueryString('exchange information')->setActiveHitsPerPage(3),
            $this->search('vector'),
        );
        $vectorData = $vector->getHits()[0]->getData();
        self::assertIsArray($vectorData);
        self::assertSame('rpc', $vectorData['_id']);

        $hybrid = $adapter->search(
            (new Query())->setQueryString('applications exchange data')->setActiveHitsPerPage(3),
            $this->search('hybrid'),
        );
        $hybridData = $hybrid->getHits()[0]->getData();
        self::assertIsArray($hybridData);
        self::assertSame('rpc', $hybridData['_id']);
        self::assertSame('hybrid', $hybrid->getMetadata()['mode']);
        self::assertSame('client_rrf', $hybrid->getMetadata()['hybridImplementation']);
        self::assertSame('elasticsearch', $hybrid->getHits()[0]->getMetadata()['engine']);
    }

    private function search(string $mode): AbstractSearch
    {
        if (!$this->client instanceof ElasticsearchClient) {
            throw new \LogicException('Live Elasticsearch client has not been initialized.');
        }
        $search = new class extends AbstractSearch {
            public function getIndexName(): string
            {
                return ElasticsearchLiveTest::INDEX;
            }
        };

        $resolver = new OptionsResolver();
        (new ElasticsearchAdapter($this->client, new ElasticsearchQueryBuilder()))->configureParameters($resolver);
        $search->setResolvedAdapterParameters($resolver->resolve([
            'index' => self::INDEX,
            'searchFields' => ['name^3', 'description'],
            'sourceFields' => ['name', 'description'],
            'retrievalMode' => $mode,
            'queryVector' => [1.0, 0.0, 0.0],
            'highlight' => true,
        ]));

        return $search;
    }
}
