<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Twig;

use Mezcalito\UxSearchBundle\Context\ContextProvider;
use Survos\FieldBundle\Model\FieldDescriptor;
use Survos\FieldBundle\Service\FieldReader;
use Survos\SearchBundle\Search\HitTemplateSearchInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SearchExtension extends AbstractExtension
{
    public function __construct(
        private readonly FieldReader $fieldReader,
        private readonly ContextProvider $contextProvider,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('search_fields', $this->fields(...)),
            new TwigFunction('survos_hit_template', $this->hitTemplate(...)),
        ];
    }

    /** @return list<FieldDescriptor> */
    public function fields(string $class): array
    {
        return $this->fieldReader->getDescriptors($class);
    }

    public function hitTemplate(): ?string
    {
        if (!$this->contextProvider->hasCurrentContext()) {
            return null;
        }

        $search = $this->contextProvider->getCurrentContext()->getSearch();

        return $search instanceof HitTemplateSearchInterface ? $search->getHitTemplate() : null;
    }
}
