<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Survos\SearchBundle\Context\ContextProvider;
use Survos\SearchBundle\Search\AbstractSearch;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\ResultSet\FacetStat;
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Survos\SearchBundle\Twig\Components\Facet;
use Survos\SearchBundle\Twig\Components\Facet\RangeSlider;

final class FacetAvailabilityTest extends TestCase
{
    public function testEmptyRangeDoesNotRenderButTermsStillDo(): void
    {
        $search = new class extends AbstractSearch {};
        $search->addFacet('year', 'Year', RangeSlider::class)->addFacet('kind', 'Kind');
        $context = new ContextProvider();
        $context->init(new Query(), $search);
        $result = new ResultSet();
        $context->getCurrentContext()->setResults($result);
        $facet = new Facet($context);
        $facet->property = 'year';
        self::assertFalse($facet->getRenderable());
        $result->setFacetStats(['year' => new FacetStat('year', 1900, 2000)]);
        self::assertTrue($facet->getRenderable());
        $facet->property = 'kind';
        self::assertTrue($facet->getRenderable());
    }
}
