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

    public function getMapping(string $index): array
    {
        $body = $this->introspect(fn (): array => $this->response($this->client->indices()->getMapping(['index' => $index]))->asArray());

        // Keyed by the concrete index name, which differs from $index when $index is an alias.
        $first = reset($body);

        return \is_array($first) ? ($first['mappings'] ?? []) : [];
    }

    public function getSettings(string $index): array
    {
        $body = $this->introspect(fn (): array => $this->response($this->client->indices()->getSettings([
            'index' => $index,
            'flat_settings' => true,
        ]))->asArray());

        $first = reset($body);

        return \is_array($first) ? ($first['settings'] ?? []) : [];
    }

    public function getAliases(string $index): array
    {
        $body = $this->introspect(fn (): array => $this->response($this->client->indices()->getAlias(['index' => $index]))->asArray());

        $aliases = [];
        foreach ($body as $definition) {
            if (\is_array($definition)) {
                $aliases = [...$aliases, ...array_keys($definition['aliases'] ?? [])];
            }
        }

        return array_values(array_unique($aliases));
    }

    public function getStats(string $index): array
    {
        $body = $this->introspect(fn (): array => $this->response($this->client->indices()->stats(['index' => $index]))->asArray());

        return $body['_all']['primaries'] ?? [];
    }

    public function listMappings(string $pattern = '*'): array
    {
        $body = $this->introspect(fn (): array => $this->response($this->client->indices()->getMapping(['index' => $pattern]))->asArray());

        $mappings = [];
        foreach ($body as $index => $definition) {
            $mappings[(string) $index] = \is_array($definition) ? ($definition['mappings'] ?? []) : [];
        }

        return $mappings;
    }

    public function listSettings(string $pattern = '*'): array
    {
        $body = $this->introspect(fn (): array => $this->response($this->client->indices()->getSettings([
            'index' => $pattern,
            'flat_settings' => true,
        ]))->asArray());

        $settings = [];
        foreach ($body as $index => $definition) {
            $settings[(string) $index] = \is_array($definition) ? ($definition['settings'] ?? []) : [];
        }

        return $settings;
    }

    public function listAliases(string $pattern = '*'): array
    {
        $body = $this->introspect(fn (): array => $this->response($this->client->indices()->getAlias(['index' => $pattern]))->asArray());

        $aliases = [];
        foreach ($body as $index => $definition) {
            $aliases[(string) $index] = \is_array($definition) ? array_map(strval(...), array_keys($definition['aliases'] ?? [])) : [];
        }

        return $aliases;
    }

    public function listIndices(string $pattern = '*'): array
    {
        $rows = $this->introspect(fn (): array => $this->response($this->client->cat()->indices([
            'index' => $pattern,
            'format' => 'json',
            'h' => 'index,health,status,docs.count,store.size,pri,rep',
            // Without this a pattern matching nothing is a 404 rather than an empty list.
            'expand_wildcards' => 'open,closed',
        ]))->asArray());

        $indices = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !isset($row['index'])) {
                continue;
            }

            $indices[] = [
                'index' => (string) $row['index'],
                'health' => isset($row['health']) ? (string) $row['health'] : null,
                'status' => isset($row['status']) ? (string) $row['status'] : null,
                'docs' => (int) ($row['docs.count'] ?? 0),
                'size' => isset($row['store.size']) ? (string) $row['store.size'] : null,
                'primaries' => isset($row['pri']) ? (int) $row['pri'] : null,
                'replicas' => isset($row['rep']) ? (int) $row['rep'] : null,
            ];
        }

        usort($indices, static fn (array $a, array $b): int => strcmp($a['index'], $b['index']));

        return $indices;
    }

    /**
     * A missing index is a 404 from every introspection endpoint. That is an ordinary answer here
     * ("nothing to report"), not a failure — an admin page listing several searches must still
     * render when one of them has never been created.
     *
     * @param callable(): array<string, mixed> $call
     *
     * @return array<string, mixed>
     */
    private function introspect(callable $call): array
    {
        try {
            return $call();
        } catch (\Throwable) {
            return [];
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
