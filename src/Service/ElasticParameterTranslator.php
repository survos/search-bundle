<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Service;

use Doctrine\Persistence\ManagerRegistry;
use Survos\SearchBundle\Adapter\AdapterInterface;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchAdapter;
use Survos\SearchBundle\Search\SearchInterface;

/**
 * Rewrites DBAL-shaped adapter parameters into Elasticsearch's shape.
 *
 * FieldSearchConfigurator speaks in columns -- facetColumns, sortColumns, searchFields -- which
 * is what the DBAL adapters want. Elasticsearch wants facetFields, sortFields, mappings,
 * sourceFields and idField, and rejects the column-shaped ones outright:
 *
 *   The options "facetColumns", "idColumn", "matchExpression", ... do not exist.
 *
 * This translation used to live as a private method on AutoEntitySearch, so only the automatic
 * path could target Elasticsearch and any hand-written AbstractFieldSearch threw. It is a
 * service now so both paths share one implementation.
 */
final readonly class ElasticParameterTranslator implements ParameterTranslatorInterface
{
    public function supports(AdapterInterface $adapter): bool
    {
        return class_exists(ElasticsearchAdapter::class) && $adapter instanceof ElasticsearchAdapter;
    }

    public function columnPrefix(): ?string
    {
        return null;
    }

    /** Column-shaped keys the Elasticsearch adapter does not define. */
    private const array DBAL_ONLY_KEYS = [
        'facetColumns', 'sortColumns', 'table', 'idColumn', 'selectColumns',
        'ftsTable', 'matchExpression', 'scoreExpression',
    ];

    public function __construct(private ?ManagerRegistry $managerRegistry = null) {}

    /**
     * @param class-string $entityClass
     */
    public function translate(SearchInterface $search, string $entityClass, AdapterInterface $adapter): void
    {
        $manager = $this->managerRegistry?->getManagerForClass($entityClass);
        if ($manager === null) {
            return;
        }

        $metadata = $manager->getClassMetadata($entityClass);
        $parameters = $search->getAdapterParameters();

        $parameters['searchFields'] = $this->plainFields($parameters['searchFields'] ?? []);
        $facetColumns = $parameters['facetColumns'] ?? [];
        $sortColumns = $parameters['sortColumns'] ?? [];
        $parameters['facetFields'] = [];
        $parameters['sortFields'] = [];
        $parameters['mappings'] = [];

        $includedFields = [];
        foreach ($metadata->getFieldNames() as $field) {
            $type = $metadata->getTypeOfField($field);
            $isText = in_array($type, ['string', 'text', 'ascii_string'], true);

            // A json column is either an array of scalars -- often the most useful facet there
            // is (phpVersions, keywords) -- or a nested object blob. Doctrine reports both as
            // 'json', and mapping a blob as `keyword` makes Elasticsearch reject the whole bulk
            // request ("Expected text but found START_OBJECT"). We cannot tell them apart from
            // metadata, so json is opt-in: included only when explicitly named as a facet or a
            // search field.
            if (in_array($type, ['json', 'jsonb', 'array', 'simple_array'], true)
                && !array_key_exists($field, $facetColumns)
                && !in_array($field, $parameters['searchFields'], true)
            ) {
                continue;
            }

            $includedFields[] = $field;
            $parameters['mappings'][$field] = $isText
                ? ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 512]]]
                : ['type' => match ($type) {
                    'integer', 'smallint', 'bigint' => 'long',
                    'float', 'decimal' => 'double',
                    'boolean' => 'boolean',
                    'date', 'date_immutable', 'datetime', 'datetime_immutable', 'datetimetz', 'datetimetz_immutable' => 'date',
                    default => 'keyword',
                }];

            if (array_key_exists($field, $facetColumns)) {
                $parameters['facetFields'][$field] = $isText ? $field . '.keyword' : $field;
            }
            if (array_key_exists($field, $sortColumns)) {
                $parameters['sortFields'][$field] = $isText ? $field . '.keyword' : $field;
            }
        }

        $parameters['index'] ??= strtolower($metadata->getTableName());
        $parameters['idField'] ??= $metadata->getSingleIdentifierFieldName();
        $parameters['sourceFields'] ??= $includedFields;

        foreach (self::DBAL_ONLY_KEYS as $key) {
            unset($parameters[$key]);
        }

        $search->setAdapterParameters($parameters);
    }

    /**
     * Strip the query-builder alias FieldSearchConfigurator prefixes on ("o.title"): an
     * Elasticsearch field name has no table alias.
     *
     * @param array<int|string, mixed> $fields
     * @return list<string>
     */
    private function plainFields(array $fields): array
    {
        $plain = [];
        foreach ($fields as $field) {
            if (is_string($field)) {
                $plain[] = preg_replace('/^[a-z]+\./', '', $field) ?? $field;
            }
        }

        return array_values(array_unique($plain));
    }
}
