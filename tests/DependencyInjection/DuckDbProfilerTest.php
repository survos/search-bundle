<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Survos\DuckDbBundle\Debug\QueryRecorder;
use Survos\DuckDbBundle\Debug\TraceablePdo;
use Survos\SearchBundle\DependencyInjection\Compiler\DuckDbProfilerPass;
use Survos\SearchBundle\Profiler\DuckDbDataCollector;
use Survos\SearchBundle\Profiler\DuckDbQueryRecorder;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class DuckDbProfilerTest extends TestCase
{
    public function testStandaloneCollectorIsRetainedUntilSharedProfilerIsEnabled(): void
    {
        $container = new ContainerBuilder();
        $container->register(DuckDbQueryRecorder::class);
        $container->register(DuckDbDataCollector::class);
        $pass = new DuckDbProfilerPass();
        $pass->process($container);
        self::assertTrue($container->hasDefinition(DuckDbDataCollector::class));
        $container->register(QueryRecorder::class);
        $container->register(\Survos\DuckDbBundle\Debug\DuckDbDataCollector::class);
        $pass->process($container);
        self::assertFalse($container->hasDefinition(DuckDbDataCollector::class));
        self::assertSame(QueryRecorder::class, (string) $container->getDefinition(DuckDbQueryRecorder::class)->getArgument('$shared'));
    }

    public function testSharedProfilerRecordsRawPdoButDoesNotDoubleCountTracedPdo(): void
    {
        $shared = new QueryRecorder();
        $search = new DuckDbQueryRecorder($shared);
        foreach ([new \PDO('duckdb::memory:'), new TraceablePdo('duckdb::memory:', $shared, 'fixture')] as $db) {
            $shared->reset();
            $call = static function () use ($db): \PDOStatement {
                $statement = $db->prepare('SELECT ?');
                $statement->execute([42]);

                return $statement;
            };
            self::assertSame(42, (int) $search->measure($db, 'fixture', 'SELECT ?', [42], $call)->fetchColumn());
            self::assertSame($db instanceof TraceablePdo ? ['prepare', 'execute'] : ['search'], array_column($shared->snapshot()['queries'], 'operation'));
            self::assertSame([], $search->all());
        }
    }
}
