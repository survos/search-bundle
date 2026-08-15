<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Service;

use Doctrine\ORM\QueryBuilder;
use Survos\SearchBundle\Adapter\AdapterInterface;
use Survos\SearchBundle\Adapter\Doctrine\DoctrineAdapter;
use Survos\SearchBundle\Search\SearchInterface;

/**
 * Shapes parameters for the Doctrine ORM adapter (LIKE matching over a QueryBuilder).
 *
 * The fallback engine: no index to build, no external service. Unlike the other two it needs a
 * query-builder alias, which is why columnPrefix() returns "o." -- FieldSearchConfigurator has
 * to emit "o.title" rather than "title" for DQL to resolve.
 *
 * Lifted out of AutoEntitySearch's inline else-branch.
 */
final readonly class DoctrineParameterTranslator implements ParameterTranslatorInterface
{
    public function supports(AdapterInterface $adapter): bool
    {
        return $adapter instanceof DoctrineAdapter;
    }

    public function columnPrefix(): ?string
    {
        return 'o.';
    }

    /** @param class-string $entityClass */
    public function translate(SearchInterface $search, string $entityClass, AdapterInterface $adapter): void
    {
        $parameters = $search->getAdapterParameters();
        $searchFields = $parameters[DoctrineAdapter::SEARCH_FIELDS] ?? $parameters['searchFields'] ?? [];

        $search->setAdapterParameters([
            DoctrineAdapter::SEARCH_FIELDS => $searchFields,
            DoctrineAdapter::QUERY_BUILDER_ALIAS => 'o',
            DoctrineAdapter::QUERY_BUILDER => static function (QueryBuilder $qb): void {},
            DoctrineAdapter::MAX_FACET_VALUES_PARAM => $parameters[DoctrineAdapter::MAX_FACET_VALUES_PARAM] ?? 20,
            // See mezcalito/ux-search#46: with a unique identifier and no to-many fan-out the
            // DISTINCT is redundant and forces a full sort, and the Paginator's output walker is
            // unnecessary for a single-table search.
            DoctrineAdapter::COUNT_DISTINCT => false,
            DoctrineAdapter::FETCH_JOIN_COLLECTION => false,
        ]);
    }
}
