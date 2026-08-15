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
use Survos\SearchBundle\Search\Filter\FilterInterface;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

class ClearRefinements
{
    public function __construct(
        private readonly ContextProvider $contextProvider,
    ) {
    }

    /**
     * @return array<string, FilterInterface>
     */
    #[ExposeInTemplate]
    public function getActiveFilters(): array
    {
        return $this->contextProvider->getCurrentContext()->getQuery()->getActiveFilters();
    }
}
