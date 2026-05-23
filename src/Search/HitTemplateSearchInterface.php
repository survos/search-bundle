<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Search;

interface HitTemplateSearchInterface
{
    public function getHitTemplate(): ?string;
}
