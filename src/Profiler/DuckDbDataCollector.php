<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Profiler;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DuckDbDataCollector extends AbstractDataCollector
{
    public function __construct(private readonly DuckDbQueryRecorder $recorder)
    {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = ['queries' => $this->recorder->all()];
    }

    public function getQueries(): array
    {
        return $this->data['queries'] ?? [];
    }

    public function getCount(): int
    {
        return count($this->getQueries());
    }

    public function getDuration(): float
    {
        return array_sum(array_column($this->getQueries(), 'milliseconds'));
    }

    public function getName(): string
    {
        return 'survos_duckdb';
    }

    public static function getTemplate(): ?string
    {
        return '@SurvosSearch/Profiler/duckdb.html.twig';
    }

    public function reset(): void
    {
        $this->data = [];
        $this->recorder->reset();
    }
}
