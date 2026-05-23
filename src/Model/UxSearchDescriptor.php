<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Model;

use Survos\FieldBundle\Entity\RouteParametersInterface;

final readonly class UxSearchDescriptor implements RouteParametersInterface, \Stringable
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

    public function __toString(): string
    {
        return $this->name;
    }

    public function getUniqueIdentifiers(): array
    {
        return ['code' => $this->code];
    }

    public function getRp(?array $addlParams = []): array
    {
        return array_merge($this->getUniqueIdentifiers(), $addlParams ?? []);
    }

    public static function getClassnamePrefix(?string $class = null): string
    {
        return 'search';
    }
}
