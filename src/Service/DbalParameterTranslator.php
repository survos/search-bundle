<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Survos\SearchBundle\Adapter\AdapterInterface;
use Survos\SearchBundle\Adapter\PostgresBm25\PostgresBm25Adapter;
use Survos\SearchBundle\Adapter\SqliteFts5\SqliteFts5Adapter;
use Survos\SearchBundle\Search\SearchInterface;

/**
 * Shapes parameters for the database-native adapters: SQLite FTS5 and Postgres BM25.
 *
 * Both address real columns, so field names are mapped to their Doctrine column names and
 * prefixed with the "d" alias the DBAL adapters use. FTS5 additionally needs the name of its
 * shadow table; BM25 needs the tsvector match and rank expressions.
 *
 * Lifted verbatim out of AutoEntitySearch::configureDbalAdapter() so hand-written searches get
 * the same treatment as automatic ones.
 */
final readonly class DbalParameterTranslator implements ParameterTranslatorInterface
{
    public function __construct(private ?ManagerRegistry $managerRegistry = null) {}

    public function supports(AdapterInterface $adapter): bool
    {
        return $adapter instanceof SqliteFts5Adapter || $adapter instanceof PostgresBm25Adapter;
    }

    public function columnPrefix(): ?string
    {
        return null;
    }

    /** @param class-string $entityClass */
    public function translate(SearchInterface $search, string $entityClass, AdapterInterface $adapter): void
    {
        $manager = $this->managerRegistry?->getManagerForClass($entityClass);
        if ($manager === null) {
            return;
        }

        $metadata = $manager->getClassMetadata($entityClass);
        $table = $metadata->getTableName();
        $columnForField = [];
        foreach ($metadata->getFieldNames() as $field) {
            $columnForField[$field] = $metadata->getColumnName($field);
        }

        $parameters = $search->getAdapterParameters();
        $parameters['facetColumns'] ??= [];
        $parameters['sortColumns'] ??= [];

        foreach ($search->getFacets() as $facet) {
            $property = $facet->getProperty();
            if (!isset($columnForField[$property]) && !isset($parameters['facetColumns'][$property])) {
                throw new \LogicException(sprintf(
                    'Cannot configure DBAL search facet "%s" for %s: no Doctrine field mapping exists.',
                    $property,
                    $entityClass,
                ));
            }

            // Multi-valued facets are allowed through by the field layer because engines that
            // bucket each array element handle them natively. A DBAL adapter does not: it would
            // GROUP BY the whole serialized array and render ["8.2","8.3"] as a single bucket.
            // Fail loudly rather than produce facets that look plausible and are wrong.
            $fieldType = isset($columnForField[$property]) ? $metadata->getTypeOfField($property) : null;
            if (in_array($fieldType, ['json', 'jsonb', 'array', 'simple_array'], true)) {
                throw new \LogicException(sprintf(
                    'Cannot facet "%s" on %s with a DBAL adapter: it is a "%s" column, and GROUP BY would '
                    . 'bucket the whole serialized array rather than each element. Use the Elasticsearch '
                    . 'adapter for multi-valued facets, or remove it from FILTERABLE_FIELDS.',
                    $property,
                    $entityClass,
                    $fieldType,
                ));
            }

            $parameters['facetColumns'][$property] ??= $property;
        }

        $parameters['searchFields'] = $this->mapFieldList($parameters['searchFields'] ?? [], $columnForField);
        $parameters['facetColumns'] = $this->mapFieldColumns($parameters['facetColumns'], $columnForField);
        $parameters['sortColumns'] = $this->mapFieldColumns($parameters['sortColumns'], $columnForField);

        $parameters += [
            'table' => $table,
            'idColumn' => $metadata->getColumnName($metadata->getSingleIdentifierFieldName()),
            'selectColumns' => array_values($columnForField),
        ];

        if ($adapter instanceof SqliteFts5Adapter) {
            $parameters += ['ftsTable' => $table . '_fts'];
        }
        if ($adapter instanceof PostgresBm25Adapter) {
            $vector = $this->postgresVectorExpression($parameters['searchFields'] ?? [], $manager->getConnection());
            $parameters += [
                'matchExpression' => sprintf("(%s) @@ websearch_to_tsquery('english', :bm25Query)", $vector),
                'scoreExpression' => sprintf("ts_rank((%s), websearch_to_tsquery('english', :bm25Query))", $vector),
            ];
        }

        $search->setAdapterParameters($parameters);
    }

    /**
     * @param array<int|string, mixed> $fields
     * @param array<string, string> $columnForField
     * @return list<string>
     */
    private function mapFieldList(array $fields, array $columnForField): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            if (is_string($field)) {
                $mapped[] = $this->dbalColumnExpression($field, $columnForField);
            }
        }

        return array_values(array_unique($mapped));
    }

    /**
     * @param array<string, mixed> $columns
     * @param array<string, string> $columnForField
     * @return array<string, string>
     */
    private function mapFieldColumns(array $columns, array $columnForField): array
    {
        $mapped = [];
        foreach ($columns as $property => $column) {
            if (!is_string($property)) {
                continue;
            }
            $mapped[$property] = is_string($column)
                ? $this->dbalColumnExpression($column, $columnForField)
                : 'd.' . ($columnForField[$property] ?? $property);
        }

        return $mapped;
    }

    /** @param array<string, string> $columnForField */
    private function dbalColumnExpression(string $expression, array $columnForField): string
    {
        $field = str_starts_with($expression, 'd.') ? substr($expression, 2) : $expression;
        $field = str_starts_with($field, 'o.') ? substr($field, 2) : $field;

        return isset($columnForField[$field]) ? 'd.' . $columnForField[$field] : $expression;
    }

    /** @param string[] $fields */
    private function postgresVectorExpression(array $fields, Connection $connection): string
    {
        $expressions = [];
        foreach ($fields as $field) {
            if (!is_string($field) || $field === '') {
                continue;
            }
            $column = preg_replace('/^[a-z]+\./', '', $field) ?? $field;
            $expressions[] = sprintf(
                "to_tsvector('english', coalesce(%s::text, ''))",
                'd.' . $connection->quoteIdentifier($column),
            );
        }

        return $expressions === [] ? "to_tsvector('english', '')" : implode(' || ', $expressions);
    }
}
