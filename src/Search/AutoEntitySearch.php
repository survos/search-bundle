<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Search;

use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Mezcalito\UxSearchBundle\Adapter\Doctrine\DoctrineAdapter;

final class AutoEntitySearch extends AbstractFieldSearch
{
    /**
     * @param class-string $entityClass
     * @param string[]     $fieldNames
     */
    public function __construct(
        private readonly string $entityClass,
        private readonly array $fieldNames,
        private readonly ?ManagerRegistry $managerRegistry = null,
    ) {}

    protected function getFieldClass(array $options = []): string
    {
        return $this->entityClass;
    }

    public function build(array $options = []): void
    {
        $this->getFieldSearchConfigurator()->configure($this, $this->entityClass, $this->fieldNames, 'o.');

        $this->setAdapterParameters(array_replace($this->getAdapterParameters(), [
            DoctrineAdapter::SEARCH_FIELDS => $this->getAdapterParameters()['searchFields'] ?? [],
            DoctrineAdapter::QUERY_BUILDER_ALIAS => 'o',
            DoctrineAdapter::QUERY_BUILDER => static function (QueryBuilder $qb): void {},
        ]));
    }
}
