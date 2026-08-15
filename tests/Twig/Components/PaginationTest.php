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
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Survos\SearchBundle\Twig\Components\Pagination;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

class PaginationTest extends AbstractComponentTestCase
{
    use InteractsWithTwigComponents;

    public function testComponentRenders(): void
    {
        $context = new Context();
        $context->setQuery((new Query())->setCurrentPage(3));
        $context->setResults((new ResultSet())->setTotalResults(100));
        $this->setCurrentContext($context);

        $rendered = $this->renderTwigComponent(
            name: Pagination::class,
        );

        $this->assertStringContainsString('<li class="page-item active" aria-current="page">', $rendered->toString());
        $this->assertStringContainsString('<span class="page-link">3</span>', $rendered->toString());
        $this->assertStringContainsString('rel="prev"', $rendered->toString());
        $this->assertStringContainsString('rel="next"', $rendered->toString());
    }

    public function testComponentRendersWithoutPagination(): void
    {
        $context = new Context();
        $context->setQuery((new Query())->setCurrentPage(1));
        $context->setResults((new ResultSet())->setTotalResults(2));
        $this->setCurrentContext($context);

        $rendered = $this->renderTwigComponent(
            name: Pagination::class,
        );

        $this->assertEmpty($rendered->toString());
    }
}
