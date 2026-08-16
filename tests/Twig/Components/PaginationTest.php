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
use Survos\SearchBundle\Search\SearchInterface;
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

    /**
     * Elasticsearch rejects `from + size` past index.max_result_window with a 400, and this
     * template always renders the last few page numbers as links — so without a clamp the
     * unreachable tail of a >10k-document index is one click away.
     *
     * 14,244 documents at 10 per page implies 1,425 pages; only 1,000 are fetchable
     * (page 1000 is from=9990, size=10, exactly the boundary verified against a live 9.5.0 node).
     */
    public function testPageCountIsClampedToTheAdaptersResultWindow(): void
    {
        $context = new Context();
        $context->setQuery((new Query())->setCurrentPage(999)->setActiveHitsPerPage(10));
        $context->setResults((new ResultSet())->setTotalResults(14244));
        $context->setSearch($this->searchWithResultWindow(10000));
        $this->setCurrentContext($context);

        $rendered = $this->renderTwigComponent(name: Pagination::class)->toString();

        $this->assertStringContainsString('data-live-page-param="1000"', $rendered, 'the last fetchable page is still offered');
        $this->assertStringNotContainsString('data-live-page-param="1001"', $rendered);
        $this->assertStringNotContainsString('data-live-page-param="1425"', $rendered, 'the naive page count must not be linked');
    }

    /** An adapter with no window (the DBAL ones) keeps every page. */
    public function testPageCountIsUntouchedWithoutAResultWindow(): void
    {
        $context = new Context();
        $context->setQuery((new Query())->setCurrentPage(1424)->setActiveHitsPerPage(10));
        $context->setResults((new ResultSet())->setTotalResults(14244));
        $context->setSearch($this->searchWithResultWindow(null));
        $this->setCurrentContext($context);

        $rendered = $this->renderTwigComponent(name: Pagination::class)->toString();

        $this->assertStringContainsString('data-live-page-param="1425"', $rendered);
    }

    /** Contexts that never set a search must not fatal on the uninitialized typed property. */
    public function testMissingSearchIsTolerated(): void
    {
        $context = new Context();
        $context->setQuery((new Query())->setCurrentPage(3));
        $context->setResults((new ResultSet())->setTotalResults(100));
        $this->setCurrentContext($context);

        $this->assertFalse($context->hasSearch());
        $this->assertNotEmpty($this->renderTwigComponent(name: Pagination::class)->toString());
    }

    private function searchWithResultWindow(?int $window): SearchInterface
    {
        $search = $this->createStub(SearchInterface::class);
        $search->method('getResolvedAdapterParameter')
            ->willReturnCallback(static fn (string $name): mixed => 'maxResultWindow' === $name ? $window : null);

        return $search;
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
