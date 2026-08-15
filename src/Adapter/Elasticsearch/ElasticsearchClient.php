<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\Elasticsearch;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Response\Elasticsearch;

final readonly class ElasticsearchClient implements ElasticsearchClientInterface
{
    public function __construct(private Client $client) {}

    public function search(string $index, array $body): array
    {
        return $this->response($this->client->search(['index' => $index, 'body' => $body]))->asArray();
    }

    public function createIndex(string $index, array $mappings): void
    {
        $this->client->indices()->create([
            'index' => $index,
            'body' => ['mappings' => ['properties' => $mappings]],
        ]);
    }

    public function deleteIndex(string $index): void
    {
        $this->client->indices()->delete(['index' => $index]);
    }

    public function indexExists(string $index): bool
    {
        return $this->response($this->client->indices()->exists(['index' => $index]))->asBool();
    }

    public function bulk(array $body): array
    {
        return $this->response($this->client->bulk(['body' => $body]))->asArray();
    }

    public function refresh(string $index): void
    {
        $this->client->indices()->refresh(['index' => $index]);
    }

    public function ping(): bool
    {
        try {
            return $this->response($this->client->ping())->asBool();
        } catch (\Throwable) {
            return false;
        }
    }

    private function response(mixed $response): Elasticsearch
    {
        if (!$response instanceof Elasticsearch) {
            throw new \LogicException('Asynchronous Elasticsearch responses are not supported.');
        }

        return $response;
    }
}
