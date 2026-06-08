<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Compiler;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\Persistence\ManagerRegistry;
use Mezcalito\UxSearchBundle\Search\SearchProvider;
use Survos\FieldBundle\Registry\EntityMetaRegistry;
use Survos\SearchBundle\Model\UxSearchDescriptor;
use Survos\SearchBundle\Registry\UxSearchRegistry;
use Survos\SearchBundle\Search\AutoEntitySearch;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class AutoEntitySearchPass implements CompilerPassInterface
{
    private const array SCALAR_TYPES = [
        Types::ASCII_STRING,
        Types::BIGINT,
        Types::BOOLEAN,
        Types::DATE_IMMUTABLE,
        Types::DATE_MUTABLE,
        Types::DATETIME_IMMUTABLE,
        Types::DATETIME_MUTABLE,
        Types::DATETIMETZ_IMMUTABLE,
        Types::DATETIMETZ_MUTABLE,
        Types::DECIMAL,
        Types::FLOAT,
        Types::GUID,
        Types::INTEGER,
        Types::SMALLINT,
        Types::STRING,
        Types::TEXT,
        Types::TIME_IMMUTABLE,
        Types::TIME_MUTABLE,
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(EntityMetaRegistry::class)) {
            return;
        }

        $registryDefinition = $container->getDefinition(EntityMetaRegistry::class);
        try {
            $entityDescriptors = $registryDefinition->getArgument('$descriptors');
        } catch (\OutOfBoundsException) {
            try {
                $entityDescriptors = $registryDefinition->getArgument(0);
            } catch (\OutOfBoundsException) {
                return;
            }
        }
        if (!is_array($entityDescriptors)) {
            return;
        }

        $defaultAdapterDsn = $this->defaultAdapterDsn($container);
        $uxDescriptors = [];
        $newSearches = [];

        foreach ($entityDescriptors as $descriptorDefinition) {
            if (!$descriptorDefinition instanceof Definition) {
                continue;
            }

            $class = $descriptorDefinition->getArgument('$class');
            $code = $descriptorDefinition->getArgument('$code');
            if (!is_string($class) || !is_string($code) || !class_exists($class)) {
                continue;
            }

            $fieldNames = $this->doctrineScalarFields($class);
            if ($fieldNames === []) {
                continue;
            }

            $serviceId = 'survos.search.auto_entity.' . $code;
            $container->setDefinition(
                $serviceId,
                (new Definition(AutoEntitySearch::class))
                    ->setAutowired(true)
                    ->setAutoconfigured(true)
                    ->setPublic(false)
                    ->setArgument('$entityClass', $class)
                    ->setArgument('$fieldNames', $fieldNames)
                    ->setArgument('$managerRegistry', new Reference(ManagerRegistry::class))
                    ->setArgument('$defaultAdapterDsn', $defaultAdapterDsn)
                    ->addTag('mezcalito_ux_search.search', [
                        'index' => $class,
                        'name' => $code,
                        'adapter' => null,
                    ])
                    ->addTag('kernel.reset', ['method' => 'reset'])
            );
            $newSearches[$code] = new Reference($serviceId);

            $uxDescriptors[] = new Definition(UxSearchDescriptor::class, [
                '$class' => $class,
                '$code' => $code,
                '$name' => $code,
                '$adapter' => 'default',
                '$hitTemplate' => $this->hitTemplate($container, $class, $code),
                '$url' => null,
            ]);
        }

        $container->getDefinition(UxSearchRegistry::class)
            ->setArgument('$descriptors', $uxDescriptors);

        if ($newSearches !== [] && $container->hasDefinition(SearchProvider::class)) {
            $providerDef = $container->getDefinition(SearchProvider::class);
            $existingArg = $providerDef->getArgument('$searches');
            $existing = $existingArg instanceof IteratorArgument ? $existingArg->getValues() : [];
            $providerDef->setArgument('$searches', new IteratorArgument(array_merge($existing, $newSearches)));
        }
    }


    private function hitTemplate(ContainerBuilder $container, string $class, string $code): string
    {
        $shortName = (new \ReflectionClass($class))->getShortName();
        $shortCode = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));
        $projectDir = $container->hasParameter('kernel.project_dir') ? (string) $container->getParameter('kernel.project_dir') : null;

        foreach (array_unique([$code, $shortCode]) as $candidate) {
            $template = sprintf('search/hits/%s.html.twig', $candidate);
            if ($projectDir === null || is_file($projectDir . '/templates/' . $template)) {
                return $template;
            }
        }

        return sprintf('search/hits/%s.html.twig', $code);
    }

    private function defaultAdapterDsn(ContainerBuilder $container): ?string
    {
        if (!$container->hasParameter('mezcalito_ux_search.default_adapter') || !$container->hasParameter('mezcalito_ux_search.adapters')) {
            return null;
        }

        $defaultAdapter = (string) $container->getParameter('mezcalito_ux_search.default_adapter');
        $adapters = $container->getParameter('mezcalito_ux_search.adapters');
        if (!is_array($adapters)) {
            return null;
        }

        $adapterConfig = $adapters[$defaultAdapter] ?? null;
        if (is_string($adapterConfig)) {
            return $adapterConfig;
        }

        return is_array($adapterConfig) && is_string($adapterConfig['dsn'] ?? null)
            ? $adapterConfig['dsn']
            : null;
    }

    /**
     * @param class-string $class
     * @return string[]
     */
    private function doctrineScalarFields(string $class): array
    {
        $fields = [];
        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            $attributes = $property->getAttributes(Column::class);
            if ($attributes === []) {
                continue;
            }

            $column = $attributes[0]->newInstance();
            $type = $column->type;
            if ($type === null) {
                $type = $this->phpType($property) ?? Types::STRING;
            }

            if (in_array($type, self::SCALAR_TYPES, true)) {
                $fields[] = $property->getName();
            }
        }

        return $fields;
    }

    private function phpType(\ReflectionProperty $property): ?string
    {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return null;
        }

        return match ($type->getName()) {
            'int' => Types::INTEGER,
            'float' => Types::FLOAT,
            'bool' => Types::BOOLEAN,
            default => Types::STRING,
        };
    }
}
