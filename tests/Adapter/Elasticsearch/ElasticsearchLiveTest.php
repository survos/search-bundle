<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Adapter\Elasticsearch;

use Elastic\Elasticsearch\ClientBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchAdapter;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchClient;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchQueryBuilder;
use Survos\SearchBundle\Search\AbstractSearch;
use Survos\SearchBundle\Search\Query;
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
        if (!is_string($url) || '' === $url) {
            self::markTestSkipped('Set ELASTICSEARCH_URL to run the live Elasticsearch test.');
        }

        $this->client = new ElasticsearchClient(ClientBuilder::create()->setHosts([$url])->build());
        $adapter = new ElasticsearchAdapter($this->client, new ElasticsearchQueryBuilder());
        self::assertTrue($this->client->ping());

        $this->client->createIndex(self::INDEX, ['properties' => [
            'name' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
            'description' => ['type' => 'text'],
            'embedding' => ['type' => 'dense_vector', 'dims' => 3, 'index' => true, 'similarity' => 'cosine'],
        ]]);
        $documents = [
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
        ];
        $body = [];
        foreach ($documents as $item) {
            $body[] = ['index' => ['_index' => self::INDEX, '_id' => $item['id']]];
            $body[] = $item['document'];
        }
        self::assertFalse($this->client->bulk($body)['errors']);
        $this->client->refresh(self::INDEX);

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

        $semanticSearch = $this->search('lexical');
        $semanticSearch->addFacet('name', 'Name');
        $params = $semanticSearch->getResolvedAdapterParameters();
        $params['facetFields'] = ['name' => 'name.keyword'];
        $semanticSearch->setResolvedAdapterParameters($params);
        self::assertTrue($adapter->supportsSemanticSearch($semanticSearch));
        $query = (new Query())->setQueryString('exchange')->setSearchMode('semantic')->setMinimumSimilarity(0.5);
        $semantic = $adapter->search($query, $semanticSearch);
        self::assertSame(1, $semantic->getTotalResults());
        self::assertSame('rpc', $semantic->getHits()[0]->getData()['_id']);
        self::assertSame(2.0, $semantic->getHits()[0]->getScore());
        self::assertSame(['JSON RPC' => 1], $semantic->getFacetDistribution('name')->getValues());
        $query->addActiveFilter(new \Survos\SearchBundle\Search\Filter\TermFilter('name', ['Image tools']));
        $filtered = $adapter->search($query, $semanticSearch);
        self::assertSame(0, $filtered->getTotalResults());
        self::assertSame(['JSON RPC' => 1], $filtered->getFacetDistribution('name')->getValues(), 'Facet excludes its own selection but respects semantic cutoff.');
        $tabHybrid = $adapter->search((new Query())->setSearchMode('hybrid')->setQueryString('photo')->setMinimumSimilarity(0.5)->setSemanticCandidates(2), $semanticSearch);
        self::assertNotEmpty($tabHybrid->getHits());
        self::assertSame('hybrid', $tabHybrid->getMetadata()['mode']);
        self::assertSame(2, array_sum($tabHybrid->getFacetDistribution('name')->getValues()));
        self::assertSame(2, $tabHybrid->getTotalResults());
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
