<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\DuckDb;

use Survos\SearchBundle\Adapter\AdapterFactoryInterface;
use Survos\SearchBundle\Adapter\AdapterInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class DuckDbFactory implements AdapterFactoryInterface
{
    public function __construct(
        #[AutowireIterator('survos_search.duckdb_connection')]
        private iterable $providers,
        private ?\Survos\SearchBundle\Profiler\DuckDbQueryRecorder $recorder = null,
    ) {
    }

    public function support(string $dsn): bool
    {
        return str_starts_with($dsn, 'duckdb://');
    }

    public function createAdapter(string $dsn): AdapterInterface
    {
        $name = substr($dsn, strlen('duckdb://'));
        foreach ($this->providers as $provider) {
            if ($provider->name() === $name) {
                return new DuckDbAdapter($provider, $this->recorder);
            }
        }
        throw new \InvalidArgumentException('No DuckDB connection provider registered for '.$name);
    }
}
