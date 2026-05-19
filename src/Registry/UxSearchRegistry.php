<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Registry;

use Survos\SearchBundle\Model\UxSearchDescriptor;

final class UxSearchRegistry
{
    /** @var array<class-string, UxSearchDescriptor> */
    private readonly array $byClass;

    /** @var array<string, UxSearchDescriptor> */
    private readonly array $byCode;

    /** @param UxSearchDescriptor[] $descriptors */
    public function __construct(private readonly array $descriptors = [])
    {
        $byClass = [];
        $byCode = [];

        foreach ($descriptors as $descriptor) {
            $byClass[$descriptor->class] = $descriptor;
            $byCode[$descriptor->code] = $descriptor;
        }

        $this->byClass = $byClass;
        $this->byCode = $byCode;
    }

    /** @return UxSearchDescriptor[] */
    public function all(): array
    {
        return $this->descriptors;
    }

    public function forClass(string $class): ?UxSearchDescriptor
    {
        return $this->byClass[$class] ?? null;
    }

    public function forCode(string $code): ?UxSearchDescriptor
    {
        return $this->byCode[$code] ?? null;
    }
}
