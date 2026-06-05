<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Search;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Mezcalito\UxSearchBundle\Adapter\Doctrine\DoctrineAdapter;
use Mezcalito\UxSearchBundle\Twig\Components\Facet\RefinementList;
use Symfony\Component\String\UnicodeString;

final class AutoEntitySearch extends AbstractFieldSearch implements HitTemplateSearchInterface
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

    public function getIndexName(): ?string
    {
        return $this->entityClass;
    }

    protected function getFieldClass(array $options = []): string
    {
        return $this->entityClass;
    }

    private ?string $hitTemplate = null;

    public function getHitTemplate(): ?string
    {
        return $this->hitTemplate;
    }

    public function build(array $options = []): void
    {
        $this->hitTemplate = $options['hitTemplate'] ?? null;

        $this->getFieldSearchConfigurator()->configure($this, $this->entityClass, $this->fieldNames, 'o.');

        // If #[Field] attributes produced nothing, fall back to the entity's class constants.
        if ($this->getFacets() === []) {
            $this->applyConstantFallback();
        }

        // DoctrineAdapter only accepts these keys; strip Survos DBAL adapter params.
        $searchFields = $this->getAdapterParameters()[DoctrineAdapter::SEARCH_FIELDS] ?? [];
        $this->setAdapterParameters([
            DoctrineAdapter::SEARCH_FIELDS          => $searchFields,
            DoctrineAdapter::QUERY_BUILDER_ALIAS    => 'o',
            DoctrineAdapter::QUERY_BUILDER          => static function (QueryBuilder $qb): void {},
            DoctrineAdapter::MAX_FACET_VALUES_PARAM => $this->getAdapterParameters()[DoctrineAdapter::MAX_FACET_VALUES_PARAM] ?? 20,
            // Auto-entity facets are plain columns on the base entity (no to-many joins),
            // so count(DISTINCT pk) is redundant and forces a full table sort per facet.
            // Emit plain count() instead. See mezcalito/ux-search#46.
            DoctrineAdapter::COUNT_DISTINCT         => false,
            // No to-many fetch joins either, so skip the DISTINCT id / ROW_NUMBER()
            // paginator walker and use a plain LIMIT/OFFSET.
            DoctrineAdapter::FETCH_JOIN_COLLECTION  => false,
        ]);
    }

    private function applyConstantFallback(): void
    {
        $rc = new \ReflectionClass($this->entityClass);

        $searchable = $rc->hasConstant('SEARCHABLE_FIELDS') ? (array) $rc->getConstant('SEARCHABLE_FIELDS') : [];
        $filterable = $rc->hasConstant('FILTERABLE_FIELDS') ? (array) $rc->getConstant('FILTERABLE_FIELDS') : [];
        $sortable   = $rc->hasConstant('SORTABLE_FIELDS')   ? (array) $rc->getConstant('SORTABLE_FIELDS')   : [];

        // Skip boolean/json/array fields — PostgreSQL rejects min()/max() on those types.
        $skipFacets = $this->nonStatFields($rc);

        foreach ($filterable as $field) {
            if (isset($skipFacets[$field])) {
                continue;
            }
            $label = ucwords(str_replace('_', ' ', (new UnicodeString($field))->snake()->toString()));
            $this->addFacet($field, $label, RefinementList::class);
        }

        foreach ($sortable as $field) {
            $label = ucwords(str_replace('_', ' ', (new UnicodeString($field))->snake()->toString()));
            $this->addAvailableSort("o.{$field}:asc",  "{$label} ↑");
            $this->addAvailableSort("o.{$field}:desc", "{$label} ↓");
        }

        if ($searchable !== []) {
            $prefixed = array_map(static fn (string $f) => "o.{$f}", $searchable);
            $existing = $this->getAdapterParameters()[DoctrineAdapter::SEARCH_FIELDS] ?? [];
            $this->setAdapterParameters(array_merge(
                $this->getAdapterParameters(),
                [DoctrineAdapter::SEARCH_FIELDS => array_unique(array_merge($existing, $prefixed))],
            ));
        }
    }

    /**
     * Returns field names whose column type cannot be used in a PostgreSQL min()/max() stats query.
     * Booleans and JSON/JSONB/array columns all fail; skip them as facets for now.
     *
     * @return array<string, true>
     */
    private function nonStatFields(\ReflectionClass $rc): array
    {
        static $nonStatTypes = ['boolean', 'bool', 'json', 'jsonb', 'array', 'simple_array', 'object'];

        $skip = [];
        foreach ($rc->getProperties() as $prop) {
            foreach ($prop->getAttributes(Column::class) as $attr) {
                $col  = $attr->newInstance();
                $type = $col->type ?? null;
                if ($type !== null && in_array($type, $nonStatTypes, true)) {
                    $skip[$prop->getName()] = true;
                    continue 2;
                }
            }
            // Detect native bool / array type with no explicit type= set
            $nativeType = $prop->getType();
            if ($nativeType instanceof \ReflectionNamedType
                && in_array($nativeType->getName(), ['bool', 'array'], true)) {
                $skip[$prop->getName()] = true;
            }
        }

        return $skip;
    }
}
