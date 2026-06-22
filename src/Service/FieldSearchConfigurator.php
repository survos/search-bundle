<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Service;

use Mezcalito\UxSearchBundle\Search\SearchInterface;
use Mezcalito\UxSearchBundle\Twig\Components\Facet\RangeInput;
use Mezcalito\UxSearchBundle\Twig\Components\Facet\RangeSlider;
use Mezcalito\UxSearchBundle\Twig\Components\Facet\RefinementList;
use Survos\FieldBundle\Enum\Widget;
use Survos\FieldBundle\Model\FieldDescriptor;
use Survos\FieldBundle\Service\FieldReader;

final readonly class FieldSearchConfigurator
{
    /**
     * @param int[] $defaultHitsPerPageChoices
     */
    public function __construct(
        private FieldReader $fieldReader,
        private int $defaultHitsPerPage = 24,
        private array $defaultHitsPerPageChoices = [12, 24, 48],
    ) {}

    /**
     * @param string[] $allowedFields
     */
    public function configure(SearchInterface $search, string $fieldClass, array $allowedFields = [], ?string $columnPrefix = null): void
    {
        $descriptors = $this->fieldReader->getDescriptors($fieldClass);
        $allowedMap = array_flip($allowedFields);

        $searchable = [];
        $facetColumns = [];
        $sortColumns = [];

        foreach ($descriptors as $descriptor) {
            if (!$descriptor->visible) {
                continue;
            }

            if ($allowedMap !== [] && !isset($allowedMap[$descriptor->name])) {
                continue;
            }

            if ($descriptor->searchable) {
                $searchable[] = $this->column($descriptor->name, $columnPrefix);
            }

            if ($descriptor->sortable) {
                $sortColumns[$descriptor->name] = $this->column($descriptor->name, $columnPrefix);
                $label = $descriptor->getFallbackLabel();
                [$ascendingLabel, $descendingLabel] = $this->sortLabels($descriptor, $label);
                $search->addAvailableSort($this->column($descriptor->name, $columnPrefix) . ':asc', $ascendingLabel);
                $search->addAvailableSort($this->column($descriptor->name, $columnPrefix) . ':desc', $descendingLabel);
            }

            if ($descriptor->facet || $this->shouldExposeFacet($descriptor)) {
                $component = $this->componentFor($descriptor);
                $search->addFacet($descriptor->name, $descriptor->getFallbackLabel(), $component);
                $facetColumns[$descriptor->name] = $this->column($descriptor->name, $columnPrefix);
            }
        }

        $search->setAvailableHitsPerPage(array_values(array_unique([$this->defaultHitsPerPage, ...$this->defaultHitsPerPageChoices])));

        $adapterParameters = $search->getAdapterParameters();
        $adapterParameters['searchFields'] = array_values(array_unique(array_merge($adapterParameters['searchFields'] ?? [], $searchable)));
        $adapterParameters['facetColumns'] = array_merge($adapterParameters['facetColumns'] ?? [], $facetColumns);
        $adapterParameters['sortColumns'] = array_merge($adapterParameters['sortColumns'] ?? [], $sortColumns);

        $search->setAdapterParameters($adapterParameters);
    }

    private function column(string $field, ?string $prefix): string
    {
        return $prefix === null ? $field : $prefix . $field;
    }

    /** @return array{0:string, 1:string} */
    private function sortLabels(FieldDescriptor $descriptor, string $label): array
    {
        $type = strtolower(ltrim($descriptor->type, '?\\'));

        if (in_array($type, ['int', 'integer', 'bigint', 'smallint', 'float', 'double', 'decimal'], true)) {
            return [$label . ' Low-High', $label . ' High-Low'];
        }

        if (str_contains($type, 'date') || str_contains($type, 'time')) {
            return [$label . ' Old-New', $label . ' New-Old'];
        }

        return [$label . ' A-Z', $label . ' Z-A'];
    }

    private function shouldExposeFacet(FieldDescriptor $descriptor): bool
    {
        $widget = $descriptor->resolvedWidget();

        // Widget::Boolean excluded: PostgreSQL rejects min(boolean) in DoctrineAdapter's stats query.
        return $descriptor->filterable && in_array($widget, [Widget::Select, Widget::Range, Widget::Date], true);
    }

    private function componentFor(FieldDescriptor $descriptor): string
    {
        return match ($descriptor->resolvedWidget()) {
            Widget::Range => RangeSlider::class,
            Widget::Date => RangeInput::class,
            default => RefinementList::class,
        };
    }
}
