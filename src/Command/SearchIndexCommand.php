<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\Persistence\ManagerRegistry;
use Mezcalito\UxSearchBundle\Adapter\AdapterProvider;
use Mezcalito\UxSearchBundle\Search\SearchProvider;
use Survos\SearchBundle\Registry\UxSearchRegistry;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\OptionsResolver\OptionsResolver;

#[AsCommand('survos:search:index', 'Create or refresh database-native search indexes')]
final class SearchIndexCommand
{
    public function __construct(
        private readonly UxSearchRegistry $uxSearchRegistry,
        private readonly SearchProvider $searchProvider,
        private readonly AdapterProvider $adapterProvider,
        private readonly ManagerRegistry $managerRegistry,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Entity/search code to index; omit for all registered searches')]
        ?string $code = null,
        #[Option('Drop and recreate SQLite FTS tables before rebuilding')]
        bool $drop = false,
        #[Option('Rebuild/populate the index after ensuring the schema exists')]
        bool $rebuild = true,
    ): int {
        $descriptors = $code === null
            ? $this->uxSearchRegistry->all()
            : array_values(array_filter($this->uxSearchRegistry->all(), static fn ($descriptor): bool => $descriptor->code === $code || $descriptor->name === $code));

        if ($descriptors === []) {
            $io->warning($code === null ? 'No UX searches are registered.' : sprintf('No UX search registered for "%s".', $code));
            return Command::SUCCESS;
        }

        foreach ($descriptors as $descriptor) {
            $search = $this->searchProvider->getSearch($descriptor->name)->create([
                'hitTemplate' => $descriptor->hitTemplate,
            ]);
            $adapter = $this->adapterProvider->getAdapter($search->getAdapterName());
            $resolver = new OptionsResolver();
            $adapter->configureParameters($resolver);
            $parameters = $resolver->resolve($search->getAdapterParameters());

            if (isset($parameters['ftsTable'])) {
                $this->ensureSqliteFts($io, $descriptor->code, $parameters, $drop, $rebuild);
                continue;
            }

            if (isset($parameters['matchExpression'], $parameters['scoreExpression'])) {
                $this->ensurePostgresTextSearch($io, $descriptor->code, $parameters, $drop);
                continue;
            }

            $io->note(sprintf('Skipping "%s": adapter does not expose DB-native index parameters.', $descriptor->code));
        }

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $parameters */
    private function ensureSqliteFts(SymfonyStyle $io, string $code, array $parameters, bool $drop, bool $rebuild): void
    {
        $connection = $this->connectionForTable((string) $parameters['table']);
        if (!$connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $io->warning(sprintf('Skipping "%s": connection is not SQLite.', $code));
            return;
        }

        $table = (string) $parameters['table'];
        $ftsTable = (string) $parameters['ftsTable'];
        $idColumn = (string) ($parameters['idColumn'] ?? 'id');
        $searchFields = array_values(array_unique(array_map(
            static fn (string $column): string => preg_replace('/^d\./', '', $column) ?? $column,
            (array) ($parameters['searchFields'] ?? []),
        )));

        if ($searchFields === []) {
            $io->warning(sprintf('Skipping "%s": no searchFields configured for %s.', $code, $table));
            return;
        }

        if ($drop) {
            $connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $connection->quoteIdentifier($ftsTable)));
        }

        $columns = implode(', ', array_map($connection->quoteIdentifier(...), $searchFields));
        $sql = sprintf(
            'CREATE VIRTUAL TABLE IF NOT EXISTS %s USING fts5(%s, content=%s, content_rowid=%s)',
            $connection->quoteIdentifier($ftsTable),
            $columns,
            $connection->quote($table),
            $connection->quote($idColumn),
        );
        $connection->executeStatement($sql);

        if ($rebuild) {
            $connection->executeStatement(sprintf(
                'INSERT INTO %s(%s) VALUES (%s)',
                $connection->quoteIdentifier($ftsTable),
                $connection->quoteIdentifier($ftsTable),
                $connection->quote('rebuild'),
            ));
        }

        $io->success(sprintf('%s: ensured %s for %s (%d fields)', $code, $ftsTable, $table, count($searchFields)));
    }


    /** @param array<string, mixed> $parameters */
    private function ensurePostgresTextSearch(SymfonyStyle $io, string $code, array $parameters, bool $drop): void
    {
        $connection = $this->connectionForTable((string) $parameters['table']);
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $io->warning(sprintf('Skipping "%s": connection is not PostgreSQL.', $code));
            return;
        }

        $table = (string) $parameters['table'];
        $searchFields = array_values(array_unique(array_map(
            static fn (string $column): string => preg_replace('/^d\./', '', $column) ?? $column,
            (array) ($parameters['searchFields'] ?? []),
        )));

        if ($searchFields === []) {
            $io->warning(sprintf('Skipping "%s": no searchFields configured for %s.', $code, $table));
            return;
        }

        $indexName = sprintf('idx_%s_search_fts', preg_replace('/[^A-Za-z0-9_]/', '_', $table) ?: $table);
        if ($drop) {
            $connection->executeStatement(sprintf('DROP INDEX IF EXISTS %s', $connection->quoteIdentifier($indexName)));
        }

        $connection->executeStatement(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s USING GIN ((%s))',
            $connection->quoteIdentifier($indexName),
            $connection->quoteIdentifier($table),
            $this->postgresVectorExpression($connection, $searchFields),
        ));

        $io->success(sprintf('%s: ensured %s for %s (%d fields)', $code, $indexName, $table, count($searchFields)));
    }

    /** @param string[] $fields */
    private function postgresVectorExpression(Connection $connection, array $fields): string
    {
        $expressions = [];
        foreach ($fields as $field) {
            if (!is_string($field) || $field === '') {
                continue;
            }
            $expressions[] = sprintf(
                'to_tsvector(\'english\', coalesce(%s::text, \'\'))',
                $connection->quoteIdentifier($field),
            );
        }

        return $expressions === [] ? "to_tsvector('english', '')" : implode(' || ', $expressions);
    }

    private function connectionForTable(string $table): Connection
    {
        foreach ($this->managerRegistry->getManagers() as $manager) {
            $connection = $manager->getConnection();
            $schemaManager = $connection->createSchemaManager();
            if ($schemaManager->tablesExist([$table])) {
                return $connection;
            }
        }

        return $this->managerRegistry->getConnection();
    }
}
