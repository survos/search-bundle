<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\Elasticsearch;

use Elastic\Elasticsearch\ClientBuilder;
use Psr\Log\LoggerInterface;
use Survos\SearchBundle\Adapter\AdapterFactoryInterface;
use Survos\SearchBundle\Adapter\AdapterInterface;

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

        return new ElasticsearchAdapter(
            new ElasticsearchClient($builder->build()),
            $this->queryBuilder,
        );
    }
}
