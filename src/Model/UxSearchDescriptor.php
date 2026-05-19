<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Model;

final readonly class UxSearchDescriptor
{
    /**
     * @param class-string $class
     */
    public function __construct(
        public string $class,
        public string $code,
        public string $name,
        public string $adapter = 'default',
        public ?string $hitTemplate = null,
        public ?string $url = null,
    ) {}
}
