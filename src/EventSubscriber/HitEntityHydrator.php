<?php

declare(strict_types=1);

namespace Survos\SearchBundle\EventSubscriber;

use Doctrine\Persistence\ManagerRegistry;
use Mezcalito\UxSearchBundle\Event\PostSearchEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Replaces raw-array hits (from the DBAL adapters — postgres-bm25, sqlite-fts5) with
 * the corresponding Doctrine entities, so hit templates receive the same managed
 * object the Doctrine adapter would have returned.
 *
 * Hits that are already objects (the Doctrine adapter) are left untouched, so this is
 * a no-op for non-DBAL searches. Entities are batch-loaded in a single query and
 * remapped in result (score) order; rows whose entity no longer exists are dropped.
 */
final readonly class HitEntityHydrator implements EventSubscriberInterface
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [PostSearchEvent::class => 'hydrate'];
    }

    public function hydrate(PostSearchEvent $event): void
    {
        $resultSet = $event->getResultSet();
        $hits = $resultSet->getHits();
        if ($hits === []) {
            return;
        }

        // Only the DBAL adapters emit array hits; the Doctrine adapter already returns
        // entities, so leave those searches untouched.
        $hasArrayHit = false;
        foreach ($hits as $hit) {
            if (\is_array($hit->getData())) {
                $hasArrayHit = true;
                break;
            }
        }
        if (!$hasArrayHit) {
            return;
        }

        $entityClass = $event->getSearch()->getIndexName();
        if (!\is_string($entityClass)) {
            return;
        }

        $manager = $this->registry->getManagerForClass($entityClass);
        if (null === $manager) {
            return; // not a Doctrine-managed entity — nothing to hydrate
        }

        $metadata = $manager->getClassMetadata($entityClass);
        $identifiers = $metadata->getIdentifierFieldNames();
        if (1 !== \count($identifiers)) {
            return; // composite identifiers are unsupported
        }
        $idField = $identifiers[0];

        // Collect the ids referenced by the array hits.
        $ids = [];
        foreach ($hits as $hit) {
            $data = $hit->getData();
            if (\is_array($data) && \array_key_exists($idField, $data)) {
                $ids[] = $data[$idField];
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return;
        }

        // One batched query for every id on the page.
        $entitiesById = [];
        foreach ($manager->getRepository($entityClass)->findBy([$idField => $ids]) as $entity) {
            $id = $metadata->getIdentifierValues($entity)[$idField];
            $entitiesById[$id] = $entity;
        }

        // Rebuild hits in original order, swapping the array for its entity and
        // dropping any hit whose entity no longer exists.
        $hydrated = [];
        foreach ($hits as $hit) {
            $data = $hit->getData();
            if (!\is_array($data)) {
                $hydrated[] = $hit; // already an entity (mixed adapters — unlikely)

                continue;
            }

            $id = $data[$idField] ?? null;
            if (null !== $id && isset($entitiesById[$id])) {
                $hydrated[] = $hit->setData($entitiesById[$id]);
            }
        }

        $resultSet->setHits($hydrated);
    }
}
