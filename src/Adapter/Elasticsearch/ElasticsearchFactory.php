<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\Elasticsearch;

use Elastic\Elasticsearch\ClientBuilder;
use Psr\Log\LoggerInterface;
use Survos\SearchBundle\Adapter\AdapterFactoryInterface;
use Survos\SearchBundle\Adapter\AdapterInterface;
use Survos\SearchBundle\Service\ElasticIndexNameResolver;

final readonly class ElasticsearchFactory implements AdapterFactoryInterface
{
    /**
     * elastic/transport brings its own HTTP client, so its requests never appear in Symfony's
     * log or profiler -- which reads as "the search never ran" when you go looking. Giving it a
     * PSR-3 logger is enough: it logs request/response at debug and info, retries at error.
     */
    public function __construct(
        private ElasticsearchQueryBuilder $queryBuilder,
        private ?LoggerInterface $logger = null,
        private ?ElasticsearchClientDecoratorInterface $clientDecorator = null,
        // DI always supplies the configured resolver; this default only serves direct
        // instantiation in tests, where sharing the namespace is deliberate.
        private ElasticIndexNameResolver $nameResolver = new ElasticIndexNameResolver(''),
    ) {}

    public function support(string $dsn): bool
    {
        return str_starts_with($dsn, 'elasticsearch://')
            || str_starts_with($dsn, 'elasticsearch+https://')
            || str_starts_with($dsn, 'elastic://');
    }

    public function createAdapter(string $dsn): AdapterInterface
    {
        $parts = parse_url($dsn);
        if (!is_array($parts) || !isset($parts['host'])) {
            throw new \InvalidArgumentException(sprintf('Invalid Elasticsearch DSN "%s".', $dsn));
        }

        $transportScheme = ($parts['scheme'] ?? '') === 'elasticsearch+https' ? 'https' : 'http';
        $host = sprintf('%s://%s:%d', $transportScheme, $parts['host'], $parts['port'] ?? 9200);
        $builder = ClientBuilder::create()->setHosts([$host]);

        if ($this->logger !== null) {
            $builder->setLogger($this->logger);
        }

        parse_str($parts['query'] ?? '', $query);
        if (is_string($query['api_key'] ?? null) && $query['api_key'] !== '') {
            $builder->setApiKey($query['api_key']);
        } elseif (isset($parts['user'])) {
            $builder->setBasicAuthentication(
                rawurldecode($parts['user']),
                rawurldecode((string) ($parts['pass'] ?? '')),
            );
        }

        $client = new ElasticsearchClient($builder->build());

        return new ElasticsearchAdapter(
            $this->clientDecorator?->decorate($client) ?? $client,
            $this->queryBuilder,
            $this->nameResolver,
        );
    }
}
