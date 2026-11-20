<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Search;

/** Opt-in literal query-term highlighting for adapters without native highlights. */
interface HighlightSearchInterface
{
    /** @return list<string> Names of plain-text fields in the returned document. */
    public function getHighlightFields(): array;
}
