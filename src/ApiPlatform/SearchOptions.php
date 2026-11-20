<?php

declare(strict_types=1);

namespace Survos\SearchBundle\ApiPlatform;

use ApiPlatform\State\OptionsInterface;

/** Trusted operation configuration: callers cannot select an arbitrary search. */
final readonly class SearchOptions implements OptionsInterface
{
    public function __construct(public string $search)
    {
    }
}
