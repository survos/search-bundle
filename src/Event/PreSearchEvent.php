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

namespace Survos\SearchBundle\Event;

use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\SearchInterface;
use Symfony\Contracts\EventDispatcher\Event;

class PreSearchEvent extends Event
{
    public function __construct(
        private readonly Query $query,
        private readonly SearchInterface $search,
    ) {
    }

    public function getQuery(): Query
    {
        return $this->query;
    }

    public function getSearch(): SearchInterface
    {
        return $this->search;
    }
}
