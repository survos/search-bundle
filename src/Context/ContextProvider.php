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

namespace Survos\SearchBundle\Context;

use Survos\SearchBundle\Exception\ContextException;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\SearchInterface;

class ContextProvider
{
    private ?Context $context = null;

    public function init(Query $query, SearchInterface $search): void
    {
        $this->context = (new Context())
            ->setQuery($query)
            ->setSearch($search)
        ;
    }

    public function hasCurrentContext(): bool
    {
        return $this->context instanceof Context;
    }

    public function getCurrentContext(): Context
    {
        if (!$this->hasCurrentContext()) {
            throw ContextException::contextNotInitialized();
        }

        return $this->context;
    }
}
