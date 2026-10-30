<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\DuckDb;

/** Builds a persisted index on a local, writable database before publication. */
final class DuckDbFtsIndexer
{
    public const int VERSION = 1;

    /** @param non-empty-list<string> $columns */
    public function build(\PDO $db, string $table, string $id, array $columns): void
    {
        if ([] === $columns) {
            throw new \InvalidArgumentException('An FTS index needs at least one text column.');
        }
        foreach ([$table, $id, ...$columns] as $name) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
                throw new \InvalidArgumentException('Use simple identifiers for FTS tables and columns.');
            }
        }
        $db->exec('LOAD fts');
        $db->exec('PRAGMA create_fts_index('.implode(', ', array_map($db->quote(...), [$table, $id, ...$columns])).', overwrite=1)');
    }
}
