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
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Survos\SearchBundle\Twig\Components\TotalHits;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

class TotalHitsTest extends AbstractComponentTestCase
{
    use InteractsWithTwigComponents;

    public function testComponentRenders(): void
    {
        $context = new Context();
        $context->setResults((new ResultSet())->setTotalResults(999));
        $this->setCurrentContext($context);

        $rendered = $this->renderTwigComponent(
            name: TotalHits::class,
        );

        $this->assertStringContainsString('999', $rendered->toString());
    }
}
