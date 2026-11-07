<?php

declare(strict_types=1);

namespace Survos\SearchBundle\DependencyInjection\Compiler;

use Survos\SearchBundle\Profiler\DuckDbDataCollector;
use Survos\SearchBundle\Profiler\DuckDbQueryRecorder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** Prefer the shared PDO profiler, while keeping standalone search profiling available. */
final class DuckDbProfilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(DuckDbQueryRecorder::class)
            || !$container->hasDefinition(\Survos\DuckDbBundle\Debug\DuckDbDataCollector::class)
            || !$container->hasDefinition(\Survos\DuckDbBundle\Debug\QueryRecorder::class)) {
            return;
        }
        $container->getDefinition(DuckDbQueryRecorder::class)->setArgument('$shared', new Reference(\Survos\DuckDbBundle\Debug\QueryRecorder::class));
        $container->removeDefinition(DuckDbDataCollector::class);
    }
}
