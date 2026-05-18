<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Twig;

use Survos\FieldBundle\Model\FieldDescriptor;
use Survos\FieldBundle\Service\FieldReader;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SearchExtension extends AbstractExtension
{
    public function __construct(private readonly FieldReader $fieldReader) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('search_fields', $this->fields(...)),
        ];
    }

    /**
     * @return list<FieldDescriptor>
     */
    public function fields(string $class): array
    {
        return $this->fieldReader->getDescriptors($class);
    }
}
