<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Http;

use PHPUnit\Framework\TestCase;
use Survos\SearchBundle\Http\QueryHighlighter;

final class QueryHighlighterTest extends TestCase
{
    public function testEscapesSourceHtmlAndHighlightsLiteralTerms(): void
    {
        $result = QueryHighlighter::highlight('<script>Wages & salaries</script>', 'wage');
        self::assertSame('<mark>Wage</mark>s &amp; salaries', $result['value']);
        self::assertSame('<mark>Minimum</mark> wage &amp; benefits', QueryHighlighter::highlight('<b>Minimum wage</b> &amp; benefits', 'minimum')['value']);
        self::assertSame('&lt;img src=x onerror=alert(1)&gt; <mark>wage</mark>', QueryHighlighter::highlight('&lt;img src=x onerror=alert(1)&gt; wage', 'wage')['value']);
        self::assertSame(['Wage'], $result['matchedWords']);
        self::assertNull(QueryHighlighter::highlight('Nothing matched', 'wage'));
        self::assertNull(QueryHighlighter::highlight('Anything', '.*'));
    }
}
