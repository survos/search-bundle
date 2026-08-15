<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\Elasticsearch;

use Survos\SearchBundle\Adapter\AdapterInterface;
use Survos\SearchBundle\Search\Filter\RangeFilter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\ResultSet\FacetStat;
use Survos\SearchBundle\Search\ResultSet\FacetTermDistribution;
use Survos\SearchBundle\Search\ResultSet\Hit;
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Survos\SearchBundle\Search\SearchInterface;
use Survos\SearchBundle\Contract\IndexingAdapterInterface;
use Survos\SearchBundle\Contract\EmbeddingProviderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final readonly class ElasticsearchAdapter implements AdapterInterface, IndexingAdapterInterface
{
    public function __construct(
        private ElasticsearchClientInterface $client,
        private ElasticsearchQueryBuilder $queryBuilder,
    ) {}

    public function configureParameters(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'index' => null,
            'searchFields' => [],
            'sourceFields' => [],
            'facetFields' => [],
            'sortFields' => [],
            'mappings' => [],
            'idField' => 'id',
            'documentProvider' => null,
            'documentMapper' => null,
            'embeddingText' => null,
            'embeddingProvider' => null,
            'queryVector' => null,
            'retrievalMode' => 'lexical',
            'vectorField' => 'embedding',
            'vectorDimensions' => null,
            'vectorSimilarity' => 'cosine',
            'k' => 20,
            'numCandidates' => 100,
            'rankConstant' => 60,
            'rankWindowSize' => 100,
            'maxFacetValues' => 100,
            'highlight' => true,
            'explain' => false,
        ]);

        $resolver->setAllowedTypes('index', ['null', 'string']);
        $resolver->setAllowedTypes('searchFields', 'string[]');
        $resolver->setAllowedTypes('sourceFields', 'string[]');
        $resolver->setAllowedTypes('facetFields', 'array');
        $resolver->setAllowedTypes('sortFields', 'array');
        $resolver->setAllowedTypes('mappings', 'array');
        $resolver->setAllowedTypes('idField', 'string');
        $resolver->setAllowedTypes('documentProvider', ['null', 'iterable', 'callable']);
        $resolver->setAllowedTypes('documentMapper', ['null', 'callable']);
        $resolver->setAllowedTypes('embeddingText', ['null', 'callable']);
        $resolver->setAllowedTypes('embeddingProvider', ['null', 'callable', EmbeddingProviderInterface::class]);
        $resolver->setAllowedTypes('queryVector', ['null', 'array', 'callable']);
        $resolver->setAllowedValues('retrievalMode', ['lexical', 'vector', 'hybrid']);
        $resolver->setAllowedTypes('vectorField', 'string');
        $resolver->setAllowedTypes('vectorDimensions', ['null', 'int']);
        $resolver->setAllowedValues('vectorSimilarity', ['cosine', 'dot_product', 'l2_norm', 'max_inner_product']);
        foreach (['k', 'numCandidates', 'rankConstant', 'rankWindowSize', 'maxFacetValues'] as $integer) {
            $resolver->setAllowedTypes($integer, 'int');
        }
        $resolver->setAllowedTypes('highlight', 'bool');
        $resolver->setAllowedTypes('explain', 'bool');
    }

    public function search(Query $query, SearchInterface $search): ResultSet
    {
        $index = $this->indexName($search);
        try {
            $response = $this->client->search($index, $this->queryBuilder->build($query, $search));
        } catch (\Throwable $exception) {
            if ($search->getResolvedAdapterParameter('retrievalMode') === 'hybrid'
                && str_contains($exception->getMessage(), 'Reciprocal Rank Fusion (RRF)')) {
                $response = $this->clientSideRrf($index, $query, $search);
            } else {
                throw new \RuntimeException(sprintf(
                    'Elasticsearch search failed for index "%s": %s',
                    $index,
                    $exception->getMessage(),
                ), 0, $exception);
            }
        }

        $hits = [];
        foreach (($response['hits']['hits'] ?? []) as $rawHit) {
            if (!is_array($rawHit)) {
                continue;
            }
            $source = is_array($rawHit['_source'] ?? null) ? $rawHit['_source'] : [];
            $source['_id'] ??= (string) ($rawHit['_id'] ?? '');
            $hits[] = new Hit(
                $source,
                (float) ($rawHit['_score'] ?? 0.0),
                array_filter([
                    'engine' => 'elasticsearch',
                    'mode' => $search->getResolvedAdapterParameter('retrievalMode'),
                    'index' => $rawHit['_index'] ?? $index,
                    'highlight' => $rawHit['highlight'] ?? null,
                    'explanation' => $rawHit['_explanation'] ?? null,
                    'rrf' => $rawHit['_search_bundle_rrf'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        $total = $response['hits']['total'] ?? 0;
        if (is_array($total)) {
            $total = $total['value'] ?? 0;
        }

        [$distributions, $stats] = $this->facets($response, $query, $search);

        return (new ResultSet())
            ->setIndexUid($index)
            ->setHits($hits)
            ->setTotalResults((int) $total)
            ->setFacetDistributions($distributions)
            ->setFacetStats($stats)
            ->setMetadata([
                'engine' => 'elasticsearch',
                'mode' => $search->getResolvedAdapterParameter('retrievalMode'),
                'took' => (int) ($response['took'] ?? 0),
                'timedOut' => (bool) ($response['timed_out'] ?? false),
                'shards' => $response['_shards'] ?? [],
                'hybridImplementation' => $response['_search_bundle_hybrid'] ?? (
                    $search->getResolvedAdapterParameter('retrievalMode') === 'hybrid' ? 'native_rrf' : null
                ),
            ]);
    }

    public function ensureIndex(string $index, array $mappings, bool $drop = false): void
    {
        if ($drop && $this->client->indexExists($index)) {
            $this->client->deleteIndex($index);
        }
        if (!$this->client->indexExists($index)) {
            $this->client->createIndex($index, $mappings);
        }
    }

    public function bulkIndex(string $index, iterable $documents, int $batchSize = 250): int
    {
        $body = [];
        $count = 0;
        foreach ($documents as $item) {
            $body[] = ['index' => ['_index' => $index, '_id' => $item['id']]];
            $body[] = $item['document'];
            ++$count;
            if (($count % $batchSize) === 0) {
                $this->flush($body);
                $body = [];
            }
        }
        if ($body !== []) {
            $this->flush($body);
        }
        if ($count > 0) {
            $this->client->refresh($index);
        }

        return $count;
    }

    public function ping(): bool
    {
        return $this->client->ping();
    }

    /** @param list<array<string, mixed>> $body */
    private function flush(array $body): void
    {
        $response = $this->client->bulk($body);
        if (!($response['errors'] ?? false)) {
            return;
        }

        foreach (($response['items'] ?? []) as $item) {
            $operation = is_array($item) ? reset($item) : null;
            if (is_array($operation) && isset($operation['error'])) {
                throw new \RuntimeException(sprintf(
                    'Elasticsearch bulk indexing failed for document "%s": %s',
                    $operation['_id'] ?? '?',
                    json_encode($operation['error'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ));
            }
        }
        throw new \RuntimeException('Elasticsearch bulk indexing failed without item-level error details.');
    }

    /** @param array<string, mixed> $response
     *  @return array{0: list<FacetTermDistribution>, 1: list<FacetStat>}
     */
    private function facets(array $response, Query $query, SearchInterface $search): array
    {
        $distributions = [];
        $stats = [];
        foreach ($search->getFacets() as $facet) {
            $property = $facet->getProperty();
            $values = [];
            foreach (($response['aggregations'][$property]['buckets'] ?? []) as $bucket) {
                if (is_array($bucket) && isset($bucket['key'])) {
                    $values[(string) $bucket['key']] = (int) ($bucket['doc_count'] ?? 0);
                }
            }
            $filter = $query->getActiveFilter($property);
            $distributions[] = (new FacetTermDistribution())
                ->setProperty($property)
                ->setValues($values)
                ->setCheckedValues($filter instanceof TermFilter ? $filter->getValues() : []);

            $rawStats = $response['aggregations'][$property . '__stats'] ?? null;
            if (is_array($rawStats) && ($rawStats['count'] ?? 0) > 0) {
                $stats[] = new FacetStat(
                    $property,
                    (float) $rawStats['min'],
                    (float) $rawStats['max'],
                    $filter instanceof RangeFilter ? $filter->getMin() : null,
                    $filter instanceof RangeFilter ? $filter->getMax() : null,
                );
            }
        }

        return [$distributions, $stats];
    }

    private function indexName(SearchInterface $search): string
    {
        $configured = $search->getResolvedAdapterParameter('index');
        $name = is_string($configured) && $configured !== '' ? $configured : (string) $search->getIndexName();
        $name = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name));

        return trim($name, '-') ?: 'search';
    }

    /** @return array<string, mixed> */
    private function clientSideRrf(string $index, Query $query, SearchInterface $search): array
    {
        $candidateQuery = clone $query;
        $candidateQuery
            ->setCurrentPage(1)
            ->setActiveHitsPerPage(max(
                (int) $search->getResolvedAdapterParameter('rankWindowSize'),
                $query->getCurrentPage() * $query->getActiveHitsPerPage(),
            ));
        $lexical = $this->client->search($index, $this->queryBuilder->build($candidateQuery, $search, 'lexical'));
        $vector = $this->client->search($index, $this->queryBuilder->build($candidateQuery, $search, 'vector'));
        $constant = max(1, (int) $search->getResolvedAdapterParameter('rankConstant'));
        $fused = [];

        foreach (['lexical' => $lexical, 'vector' => $vector] as $source => $response) {
            foreach (($response['hits']['hits'] ?? []) as $offset => $hit) {
                if (!is_array($hit) || !isset($hit['_id'])) {
                    continue;
                }
                $id = (string) $hit['_id'];
                if (!isset($fused[$id])) {
                    $fused[$id] = $hit;
                    $fused[$id]['_score'] = 0.0;
                }
                $contribution = 1.0 / ($constant + $offset + 1);
                $fused[$id]['_score'] = (float) $fused[$id]['_score'] + $contribution;
                $fused[$id]['_search_bundle_rrf'][$source] = [
                    'rank' => $offset + 1,
                    'score' => $hit['_score'] ?? null,
                    'contribution' => $contribution,
                ];
            }
        }
        usort($fused, static fn (array $a, array $b): int => $b['_score'] <=> $a['_score']);
        $size = max(1, $query->getActiveHitsPerPage());
        $page = max(0, $query->getCurrentPage() - 1);
        $hits = array_slice($fused, $page * $size, $size);

        return [
            'took' => (int) ($lexical['took'] ?? 0) + (int) ($vector['took'] ?? 0),
            'timed_out' => (bool) ($lexical['timed_out'] ?? false) || (bool) ($vector['timed_out'] ?? false),
            '_shards' => $lexical['_shards'] ?? $vector['_shards'] ?? [],
            '_search_bundle_hybrid' => 'client_rrf',
            'hits' => [
                'total' => ['value' => count($fused), 'relation' => 'eq'],
                'hits' => $hits,
            ],
        ];
    }
}
