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
use Survos\SearchBundle\Search\ResultSet\Hit;
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Survos\SearchBundle\Search\SearchInterface;
use Survos\SearchBundle\Twig\Components\Hits;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

class HitsTest extends AbstractComponentTestCase
{
    use InteractsWithTwigComponents;

    public function testComponentRenders(): void
    {
        $context = new Context();
        $context->setResults((new ResultSet())->setHits([
            new Hit([
                'objectID' => 'result1',
                'name' => 'Result 1',
            ], 1.0),
            new Hit([
                'objectID' => 'result2',
                'name' => 'Result 2',
            ], 1.0),
        ]));
        $context->setSearch($this->createStub(SearchInterface::class));
        $this->setCurrentContext($context);

        $rendered = $this->renderTwigComponent(
            name: Hits::class,
        );

        $this->assertStringContainsString('Result 1', $rendered->toString());
        $this->assertStringContainsString('Result 2', $rendered->toString());
    }

    public function testComponentRendersWithEmptyHits(): void
    {
        $context = new Context();
        $context->setResults((new ResultSet())->setHits([]));
        $context->setSearch($this->createStub(SearchInterface::class));
        $this->setCurrentContext($context);

        $rendered = $this->renderTwigComponent(
            name: Hits::class,
        );

        $this->assertStringContainsString('No result', $rendered->toString());
    }
}
