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

namespace Survos\SearchBundle\Adapter;

use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Survos\SearchBundle\Search\SearchInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

interface AdapterInterface
{
    public function search(Query $query, SearchInterface $search): ResultSet;

    public function configureParameters(OptionsResolver $resolver): void;
}
