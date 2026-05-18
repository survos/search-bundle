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

    public function configure(SearchInterface $search, string $fieldClass): void
    {
        $descriptors = $this->fieldReader->getDescriptors($fieldClass);

        $searchable = [];
        $facetColumns = [];
        $sortColumns = [];

        foreach ($descriptors as $descriptor) {
            if ($descriptor->searchable) {
                $searchable[] = $descriptor->name;
            }

            if ($descriptor->sortable) {
                $sortColumns[$descriptor->name] = $descriptor->name;
                $label = $descriptor->getFallbackLabel();
                $search->addAvailableSort($descriptor->name . ':asc', $label . ' A-Z');
                $search->addAvailableSort($descriptor->name . ':desc', $label . ' Z-A');
            }

            if ($descriptor->facet || $this->shouldExposeFacet($descriptor)) {
                $component = $this->componentFor($descriptor);
                $search->addFacet($descriptor->name, $descriptor->getFallbackLabel(), $component);
                $facetColumns[$descriptor->name] = $descriptor->name;
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

    private function shouldExposeFacet(FieldDescriptor $descriptor): bool
    {
        $widget = $descriptor->resolvedWidget();

        return $descriptor->filterable && in_array($widget, [Widget::Select, Widget::Boolean, Widget::Range, Widget::Date], true);
    }

    private function componentFor(FieldDescriptor $descriptor): string
    {
        return match ($descriptor->resolvedWidget()) {
            Widget::Range, Widget::Date => RangeInput::class,
            default => RefinementList::class,
        };
    }
}
