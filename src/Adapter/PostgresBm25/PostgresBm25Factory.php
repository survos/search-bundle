<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\PostgresBm25;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Persistence\ManagerRegistry;
use Mezcalito\UxSearchBundle\Adapter\AdapterFactoryInterface;
use Mezcalito\UxSearchBundle\Adapter\AdapterInterface;

final readonly class PostgresBm25Factory implements AdapterFactoryInterface
{
    public function __construct(private ?ManagerRegistry $managerRegistry = null) {}

    public function support(string $dsn): bool
    {
        return str_starts_with($dsn, 'postgres-bm25://') || str_starts_with($dsn, 'pgsql-bm25://');
    }

    public function createAdapter(string $dsn): AdapterInterface
    {
        if (!$this->managerRegistry instanceof ManagerRegistry) {
            throw new \LogicException('Doctrine is required to use the postgres-bm25 adapter.');
        }

        $parsed = parse_url($dsn);
        $connectionName = $parsed['host'] ?? 'default';
        $connection = $this->managerRegistry->getConnection($connectionName);
        if (!$connection instanceof Connection) {
            throw new \LogicException(sprintf('Doctrine connection "%s" was not found.', $connectionName));
        }

        $platform = $connection->getDatabasePlatform();
        if (!$platform instanceof PostgreSQLPlatform) {
            throw new \LogicException(sprintf(
                'The "%s" search adapter requires a PostgreSQL connection, but Doctrine connection "%s" uses %s. '
                .'Point the search DSN at a backend that matches DATABASE_URL (e.g. sqlite-fts5:// for SQLite).',
                $dsn,
                $connectionName,
                $platform::class,
            ));
        }

        return new PostgresBm25Adapter($connection);
    }
}
