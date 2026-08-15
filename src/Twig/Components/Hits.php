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
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Survos\SearchBundle\Search\SearchInterface;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

class Hits
{
    public function __construct(
        private readonly ContextProvider $contextProvider,
    ) {
    }

    #[ExposeInTemplate]
    public function getResults(): ResultSet
    {
        return $this->contextProvider->getCurrentContext()->getResults();
    }

    /**
     * Exposes the active search to the template so hit rendering can branch on it
     * (e.g. a per-search hit template) instead of overriding Hits.html.twig globally.
     * See Mezcalito/ux-search#13.
     */
    #[ExposeInTemplate]
    public function getSearch(): SearchInterface
    {
        return $this->contextProvider->getCurrentContext()->getSearch();
    }
}
