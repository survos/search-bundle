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

namespace Survos\SearchBundle\Tests\Context;

use Survos\SearchBundle\Context\ContextProvider;
use Survos\SearchBundle\Exception\ContextException;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Survos\SearchBundle\Search\Searcher;
use Survos\SearchBundle\Search\SearchInterface;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;

class ContextProviderTest extends TestCase
{
    private ContextProvider $contextProvider;

    private SearchInterface $search;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        $searcher = $this->createStub(Searcher::class);
        $searcher->method('search')->willReturn(new ResultSet());

        $this->search = $this->createStub(SearchInterface::class);
        $this->search->method('getIndexName')->willReturn('test');

        $this->contextProvider = new ContextProvider();
    }

    public function testInit(): void
    {
        $query = new Query();
        $this->contextProvider->init($query, $this->search);

        $this->assertTrue($this->contextProvider->hasCurrentContext());
        $this->assertSame($query, $this->contextProvider->getCurrentContext()->getQuery());
        $this->assertSame($this->search, $this->contextProvider->getCurrentContext()->getSearch());
        $this->assertNull($this->contextProvider->getCurrentContext()->getResults());
    }

    public function testBeforeInit(): void
    {
        $this->assertFalse($this->contextProvider->hasCurrentContext());

        $this->expectException(ContextException::class);

        $this->contextProvider->getCurrentContext();
    }
}
