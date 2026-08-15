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

namespace Survos\SearchBundle\Tests\TestApplication\Search;

use Doctrine\ORM\QueryBuilder;
use Survos\SearchBundle\Adapter\Doctrine\DoctrineAdapter;
use Survos\SearchBundle\Attribute\AsSearch;
use Survos\SearchBundle\Search\AbstractSearch;
use Survos\SearchBundle\Tests\TestApplication\Entity\Product;
use Survos\SearchBundle\Twig\Components\Facet\RangeInput;

#[AsSearch(Product::class, adapter: 'doctrine')]
class DoctrineSearch extends AbstractSearch
{
    public function build(array $options = []): void
    {
        $this
            ->setAdapterParameters([
                DoctrineAdapter::MAX_FACET_VALUES_PARAM => 30,
                DoctrineAdapter::QUERY_BUILDER_ALIAS => 'o',
                DoctrineAdapter::QUERY_BUILDER => static function (QueryBuilder $queryBuilder) {
                    $queryBuilder->andWhere('1 = 1');
                },
                DoctrineAdapter::SEARCH_FIELDS => ['o.name', 'o.brand'],
            ])
            ->addFacet('o.type', 'Type')
            ->addFacet('o.brand', 'Brand')
            ->addFacet('o.rating', 'Rating')
            ->addFacet('o.priceRange', 'Price range')
            ->addFacet('o.price', 'Price', RangeInput::class)
            ->addAvailableSort('o.price:asc', 'Price ↑')
            ->addAvailableSort('o.price:desc', 'Price ↓')
            ->addAvailableSort('o.popularity:asc', 'Popularity ↑')
            ->addAvailableSort('o.popularity:desc', 'Popularity ↓')
            ->enableUrlRewriting()
        ;
    }
}
