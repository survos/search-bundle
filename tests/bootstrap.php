<?php

declare(strict_types=1);

/*
 * Minimal PSR-4 autoloader for running the bundle's tests without a full
 * `composer install`. The classes under test (e.g. Fts5MatchQuery) are
 * dependency-free, so a real autoloader/vendor tree is not required here.
 */
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Survos\\SearchBundle\\Tests\\' => __DIR__ . '/',
        'Survos\\SearchBundle\\' => __DIR__ . '/../src/',
    ];

    foreach ($prefixes as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $path = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }

        return;
    }
});
