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

namespace Survos\SearchBundle\Twig;

use Survos\SearchBundle\Search\Filter\FilterInterface;
use Survos\SearchBundle\Search\Filter\RangeFilter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class UxSearchExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('ux_search_is_range_filter', $this->isRangeFilter(...)),
            new TwigFunction('ux_search_is_term_filter', $this->isTermFilter(...)),
        ];
    }

    public function isRangeFilter(FilterInterface $filter): bool
    {
        return $filter instanceof RangeFilter;
    }

    public function isTermFilter(FilterInterface $filter): bool
    {
        return $filter instanceof TermFilter;
    }
}
