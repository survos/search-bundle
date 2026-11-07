<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Profiler;

use Symfony\Contracts\Service\ResetInterface;

final class DuckDbQueryRecorder implements ResetInterface
{
    private array $queries = [];

    public function __construct(private readonly ?\Survos\DuckDbBundle\Debug\QueryRecorder $shared = null)
    {
    }

    public function measure(\PDO $db, string $dataset, string $sql, array $parameters, \Closure $call): \PDOStatement
    {
        if (null !== $this->shared) {
            // A traceable connection already records prepare/execute; never count it twice.
            return $db instanceof \Survos\DuckDbBundle\Debug\TraceablePdo
                ? $call()
                : $this->shared->measure($dataset, 'search', $sql, $call);
        }
        $start = hrtime(true);
        $error = null;
        try {
            return $call();
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
            throw $exception;
        } finally {
            $this->record($dataset, $sql, $parameters, (hrtime(true) - $start) / 1e6, $error);
        }
    }

    public function record(string $dataset, string $sql, array $parameters, float $milliseconds, ?string $error): void
    {
        $this->queries[] = compact('dataset', 'sql', 'parameters', 'milliseconds', 'error');
    }

    public function all(): array
    {
        return $this->queries;
    }

    public function reset(): void
    {
        $this->queries = [];
    }
}
