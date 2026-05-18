<?php

declare(strict_types=1);

namespace Survos\SearchBundle\DependencyInjection;

use Mezcalito\UxSearchBundle\Adapter\AdapterProvider;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class UxSearchAdapterPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(AdapterProvider::class)) {
            return;
        }

        $factories = [];
        foreach ($container->findTaggedServiceIds('mezcalito_ux_search.adapter_factory') as $id => $_tags) {
            $factories[] = new Reference($id);
        }

        $container
            ->getDefinition(AdapterProvider::class)
            ->setArgument('$factories', new IteratorArgument($factories));
    }
}
