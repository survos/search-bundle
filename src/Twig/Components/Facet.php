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

namespace Survos\SearchBundle\Twig\Components;

use Survos\SearchBundle\Context\ContextProvider;
use Survos\SearchBundle\Twig\Components\Facet\AbstractFacet;
use Survos\SearchBundle\Twig\Components\Facet\RefinementList;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

class Facet
{
    public string $property = '';

    public function __construct(
        private readonly ContextProvider $contextProvider,
    ) {
    }

    #[ExposeInTemplate]
    public function getRenderable(): bool
    {
        $component = $this->getComponent();

        return !is_a($component, AbstractFacet::class, true) || !$component::usesFacetStats()
            || isset($this->contextProvider->getCurrentContext()->getResults()->getFacetStats()[$this->property]);
    }

    #[ExposeInTemplate]
    public function getComponent(): string
    {
        $facet = $this->contextProvider->getCurrentContext()->getSearch()->getFacet($this->property);

        return $facet->getDisplayComponent() ?? RefinementList::class;
    }
}
