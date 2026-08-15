<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Search;

use Doctrine\ORM\Mapping\Column;
use Survos\SearchBundle\Service\ParameterTranslatorInterface;
use Survos\SearchBundle\Twig\Components\Facet\RefinementList;
use Symfony\Component\String\UnicodeString;

/**
 * A search derived entirely from Doctrine metadata plus three optional class constants, for
 * entities that carry #[EntityMeta]. It adds no engine knowledge of its own: which parameters
 * an adapter needs is AbstractFieldSearch's job, via the ParameterTranslator services.
 */
final class AutoEntitySearch extends AbstractFieldSearch implements HitTemplateSearchInterface
{
    /**
     * @param class-string $entityClass
     * @param string[]     $fieldNames
     */
    public function __construct(
        private readonly string $entityClass,
        private readonly array $fieldNames,
    ) {}

    public function getIndexName(): ?string
    {
        return $this->entityClass;
    }

    protected function getFieldClass(array $options = []): string
    {
        return $this->entityClass;
    }

    protected function allowedFieldNames(): array
    {
        return $this->fieldNames;
    }

    private ?string $hitTemplate = null;

    public function getHitTemplate(): ?string
    {
        return $this->hitTemplate;
    }

    public function build(array $options = []): void
    {
        $this->hitTemplate = $options['hitTemplate'] ?? null;
        parent::build($options);
    }

    protected function configureFields(?ParameterTranslatorInterface $translator): void
    {
        $this->applyConstantFields($translator?->columnPrefix());
    }

    private function applyConstantFields(?string $columnPrefix): void
    {
        $rc = new \ReflectionClass($this->entityClass);

        $searchable = $rc->hasConstant('SEARCHABLE_FIELDS') ? (array) $rc->getConstant('SEARCHABLE_FIELDS') : [];
        $filterable = $rc->hasConstant('FILTERABLE_FIELDS') ? (array) $rc->getConstant('FILTERABLE_FIELDS') : [];
        $sortable = $rc->hasConstant('SORTABLE_FIELDS') ? (array) $rc->getConstant('SORTABLE_FIELDS') : [];

        $skipFacets = $this->unfacetableFields($rc);
        $existingFacets = [];
        foreach ($this->getFacets() as $facet) {
            $existingFacets[$facet->getProperty()] = true;
        }

        foreach ($filterable as $field) {
            if (isset($skipFacets[$field]) || isset($existingFacets[$field])) {
                continue;
            }
            $label = ucwords(str_replace('_', ' ', (new UnicodeString($field))->snake()->toString()));
            $this->addFacet($field, $label, RefinementList::class);
            $existingFacets[$field] = true;

            // Record it engine-neutrally too. The translators read facetColumns, not
            // getFacets(): Elasticsearch turns the key into a facetField (and uses it to decide
            // whether a json column is worth mapping at all), and the DBAL translator maps it to
            // a "d."-prefixed column. Without this, FILTERABLE_FIELDS produced facets the engine
            // never saw -- which silently dropped the array facets that motivate this whole path.
            $adapterParameters = $this->getAdapterParameters();
            $adapterParameters['facetColumns'][$field] ??= $field;
            $this->setAdapterParameters($adapterParameters);
        }

        $existingSorts = [];
        foreach ($this->getAvailableSorts() as $sort) {
            // getAvailableSorts() returns Sort objects, not arrays — key by getKey().
            $existingSorts[$sort->getKey() ?? ''] = true;
        }

        foreach ($sortable as $field) {
            $label = ucwords(str_replace('_', ' ', (new UnicodeString($field))->snake()->toString()));
            $sortKey = $columnPrefix === null ? $field : $columnPrefix . $field;
            if (!isset($existingSorts["{$sortKey}:asc"])) {
                $this->addAvailableSort("{$sortKey}:asc", "{$label} A-Z");
            }
            if (!isset($existingSorts["{$sortKey}:desc"])) {
                $this->addAvailableSort("{$sortKey}:desc", "{$label} Z-A");
            }

            $adapterParameters = $this->getAdapterParameters();
            $adapterParameters['sortColumns'][$field] ??= $field;
            $this->setAdapterParameters($adapterParameters);
        }

        if ($searchable !== []) {
            // Always write the neutral 'searchFields'. Mapping it to whatever key the engine
            // wants -- DoctrineAdapter::SEARCH_FIELDS, or column expressions for DBAL -- is the
            // translator's job now.
            $adapterParameters = $this->getAdapterParameters();
            $prefix = $columnPrefix ?? '';
            $existing = $adapterParameters['searchFields'] ?? [];
            $adapterParameters['searchFields'] = array_values(array_unique(array_merge(
                $existing,
                array_map(static fn (string $f): string => $prefix . $f, $searchable),
            )));
            $this->setAdapterParameters($adapterParameters);
        }
    }

    /**
     * Fields that cannot back a facet at all, whatever the adapter.
     *
     * This used to also exclude json/jsonb/array/simple_array, which meant a column named
     * in FILTERABLE_FIELDS -- an explicit request -- was silently dropped. Multi-valued
     * columns are frequently the most useful facets (phpVersions, symfonyVersions,
     * keywords), and an engine that buckets each element handles them natively, so an
     * explicit declaration now wins. See configureDbalAdapter(), which rejects them loudly
     * for adapters that would GROUP BY the whole serialized array instead.
     *
     * boolean stays excluded: PostgreSQL rejects min(boolean) in DoctrineAdapter's stats
     * query (same reason FieldSearchConfigurator::shouldExposeFacet() skips Widget::Boolean).
     *
     * @return array<string, true>
     */
    private function unfacetableFields(\ReflectionClass $rc): array
    {
        $unfacetableTypes = ['boolean', 'bool', 'object'];

        $skip = [];
        foreach ($rc->getProperties() as $prop) {
            foreach ($prop->getAttributes(Column::class) as $attr) {
                $col = $attr->newInstance();
                $type = $col->type ?? null;
                if ($type !== null && in_array($type, $unfacetableTypes, true)) {
                    $skip[$prop->getName()] = true;
                    continue 2;
                }
            }
            $nativeType = $prop->getType();
            if ($nativeType instanceof \ReflectionNamedType && $nativeType->getName() === 'bool') {
                $skip[$prop->getName()] = true;
            }
        }

        return $skip;
    }
}
