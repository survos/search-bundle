<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Service;

use Survos\SearchBundle\Adapter\AdapterInterface;
use Survos\SearchBundle\Search\SearchInterface;

/**
 * Turns the engine-neutral output of FieldSearchConfigurator into one engine's parameter shape.
 *
 * FieldSearchConfigurator speaks in fields: searchFields, facetColumns, sortColumns. Each engine
 * wants something different -- Elasticsearch wants facetFields and mappings, the DBAL adapters
 * want a table and a match expression, the Doctrine ORM adapter wants a query-builder alias.
 * That per-engine shaping used to live as three private branches inside AutoEntitySearch, which
 * is why only automatic searches could target anything but Doctrine.
 *
 * Implementations are tagged and resolved by adapter TYPE, never by sniffing a DSN string: a
 * search names its adapter and only AdapterProvider knows what that name points at.
 */
interface ParameterTranslatorInterface
{
    public function supports(AdapterInterface $adapter): bool;

    /**
     * Alias FieldSearchConfigurator should prefix column names with, or null for none.
     * The Doctrine ORM adapter builds a QueryBuilder aliased "o"; the others address columns
     * or document fields directly.
     */
    public function columnPrefix(): ?string;

    /**
     * @param class-string $entityClass the class carrying the metadata, when it is a real class
     * @param AdapterInterface $adapter the resolved adapter, so an implementation covering more
     *        than one (FTS5 and BM25) can tell them apart without resolving it again
     */
    public function translate(SearchInterface $search, string $entityClass, AdapterInterface $adapter): void;
}
