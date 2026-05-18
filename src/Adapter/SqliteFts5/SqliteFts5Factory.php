<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\SqliteFts5;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Mezcalito\UxSearchBundle\Adapter\AdapterFactoryInterface;
use Mezcalito\UxSearchBundle\Adapter\AdapterInterface;

final readonly class SqliteFts5Factory implements AdapterFactoryInterface
{
    public function __construct(private ?ManagerRegistry $managerRegistry = null) {}

    public function support(string $dsn): bool
    {
        return str_starts_with($dsn, 'sqlite-fts5://');
    }

    public function createAdapter(string $dsn): AdapterInterface
    {
        if (!$this->managerRegistry instanceof ManagerRegistry) {
            throw new \LogicException('Doctrine is required to use the sqlite-fts5 adapter.');
        }

        $parsed = parse_url($dsn);
        $connectionName = $parsed['host'] ?? 'default';
        $connection = $this->managerRegistry->getConnection($connectionName);
        if (!$connection instanceof Connection) {
            throw new \LogicException(sprintf('Doctrine connection "%s" was not found.', $connectionName));
        }

        return new SqliteFts5Adapter($connection);
    }
}
