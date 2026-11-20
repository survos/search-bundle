<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\DependencyInjection;

use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;
use Survos\FieldBundle\Registry\EntityMetaRegistry;
use Survos\SearchBundle\Compiler\AutoEntitySearchPass;
use Survos\SearchBundle\Registry\UxSearchRegistry;
use Survos\SearchBundle\Search\SearchProvider;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class AutoEntitySearchPassTest extends TestCase
{
    public function testExplicitSearchWinsAndStillHasAnEntityDescriptor(): void
    {
        $container = $this->container();
        $container->register('explicit')->addTag('survos_search.search', [
            'name' => 'bill', 'index' => DuckBillFixture::class, 'adapter' => 'duckdb',
        ]);
        $container->getDefinition(SearchProvider::class)->setArgument('$searches', new IteratorArgument(['bill' => new Reference('explicit')]));
        (new AutoEntitySearchPass())->process($container);
        self::assertFalse($container->hasDefinition('survos.search.auto_entity.bill'));
        $searches = $container->getDefinition(SearchProvider::class)->getArgument('$searches')->getValues();
        self::assertSame('explicit', (string) $searches['bill']);
        $descriptors = $container->getDefinition(UxSearchRegistry::class)->getArgument('$descriptors');
        self::assertCount(1, $descriptors);
        self::assertSame('duckdb', $descriptors[0]->getArgument('$adapter'));
    }

    public function testAutomaticSearchStillRegistersWithoutAnOverride(): void
    {
        $container = $this->container();
        (new AutoEntitySearchPass())->process($container);
        self::assertTrue($container->hasDefinition('survos.search.auto_entity.bill'));
        $searches = $container->getDefinition(SearchProvider::class)->getArgument('$searches')->getValues();
        self::assertSame('survos.search.auto_entity.bill', (string) $searches['bill']);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(EntityMetaRegistry::class)->setArgument('$descriptors', [
            new Definition(\stdClass::class, ['$class' => DuckBillFixture::class, '$code' => 'bill']),
        ]);
        $container->register(SearchProvider::class)->setArgument('$searches', new IteratorArgument([]));
        $container->register(UxSearchRegistry::class);

        return $container;
    }
}

final class DuckBillFixture
{
    #[ORM\Column]
    public int $id;
}
