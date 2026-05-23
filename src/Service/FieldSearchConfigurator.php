<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Service;

use Mezcalito\UxSearchBundle\Search\SearchInterface;
use Mezcalito\UxSearchBundle\Twig\Components\Facet\RangeInput;
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
                $search->addAvailableSort($this->column($descriptor->name, $columnPrefix) . ':asc', $label . ' A-Z');
                $search->addAvailableSort($this->column($descriptor->name, $columnPrefix) . ':desc', $label . ' Z-A');
            }

            if ($descriptor->facet || $this->shouldExposeFacet($descriptor)) {
                $component = $this->componentFor($descriptor);
                $search->addFacet($descriptor->name, $descriptor->getFallbackLabel(), $component);
                $facetColumns[$descriptor->name] = $this->column($descriptor->name, $columnPrefix);
            }
        }

        $search->setAvailableHitsPerPage($this->defaultHitsPerPageChoices);

        $adapterParameters = $search->getAdapterParameters();
        $adapterParameters += [
            'searchFields' => $searchable,
            'facetColumns' => $facetColumns,
            'sortColumns' => $sortColumns,
            'defaultHitsPerPage' => $this->defaultHitsPerPage,
        ];

        $search->setAdapterParameters($adapterParameters);
    }

    private function column(string $field, ?string $prefix): string
    {
        return $prefix === null ? $field : $prefix . $field;
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
            Widget::Range, Widget::Date => RangeInput::class,
            default => RefinementList::class,
        };
    }
}
