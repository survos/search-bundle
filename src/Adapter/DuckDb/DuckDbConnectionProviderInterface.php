<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\DuckDb;

/** The application owns trusted dataset selection, credentials and read-only attachment. */
interface DuckDbConnectionProviderInterface
{
    public function name(): string;

    public function connect(array $options = []): \PDO;
}
