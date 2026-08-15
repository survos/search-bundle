<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\Persistence\ManagerRegistry;
use Survos\SearchBundle\Adapter\AdapterProvider;
use Survos\SearchBundle\Search\SearchProvider;
use Survos\SearchBundle\Registry\UxSearchRegistry;
use Survos\SearchBundle\Contract\EmbeddingProviderInterface;
use Survos\SearchBundle\Contract\IndexingAdapterInterface;
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
        #[Option('Documents per Elasticsearch bulk request')]
        int $batchSize = 250,
        #[Option('Maximum documents to index')]
        ?int $limit = null,
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

            if ($adapter instanceof IndexingAdapterInterface) {
                $this->ensureExternalIndex(
                    $io,
                    $adapter,
                    $descriptor->class,
                    $descriptor->code,
                    $parameters,
                    $drop,
                    $rebuild,
                    $batchSize,
                    $limit,
                );
                continue;
            }

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

    /** @param class-string $class
     *  @param array<string, mixed> $parameters
     */
    private function ensureExternalIndex(
        SymfonyStyle $io,
        IndexingAdapterInterface $adapter,
        string $class,
        string $code,
        array $parameters,
        bool $drop,
        bool $rebuild,
        int $batchSize,
        ?int $limit,
    ): void {
        if (!$adapter->ping()) {
            throw new \RuntimeException(sprintf('Search backend for "%s" is unavailable.', $code));
        }

        $index = $this->externalIndexName($parameters['index'] ?? null, $code);
        $mappings = is_array($parameters['mappings'] ?? null) ? $parameters['mappings'] : [];
        $dimensions = $parameters['vectorDimensions'] ?? null;
        if (is_int($dimensions)) {
            $mappings[(string) $parameters['vectorField']] ??= [
                'type' => 'dense_vector',
                'dims' => $dimensions,
                'index' => true,
                'similarity' => $parameters['vectorSimilarity'],
            ];
        }
        $adapter->ensureIndex($index, $mappings, $drop);

        if (!$rebuild) {
            $io->success(sprintf('%s: ensured external index %s', $code, $index));
            return;
        }

        $count = $adapter->bulkIndex(
            $index,
            $this->externalDocuments($class, $parameters, $limit),
            max(1, $batchSize),
        );
        $io->success(sprintf('%s: indexed %d documents into %s', $code, $count, $index));
    }

    /** @param class-string $class
     *  @param array<string, mixed> $parameters
     *  @return \Generator<int, array{id: string, document: array<string, mixed>}>
     */
    private function externalDocuments(string $class, array $parameters, ?int $limit): \Generator
    {
        $provider = $parameters['documentProvider'] ?? null;
        if (is_callable($provider)) {
            $provider = $provider();
        }
        if (!is_iterable($provider)) {
            $manager = $this->managerRegistry->getManagerForClass($class);
            if ($manager === null) {
                throw new \LogicException(sprintf(
                    'No documentProvider is configured and no Doctrine manager exists for %s.',
                    $class,
                ));
            }
            $provider = $manager->getRepository($class)->findAll();
        }

        $mapper = $parameters['documentMapper'] ?? null;
        $fields = $parameters['sourceFields'] ?: $parameters['searchFields'];
        $idField = (string) $parameters['idField'];
        $embeddingProvider = $parameters['embeddingProvider'] ?? null;
        $embeddingText = $parameters['embeddingText'] ?? null;
        $vectorField = (string) $parameters['vectorField'];
        $count = 0;

        foreach ($provider as $source) {
            if ($limit !== null && $count >= $limit) {
                break;
            }
            $document = is_callable($mapper)
                ? $mapper($source)
                : $this->mapDocument($source, $fields);
            if (!is_array($document)) {
                throw new \UnexpectedValueException('documentMapper must return an array.');
            }
            $document = $this->normalizeDocument($document);

            if (!isset($document[$vectorField]) && is_callable($embeddingText)) {
                $text = $embeddingText($document);
                if (is_string($text) && $text !== '') {
                    if ($embeddingProvider instanceof EmbeddingProviderInterface) {
                        $document[$vectorField] = $embeddingProvider->embed($text);
                    } elseif (is_callable($embeddingProvider)) {
                        $document[$vectorField] = $embeddingProvider($text);
                    }
                }
            }

            $id = $document[$idField] ?? $this->readValue($source, $idField);
            if (!is_scalar($id) && !$id instanceof \Stringable) {
                throw new \LogicException(sprintf('Unable to resolve scalar id field "%s".', $idField));
            }
            yield ['id' => (string) $id, 'document' => $document];
            ++$count;
        }
    }

    /** @param string[] $fields
     *  @return array<string, mixed>
     */
    private function mapDocument(mixed $source, array $fields): array
    {
        $document = [];
        foreach ($fields as $field) {
            $name = preg_replace('/^[a-z]+\./', '', $field) ?? $field;
            $document[$name] = $this->readValue($source, $name);
        }

        return $document;
    }

    private function readValue(mixed $source, string $field): mixed
    {
        if (is_array($source)) {
            return $source[$field] ?? null;
        }
        if (!is_object($source)) {
            return null;
        }
        foreach (['get' . ucfirst($field), 'is' . ucfirst($field), 'has' . ucfirst($field)] as $method) {
            if (is_callable([$source, $method])) {
                return $source->{$method}();
            }
        }
        try {
            return $source->{$field};
        } catch (\Error) {
            return null;
        }
    }

    /** @param array<string, mixed> $document
     *  @return array<string, mixed>
     */
    private function normalizeDocument(array $document): array
    {
        foreach ($document as $field => $value) {
            $document[$field] = $this->normalizeValue($value);
        }

        return $document;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        if (is_array($value)) {
            return array_map($this->normalizeValue(...), $value);
        }

        return $value;
    }

    private function externalIndexName(mixed $configured, string $fallback): string
    {
        $name = is_string($configured) && $configured !== '' ? $configured : $fallback;
        return trim(strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name)), '-');
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
