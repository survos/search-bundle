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

namespace Survos\SearchBundle\Tests\Twig\Components;

use Survos\SearchBundle\Context\Context;
use Survos\SearchBundle\Search\Filter\RangeFilter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Twig\Components\CurrentRefinements;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

class CurrentRefinementsTest extends AbstractComponentTestCase
{
    use InteractsWithTwigComponents;

    public function testComponentRenders(): void
    {
        $context = new Context();
        $context->setQuery((new Query())->setActiveFilters([
            new TermFilter('brand', ['GoPro', 'Apple', 'Samsung']),
            new RangeFilter('price', 10, 20),
        ]));
        $this->setCurrentContext($context);

        $rendered = $this->renderTwigComponent(
            name: CurrentRefinements::class,
        );

        // Term filters
        $this->assertStringContainsString('<span class="ux-search-current-refinements__value">GoPro</span>', $rendered->toString());
        $this->assertStringContainsString('<span class="ux-search-current-refinements__value">Apple</span>', $rendered->toString());
        $this->assertStringContainsString('<span class="ux-search-current-refinements__value">Samsung</span>', $rendered->toString());

        // Range filters
        $this->assertStringContainsString('<span class="ux-search-current-refinements__value">price >= 10</span>', $rendered->toString());
        $this->assertStringContainsString('<span class="ux-search-current-refinements__value">price <= 20</span>', $rendered->toString());
    }

    public function testComponentRendersWithoutActiveFilters(): void
    {
        $context = new Context();
        $context->setQuery((new Query())->setActiveFilters([]));
        $this->setCurrentContext($context);

        $rendered = $this->renderTwigComponent(
            name: CurrentRefinements::class,
        );

        $this->assertStringContainsString('<ul class="ux-search-current-refinements__list"></ul>', $rendered->toString());
        $this->assertStringNotContainsString('<span class="ux-search-current-refinements__value">', $rendered->toString());
    }
}
