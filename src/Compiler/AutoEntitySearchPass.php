<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Compiler;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\Persistence\ManagerRegistry;
use Survos\SearchBundle\Search\SearchProvider;
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

    /**
     * Multi-valued columns. Not scalars, but a search engine that buckets each element of
     * an array (Elasticsearch, Meilisearch) can facet them meaningfully -- and they're
     * often the *most* interesting facets (phpVersions, symfonyVersions, keywords).
     * Admitting them here only makes them visible to FieldSearchConfigurator; whether a
     * given field is searchable, sortable, or a facet is still decided by its #[Field]
     * descriptor.
     */
    private const array MULTI_VALUE_TYPES = [
        Types::JSON,
        Types::SIMPLE_ARRAY,
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

        $entityAdapters = $container->hasParameter('survos_search.entity_adapters')
            ? (array) $container->getParameter('survos_search.entity_adapters')
            : [];
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
                    // adapter: null means "the app default", resolved at runtime by
                    // AdapterProvider. It used to be baked in here as a DSN string read at
                    // compile time, which forced survos_search.default_adapter to be a literal
                    // -- an %env() placeholder silently produced a null DSN and every search
                    // fell back to Doctrine with no error.
                    ->addTag('survos_search.search', [
                        'index' => $class,
                        'name' => $code,
                        'adapter' => $entityAdapters[$code] ?? null,
                    ])
                    ->addTag('kernel.reset', ['method' => 'reset'])
            );
            $newSearches[$code] = new Reference($serviceId);

            $uxDescriptors[] = new Definition(UxSearchDescriptor::class, [
                '$class' => $class,
                '$code' => $code,
                '$name' => $code,
                '$adapter' => $entityAdapters[$code] ?? 'default',
                '$hitTemplate' => $this->hitTemplate($container, $class, $code),
                '$url' => null,
            ]);
        }

        // Hand-written #[AsSearch] classes are registered in SearchProvider by
        // RegisterSearchPass, but until now they never reached UxSearchRegistry -- so
        // survos:search:index, AutoSearchController and the admin menu could not see them,
        // and neither could survos/elastic-bundle's index and postFlush reconcile. Anything
        // whose index is a real class gets a descriptor too.
        $seen = [];
        foreach ($uxDescriptors as $descriptor) {
            $seen[$descriptor->getArgument('$code')] = true;
        }

        foreach ($container->findTaggedServiceIds('survos_search.search') as $serviceId => $tags) {
            foreach ($tags as $tag) {
                $name = $tag['name'] ?? null;
                $index = $tag['index'] ?? null;
                if (!is_string($name) || isset($seen[$name])) {
                    continue;
                }
                // index may be a free-form string (folio_row) rather than an entity class;
                // without a class there is nothing to load documents from, so skip it.
                if (!is_string($index) || !class_exists($index)) {
                    continue;
                }

                $seen[$name] = true;
                $uxDescriptors[] = new Definition(UxSearchDescriptor::class, [
                    '$class' => $index,
                    '$code' => $name,
                    '$name' => $name,
                    '$adapter' => $tag['adapter'] ?? 'default',
                    '$hitTemplate' => $this->hitTemplate($container, $index, $name),
                    '$url' => null,
                ]);
            }
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

            if (in_array($type, self::SCALAR_TYPES, true) || in_array($type, self::MULTI_VALUE_TYPES, true)) {
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
            // An untyped #[ORM\Column] on an ?array property is a json column; calling it
            // a string admitted it to the field list for the wrong reason and mislabelled
            // it for anything that reads this type back.
            'array' => Types::JSON,
            default => Types::STRING,
        };
    }
}
