<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Survos\SearchBundle\Search\Filter\RangeFilter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Survos\SearchBundle\Search\ResultSet\FacetTermDistribution;
use Symfony\UX\TwigComponent\ComponentAttributes;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class ValueLabelBlocksTest extends TestCase
{
    private function twig(): Environment
    {
        $files = new FilesystemLoader(dirname(__DIR__, 2).'/templates');
        $twig = new Environment(new ChainLoader([new ArrayLoader([
            'facet' => "{% extends 'Facet/RefinementList.html.twig' %}{% block item_label %}Label {{ key }}{% endblock %}",
            'current' => "{% extends 'CurrentRefinements.html.twig' %}{% block term_label %}Label {{ value }}{% endblock %}",
        ]), $files]), ['strict_variables' => true, 'autoescape' => 'html']);
        // The list block needs only a target attribute. Register the remaining
        // helpers so Twig can compile the surrounding component template.
        foreach (['survos_stimulus', 'stimulus_controller', 'stimulus_target', 'stimulus_action'] as $name) {
            $twig->addFunction(new TwigFunction($name, static fn (...$args) => ''));
        }
        $twig->addFilter(new TwigFilter('stimulus_action', static fn ($value) => $value));
        $twig->addFilter(new TwigFilter('trans', static fn ($value, array $parameters = [], ?string $domain = null) => $value));
        $twig->addFunction(new TwigFunction('ux_search_is_term_filter', static fn ($filter) => $filter instanceof TermFilter));
        $twig->addFunction(new TwigFunction('ux_search_is_range_filter', static fn ($filter) => $filter instanceof RangeFilter));

        return $twig;
    }

    public function testFacetLabelOverridePreservesCodesCountsAndCheckedState(): void
    {
        $twig = $this->twig();
        $context = [
            'property' => 'category', '_rl' => 'refinement-list',
            'this' => (object) ['limit' => 10],
            'distribution' => (new FacetTermDistribution())->setValues(['MTL' => 3])->setCheckedValues(['MTL']),
        ];
        $default = $twig->load('Facet/RefinementList.html.twig')->renderBlock('list', $context);
        $custom = $twig->load('facet')->renderBlock('list', $context);
        self::assertSame(str_replace('>MTL</span>', '>Label MTL</span>', $default), $custom);
        self::assertStringContainsString('value="MTL"', $custom);
        self::assertStringContainsString('id="category-MTL" checked', $custom);
        self::assertStringContainsString('data-live-value-param="MTL"', $custom);
        self::assertStringContainsString('>3</span>', $custom);
    }

    public function testTermLabelOverridePreservesRemovalAndRangeControls(): void
    {
        $twig = $this->twig();
        $context = [
            'attributes' => new ComponentAttributes([], $twig->getRuntime(\Twig\Runtime\EscaperRuntime::class)),
            'activeFilters' => [new TermFilter('category', ['MTL']), new RangeFilter('price', 10, 20)],
        ];
        $default = $twig->render('CurrentRefinements.html.twig', $context);
        $custom = $twig->render('current', $context);
        self::assertSame(str_replace('>MTL</span>', '>Label MTL</span>', $default), $custom);
        self::assertStringContainsString('data-live-value-param="MTL"', $custom);
        self::assertStringContainsString('price >= 10', $custom);
        self::assertStringContainsString('price <= 20', $custom);
        self::assertSame(3, substr_count($custom, 'class="ux-search-current-refinements__remove"'));
    }
}
