<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Profiler;

use Symfony\Contracts\Service\ResetInterface;

final class DuckDbQueryRecorder implements ResetInterface
{
    private array $queries = [];

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
