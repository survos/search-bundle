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

namespace Survos\SearchBundle\Tests\Twig\Components\Facet;

use Survos\SearchBundle\Context\Context;
use Survos\SearchBundle\Search\Facet;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\ResultSet\FacetTermDistribution;
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Survos\SearchBundle\Search\SearchInterface;
use Survos\SearchBundle\Tests\Twig\Components\AbstractComponentTestCase;
use Survos\SearchBundle\Twig\Components\Facet\RefinementList;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

class RefinementListTest extends AbstractComponentTestCase
{
    use InteractsWithTwigComponents;

    public function testComponentRenders(): void
    {
        $search = $this->createStub(SearchInterface::class);
        $search->method('getFacet')
            ->willReturn(new Facet('brand', 'Brand'));

        $context = new Context();
        $context->setQuery((new Query())->addActiveFilter(new TermFilter('brand')));
        $context->setSearch($search);
        $context->setResults((new ResultSet())->setFacetDistributions([
            (new FacetTermDistribution())
                ->setProperty('brand')
                ->setValues([
                    'GoPro' => 10,
                    'Apple' => 50,
                    'Samsung' => 20,
                ])
                ->setCheckedValues(['Apple']),
        ]));

        $this->setCurrentContext($context);

        $rendered = $this->renderTwigComponent(
            name: RefinementList::class,
            data: ['property' => 'brand'],
        );

        // Label
        $this->assertStringContainsString('class="ux-search-facet__title-text ux-search-refinement-list__title-text"', $rendered->toString());
        $this->assertStringContainsString('>Brand</span>', $rendered->toString());
        $this->assertStringContainsString('data-ux-search-facet-toggle', $rendered->toString());
        $this->assertStringContainsString('click->ux-search#toggleFacetCollapse', $rendered->toString());
        $this->assertStringContainsString('class="ux-search-facet__collapse ux-search-refinement-list__collapse btn btn-action btn-sm"', $rendered->toString());
        $this->assertStringContainsString('aria-expanded="true"', $rendered->toString());
        $this->assertStringContainsString('data-action="ux-search#toggleFacetCollapse"', $rendered->toString());
        $this->assertStringContainsString('data-ux-search-facet-panel', $rendered->toString());
        $this->assertStringContainsString('class="ux-search-refinement-list__sort dropdown"', $rendered->toString());
        $this->assertStringContainsString('class="ux-search-refinement-list__sort-toggle btn btn-action btn-sm dropdown-toggle"', $rendered->toString());
        $this->assertStringContainsString('title="Most Results"', $rendered->toString());
        $this->assertStringContainsString('<option value="count_desc">Most Results</option>', $rendered->toString());
        $this->assertStringContainsString('data-sort-value="count_desc"', $rendered->toString());
        $this->assertStringContainsString('data-value-type="string">A → Z</option>', $rendered->toString());
        $this->assertStringContainsString('data-sort-label="A → Z"', $rendered->toString());
        $this->assertStringContainsString('data-sort-value="number_asc" data-sort-label="Lowest First" data-value-type="number"', $rendered->toString());
        $this->assertStringContainsString('data-sort-value="date_desc" data-sort-label="Newest First" data-value-type="date"', $rendered->toString());
        $this->assertStringNotContainsString('class="ux-search-refinement-list__search-input ux-search-input form-control form-control-sm"', $rendered->toString());

        // GoPro
        $this->assertStringContainsString('<label class="ux-search-refinement-list__label" for="brand-GoPro">', $rendered->toString());
        $this->assertStringContainsString('<span class="ux-search-refinement-list__label-text" data-ux-search--refinement-list-target="label">GoPro</span>', $rendered->toString());
        $this->assertStringContainsString('<span class="ux-search-refinement-list__count" data-ux-search--refinement-list-target="count">10</span>', $rendered->toString());

        // Apple
        $this->assertStringContainsString('<label class="ux-search-refinement-list__label" for="brand-Apple">', $rendered->toString());
        $this->assertStringContainsString('<span class="ux-search-refinement-list__label-text" data-ux-search--refinement-list-target="label">Apple</span>', $rendered->toString());
        $this->assertStringContainsString('<span class="ux-search-refinement-list__count" data-ux-search--refinement-list-target="count">50</span>', $rendered->toString());
        $this->assertStringContainsString('id="brand-Apple" checked data-action="live#action"', $rendered->toString());

        // Samsung
        $this->assertStringContainsString('<label class="ux-search-refinement-list__label" for="brand-Samsung">', $rendered->toString());
        $this->assertStringContainsString('<span class="ux-search-refinement-list__label-text" data-ux-search--refinement-list-target="label">Samsung</span>', $rendered->toString());
        $this->assertStringContainsString('<span class="ux-search-refinement-list__count" data-ux-search--refinement-list-target="count">20</span>', $rendered->toString());
    }

    public function testComponentRendersSearchWhenValuesExceedLimit(): void
    {
        $search = $this->createStub(SearchInterface::class);
        $search->method('getFacet')
            ->willReturn(new Facet('brand', 'Brand', null, ['limit' => 2]));

        $context = new Context();
        $context->setQuery(new Query());
        $context->setSearch($search);
        $context->setResults((new ResultSet())->setFacetDistributions([
            (new FacetTermDistribution())
                ->setProperty('brand')
                ->setValues([
                    'GoPro' => 10,
                    'Apple' => 50,
                    'Samsung' => 20,
                ]),
        ]));

        $this->setCurrentContext($context);

        $rendered = $this->renderTwigComponent(
            name: RefinementList::class,
            data: ['property' => 'brand'],
        );

        $this->assertStringContainsString('class="ux-search-refinement-list__sort dropdown"', $rendered->toString());
        $this->assertStringContainsString('class="ux-search-refinement-list__search-input ux-search-input form-control form-control-sm"', $rendered->toString());
        $this->assertStringContainsString('data-action="input->ux-search--refinement-list#search"', $rendered->toString());
        $this->assertStringContainsString('class="ux-search-refinement-list__no-results text-secondary small"', $rendered->toString());
        $this->assertStringContainsString('data-ux-search--refinement-list-target="noResults"', $rendered->toString());
        $this->assertStringContainsString('No search results', $rendered->toString());
        $this->assertStringContainsString('class="ux-search-refinement-list__show-more"', $rendered->toString());
    }

    public function testComponentHidesControlsForSingleValue(): void
    {
        $search = $this->createStub(SearchInterface::class);
        $search->method('getFacet')
            ->willReturn(new Facet('brand', 'Brand'));

        $context = new Context();
        $context->setQuery(new Query());
        $context->setSearch($search);
        $context->setResults((new ResultSet())->setFacetDistributions([
            (new FacetTermDistribution())
                ->setProperty('brand')
                ->setValues(['Apple' => 50]),
        ]));

        $this->setCurrentContext($context);

        $rendered = $this->renderTwigComponent(
            name: RefinementList::class,
            data: ['property' => 'brand'],
        );

        $this->assertStringNotContainsString('ux-search-refinement-list__sort', $rendered->toString());
        $this->assertStringNotContainsString('ux-search-refinement-list__search-input', $rendered->toString());
    }

    public function testComponentCanRenderInitiallyCollapsed(): void
    {
        $search = $this->createStub(SearchInterface::class);
        $search->method('getFacet')
            ->willReturn(new Facet('brand', 'Brand', null, ['collapsed' => true]));

        $context = new Context();
        $context->setQuery(new Query());
        $context->setSearch($search);
        $context->setResults((new ResultSet())->setFacetDistributions([
            (new FacetTermDistribution())
                ->setProperty('brand')
                ->setValues([
                    'Apple' => 50,
                    'Samsung' => 20,
                ]),
        ]));

        $this->setCurrentContext($context);

        $rendered = $this->renderTwigComponent(
            name: RefinementList::class,
            data: ['property' => 'brand'],
        );

        $this->assertStringContainsString('data-ux-search-facet-collapsed="true"', $rendered->toString());
        $this->assertStringContainsString('aria-expanded="false"', $rendered->toString());
        $this->assertStringContainsString('id="brand-facet-panel"', $rendered->toString());
        $this->assertStringContainsString('hidden', $rendered->toString());
        $this->assertStringContainsString('Expand Brand', $rendered->toString());
    }
}
