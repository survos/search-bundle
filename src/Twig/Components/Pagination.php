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

namespace Survos\SearchBundle\Twig\Components;

use Survos\SearchBundle\Context\ContextProvider;
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

class Pagination
{
    public int $range = 2;

    public function __construct(
        private readonly ContextProvider $contextProvider,
    ) {
    }

    #[ExposeInTemplate]
    public function getStartRange(): int
    {
        return max($this->contextProvider->getCurrentContext()->getQuery()->getCurrentPage() - $this->range, 1);
    }

    #[ExposeInTemplate]
    public function getEndRange(): int
    {
        $endRange = min($this->contextProvider->getCurrentContext()->getQuery()->getCurrentPage() + $this->range, $this->getTotalPage() - $this->range);

        return 0 == $endRange ? $this->getTotalPage() : $endRange;
    }

    /**
     * Pages the widget is willing to offer — which is not always every page the result count
     * implies.
     *
     * Engines cap how deep offset paging may go. Elasticsearch enforces `index.max_result_window`
     * (default 10,000) and rejects `from + size` beyond it with a hard 400, so on a 14k-document
     * index the naive page count is ~1,425 while only the first ~1,000 are actually fetchable.
     * That matters here specifically because this template always renders the *last* few page
     * numbers as links, which made the unreachable tail one click away rather than a bot-only
     * edge case.
     *
     * The cap comes from the adapter's own `maxResultWindow` parameter, so adapters without a
     * limit (the DBAL ones) are unaffected and nothing engine-specific leaks into this component.
     * This only stops the widget offering doomed links — a hand-typed `?page=2000` still reaches
     * the engine and still fails, deliberately, rather than silently serving the wrong page.
     * The real fix is cursor paging with search_after (survos/mono#42 item 7).
     */
    #[ExposeInTemplate]
    public function getTotalPage(): int
    {
        $context = $this->contextProvider->getCurrentContext();
        $results = $context->getResults();
        if (!$results instanceof ResultSet) {
            return 0;
        }

        $hitsPerPage = max(1, $context->getQuery()->getActiveHitsPerPage());
        $totalPage = (int) ceil($results->getTotalResults() / $hitsPerPage);

        $maxResultWindow = $context->hasSearch()
            ? $context->getSearch()->getResolvedAdapterParameter('maxResultWindow')
            : null;
        if (\is_int($maxResultWindow) && $maxResultWindow > 0) {
            // intdiv, not ceil: a partial final page would still put from + size over the window.
            // Floored at 1 so the range arithmetic above never goes negative.
            $totalPage = min($totalPage, max(1, intdiv($maxResultWindow, $hitsPerPage)));
        }

        return $totalPage;
    }

    #[ExposeInTemplate]
    public function getPage(): int
    {
        return $this->contextProvider->getCurrentContext()->getQuery()->getCurrentPage();
    }
}
