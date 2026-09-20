<?php

/*
 * This file is part of the UxSearch project.
 *
 * (c) Mezcalito (https://www.mezcalito.fr)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Survos\SearchBundle\Search;

readonly class Facet
{
    /**
     * @param array<string, mixed> $props
     */
    public function __construct(
        private string $property,
        private string $label,
        private ?string $displayComponent = null,
        private array $props = [],
        /** Sidebar block this facet belongs to (the DTO field's #[Field(group:)]); null renders it on its own. */
        private ?string $group = null,
    ) {
    }

    public function getGroup(): ?string
    {
        return $this->group;
    }

    public function getProperty(): string
    {
        return $this->property;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDisplayComponent(): ?string
    {
        return $this->displayComponent;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProps(): array
    {
        return $this->props;
    }
}
