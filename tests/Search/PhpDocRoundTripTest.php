<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Search;

use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\ConstructorExtractor;
use Symfony\Component\PropertyInfo\Extractor\PhpStanExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class PhpDocRoundTripTest extends TestCase
{
    public function testPolymorphicFiltersAndSparseValuesRoundTrip(): void
    {
        $query = new \Survos\SearchBundle\Search\Query();
        $term = new \Survos\SearchBundle\Search\Filter\TermFilter('category', ['one', 'two']);
        $term->removeValue('one');
        $query->addActiveFilter($term);
        $query->addActiveFilter(new \Survos\SearchBundle\Search\Filter\RangeFilter('year', 1900, 2000));
        $serializer = $this->serializer();
        $copy = $serializer->deserialize($serializer->serialize($query, 'json'), $query::class, 'json');
        self::assertInstanceOf(\Survos\SearchBundle\Search\Filter\TermFilter::class, $copy->getActiveFilter('category'));
        self::assertInstanceOf(\Survos\SearchBundle\Search\Filter\RangeFilter::class, $copy->getActiveFilter('year'));
        self::assertSame([1 => 'two'], $copy->getActiveFilter('category')->getValues());
    }

    public function testHitsAndNumericFacetKeysRoundTrip(): void
    {
        $result = new \Survos\SearchBundle\Search\ResultSet\ResultSet();
        $result->setHits([4 => new \Survos\SearchBundle\Search\ResultSet\Hit(['title' => 'Example'], 1.0)]);
        $facet = (new \Survos\SearchBundle\Search\ResultSet\FacetTermDistribution())->setProperty('year')->setValues([1900 => 3]);
        $result->setFacetDistributions([$facet]);
        $serializer = $this->serializer();
        $copy = $serializer->deserialize($serializer->serialize($result, 'json'), $result::class, 'json');
        self::assertInstanceOf(\Survos\SearchBundle\Search\ResultSet\Hit::class, $copy->getHits()[4]);
        self::assertSame(['title' => 'Example'], $copy->getHits()[4]->getData());
        self::assertSame([1900 => 3], $copy->getFacetDistribution('year')->getValues());
    }

    private function serializer(): Serializer
    {
        // These are integration checks for consuming apps with PHPDoc-aware serialization enabled.
        if (!class_exists(PhpStanExtractor::class)
            || !class_exists(\PHPStan\PhpDocParser\Parser\PhpDocParser::class)
            || !class_exists(\phpDocumentor\Reflection\Types\ContextFactory::class)
            || !class_exists(Serializer::class)) {
            self::markTestSkipped('Run with a consuming app providing Serializer, PropertyInfo and the PHPDoc parsers.');
        }

        $phpDoc = new PhpStanExtractor();
        $reflection = new ReflectionExtractor();
        $types = new PropertyInfoExtractor([], [new ConstructorExtractor([$phpDoc]), $phpDoc, $reflection]);

        return new Serializer([
            new ObjectNormalizer(new ClassMetadataFactory(new AttributeLoader()), propertyTypeExtractor: $types),
            new ArrayDenormalizer(),
        ], [new JsonEncoder()]);
    }
}
