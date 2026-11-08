<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Search;

use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;
use Survos\FieldBundle\Service\FieldReader;
use Survos\SearchBundle\Search\AutoEntitySearch;
use Survos\SearchBundle\Service\FieldSearchConfigurator;

final class AutoEntitySortLabelsTest extends TestCase
{
    public function testConstantSortsUsePropertyAndDoctrineTypes(): void
    {
        $search = new AutoEntitySearch(SortLabelFixture::class, []);
        $search->setFieldSearchConfigurator(new FieldSearchConfigurator(new FieldReader()));
        $search->build();
        $labels = [];
        foreach ($search->getAvailableSorts() as $sort) {
            $labels[$sort->getKey()] = $sort->getLabel();
        }
        self::assertSame('Stars Low-High', $labels['stars:asc']);
        self::assertSame('Stars High-Low', $labels['stars:desc']);
        self::assertSame('Price Low-High', $labels['price:asc']);
        self::assertSame('Updated Old-New', $labels['updated:asc']);
        self::assertSame('Updated New-Old', $labels['updated:desc']);
        self::assertSame('Name A-Z', $labels['name:asc']);
        self::assertSame('Name Z-A', $labels['name:desc']);
    }
}

final class SortLabelFixture
{
    public const array SORTABLE_FIELDS = ['stars', 'price', 'updated', 'name'];
    public ?int $stars = null;
    #[ORM\Column(type: 'decimal')]
    public ?string $price = null;
    public ?\DateTimeImmutable $updated = null;
    public string $name = '';
}
