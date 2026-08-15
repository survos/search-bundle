<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\Elasticsearch;

/**
 * Lets another bundle wrap the client the factory builds.
 *
 * The client is constructed per-DSN inside ElasticsearchFactory rather than registered as a
 * service, so it cannot be decorated through normal DI. survos/elastic-bundle uses this to
 * install a traceable client that feeds the profiler; without a hook, Elasticsearch traffic is
 * invisible to Symfony because elastic/transport brings its own HTTP client.
 */
interface ElasticsearchClientDecoratorInterface
{
    public function decorate(ElasticsearchClientInterface $client): ElasticsearchClientInterface;
}
