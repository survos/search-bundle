<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Search;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Survos\SearchBundle\Adapter\Doctrine\DoctrineAdapter;
use Survos\SearchBundle\Twig\Components\Facet\RefinementList;
use Symfony\Component\String\UnicodeString;

final class AutoEntitySearch extends AbstractFieldSearch implements HitTemplateSearchInterface
{
    /**
     * @param class-string $entityClass
     * @param string[]     $fieldNames
     */
    public function __construct(
        private readonly string $entityClass,
        private readonly array $fieldNames,
        private readonly ?ManagerRegistry $managerRegistry = null,
        private readonly ?string $defaultAdapterDsn = null,
    ) {}

    public function getIndexName(): ?string
    {
        return $this->entityClass;
    }

    protected function getFieldClass(array $options = []): string
    {
        return $this->entityClass;
    }

    private ?string $hitTemplate = null;

    public function getHitTemplate(): ?string
    {
        return $this->hitTemplate;
    }

    public function build(array $options = []): void
    {
        $this->hitTemplate = $options['hitTemplate'] ?? null;

        $isDbalAdapter = $this->isDbalAdapter();
        $isElasticsearchAdapter = $this->isElasticsearchAdapter();
        $this->getFieldSearchConfigurator()->configure(
            $this,
            $this->entityClass,
            $this->fieldNames,
            ($isDbalAdapter || $isElasticsearchAdapter) ? null : 'o.',
        );

        $this->applyConstantFields($isDbalAdapter || $isElasticsearchAdapter);

        if ($isDbalAdapter) {
            $this->configureDbalAdapter();
            return;
        }
        if ($isElasticsearchAdapter) {
            $this->configureElasticsearchAdapter();
            return;
        }

        $searchFields = $this->getAdapterParameters()[DoctrineAdapter::SEARCH_FIELDS] ?? [];
        $this->setAdapterParameters([
            DoctrineAdapter::SEARCH_FIELDS => $searchFields,
            DoctrineAdapter::QUERY_BUILDER_ALIAS => 'o',
            DoctrineAdapter::QUERY_BUILDER => static function (QueryBuilder $qb): void {},
            DoctrineAdapter::MAX_FACET_VALUES_PARAM => $this->getAdapterParameters()[DoctrineAdapter::MAX_FACET_VALUES_PARAM] ?? 20,
            DoctrineAdapter::COUNT_DISTINCT => false,
            DoctrineAdapter::FETCH_JOIN_COLLECTION => false,
        ]);
    }

    private function isDbalAdapter(): bool
    {
        return is_string($this->defaultAdapterDsn)
            && (str_starts_with($this->defaultAdapterDsn, 'sqlite-fts5://') || str_starts_with($this->defaultAdapterDsn, 'postgres-bm25://'));
    }

    private function isElasticsearchAdapter(): bool
    {
        return is_string($this->defaultAdapterDsn)
            && (str_starts_with($this->defaultAdapterDsn, 'elasticsearch://')
                || str_starts_with($this->defaultAdapterDsn, 'elasticsearch+https://')
                || str_starts_with($this->defaultAdapterDsn, 'elastic://'));
    }

    private function configureElasticsearchAdapter(): void
    {
        if (!$this->managerRegistry) {
            return;
        }
        $manager = $this->managerRegistry->getManagerForClass($this->entityClass);
        if (!$manager) {
            return;
        }

        $metadata = $manager->getClassMetadata($this->entityClass);
        $parameters = $this->getAdapterParameters();
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

            // A json column is either an array of scalars -- often the most useful facet
            // there is (phpVersions, keywords) -- or a nested object blob. Doctrine reports
            // both as 'json', and mapping a blob as `keyword` makes Elasticsearch reject the
            // whole bulk request ("Expected text but found START_OBJECT"). We can't tell them
            // apart from metadata, so json is opt-in: included only when it was explicitly
            // named as a facet or a search field.
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
        unset($parameters['facetColumns'], $parameters['sortColumns']);
        $this->setAdapterParameters($parameters);
    }

    /** @param array<int|string, mixed> $fields
     *  @return string[]
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

    private function configureDbalAdapter(): void
    {
        if (!$this->managerRegistry) {
            return;
        }

        $manager = $this->managerRegistry->getManagerForClass($this->entityClass);
        if (!$manager) {
            return;
        }

        $metadata = $manager->getClassMetadata($this->entityClass);
        $table = $metadata->getTableName();
        $columnForField = [];
        foreach ($metadata->getFieldNames() as $field) {
            $columnForField[$field] = $metadata->getColumnName($field);
        }

        $adapterParameters = $this->getAdapterParameters();
        $adapterParameters['facetColumns'] ??= [];
        $adapterParameters['sortColumns'] ??= [];
        foreach ($this->getFacets() as $facet) {
            $property = $facet->getProperty();
            if (!isset($columnForField[$property]) && !isset($adapterParameters['facetColumns'][$property])) {
                throw new \LogicException(sprintf(
                    'Cannot configure DBAL search facet "%s" for %s: no Doctrine field mapping exists.',
                    $property,
                    $this->entityClass,
                ));
            }
            // Multi-valued facets are allowed through unfacetableFields() because engines that
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
                    $this->entityClass,
                    $fieldType,
                ));
            }
            $adapterParameters['facetColumns'][$property] ??= $property;
        }
        $adapterParameters['searchFields'] = $this->mapFieldList($adapterParameters['searchFields'] ?? [], $columnForField);
        $adapterParameters['facetColumns'] = $this->mapFieldColumns($adapterParameters['facetColumns'], $columnForField);
        $adapterParameters['sortColumns'] = $this->mapFieldColumns($adapterParameters['sortColumns'], $columnForField);

        $adapterParameters += [
            'table' => $table,
            'idColumn' => $metadata->getColumnName($metadata->getSingleIdentifierFieldName()),
            'selectColumns' => array_values($columnForField),
        ];

        if (str_starts_with((string) $this->defaultAdapterDsn, 'sqlite-fts5://')) {
            $adapterParameters += [
                'ftsTable' => $table . '_fts',
            ];
        }

        if (str_starts_with((string) $this->defaultAdapterDsn, 'postgres-bm25://')) {
            $vectorExpression = $this->postgresVectorExpression($adapterParameters['searchFields'] ?? [], $manager->getConnection(), true);
            $adapterParameters += [
                'matchExpression' => sprintf("(%s) @@ websearch_to_tsquery('english', :bm25Query)", $vectorExpression),
                'scoreExpression' => sprintf("ts_rank((%s), websearch_to_tsquery('english', :bm25Query))", $vectorExpression),
            ];
        }

        $this->setAdapterParameters($adapterParameters);
    }

    /**
     * @param array<int|string, mixed> $fields
     * @param array<string, string> $columnForField
     * @return string[]
     */
    private function mapFieldList(array $fields, array $columnForField): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            if (!is_string($field)) {
                continue;
            }
            $mapped[] = $this->dbalColumnExpression($field, $columnForField);
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

        if (isset($columnForField[$field])) {
            return 'd.' . $columnForField[$field];
        }

        return $expression;
    }

    /** @param string[] $fields */
    private function postgresVectorExpression(array $fields, \Doctrine\DBAL\Connection $connection, bool $withAlias): string
    {
        $expressions = [];
        foreach ($fields as $field) {
            if (!is_string($field) || $field === '') {
                continue;
            }
            $column = preg_replace('/^[a-z]+\./', '', $field) ?? $field;
            $qualified = ($withAlias ? 'd.' : '') . $connection->quoteIdentifier($column);
            $expressions[] = sprintf("to_tsvector('english', coalesce(%s::text, ''))", $qualified);
        }

        return $expressions === [] ? "to_tsvector('english', '')" : implode(' || ', $expressions);
    }

    private function applyConstantFields(bool $isDbalAdapter): void
    {
        $rc = new \ReflectionClass($this->entityClass);

        $searchable = $rc->hasConstant('SEARCHABLE_FIELDS') ? (array) $rc->getConstant('SEARCHABLE_FIELDS') : [];
        $filterable = $rc->hasConstant('FILTERABLE_FIELDS') ? (array) $rc->getConstant('FILTERABLE_FIELDS') : [];
        $sortable = $rc->hasConstant('SORTABLE_FIELDS') ? (array) $rc->getConstant('SORTABLE_FIELDS') : [];

        $skipFacets = $this->unfacetableFields($rc);
        $existingFacets = [];
        foreach ($this->getFacets() as $facet) {
            $existingFacets[$facet->getProperty()] = true;
        }

        foreach ($filterable as $field) {
            if (isset($skipFacets[$field]) || isset($existingFacets[$field])) {
                continue;
            }
            $label = ucwords(str_replace('_', ' ', (new UnicodeString($field))->snake()->toString()));
            $this->addFacet($field, $label, RefinementList::class);
            $existingFacets[$field] = true;
            if ($isDbalAdapter) {
                $adapterParameters = $this->getAdapterParameters();
                $adapterParameters['facetColumns'][$field] ??= 'd.' . $field;
                $this->setAdapterParameters($adapterParameters);
            }
        }

        $existingSorts = [];
        foreach ($this->getAvailableSorts() as $sort) {
            // getAvailableSorts() returns Sort objects, not arrays — key by getKey().
            $existingSorts[$sort->getKey() ?? ''] = true;
        }

        foreach ($sortable as $field) {
            $label = ucwords(str_replace('_', ' ', (new UnicodeString($field))->snake()->toString()));
            $sortKey = $isDbalAdapter ? $field : "o.{$field}";
            if (!isset($existingSorts["{$sortKey}:asc"])) {
                $this->addAvailableSort("{$sortKey}:asc", "{$label} A-Z");
            }
            if (!isset($existingSorts["{$sortKey}:desc"])) {
                $this->addAvailableSort("{$sortKey}:desc", "{$label} Z-A");
            }
            if ($isDbalAdapter) {
                $adapterParameters = $this->getAdapterParameters();
                $adapterParameters['sortColumns'][$field] ??= 'd.' . $field;
                $this->setAdapterParameters($adapterParameters);
            }
        }

        if ($searchable !== []) {
            $adapterParameters = $this->getAdapterParameters();
            $prefix = $isDbalAdapter ? '' : 'o.';
            $existing = $adapterParameters[DoctrineAdapter::SEARCH_FIELDS] ?? $adapterParameters['searchFields'] ?? [];
            $adapterParameters['searchFields'] = array_unique(array_merge($existing, array_map(static fn (string $f) => $prefix . $f, $searchable)));
            if (!$isDbalAdapter) {
                $adapterParameters[DoctrineAdapter::SEARCH_FIELDS] = $adapterParameters['searchFields'];
                unset($adapterParameters['searchFields']);
            }
            $this->setAdapterParameters($adapterParameters);
        }
    }

    /**
     * Fields that cannot back a facet at all, whatever the adapter.
     *
     * This used to also exclude json/jsonb/array/simple_array, which meant a column named
     * in FILTERABLE_FIELDS -- an explicit request -- was silently dropped. Multi-valued
     * columns are frequently the most useful facets (phpVersions, symfonyVersions,
     * keywords), and an engine that buckets each element handles them natively, so an
     * explicit declaration now wins. See configureDbalAdapter(), which rejects them loudly
     * for adapters that would GROUP BY the whole serialized array instead.
     *
     * boolean stays excluded: PostgreSQL rejects min(boolean) in DoctrineAdapter's stats
     * query (same reason FieldSearchConfigurator::shouldExposeFacet() skips Widget::Boolean).
     *
     * @return array<string, true>
     */
    private function unfacetableFields(\ReflectionClass $rc): array
    {
        $unfacetableTypes = ['boolean', 'bool', 'object'];

        $skip = [];
        foreach ($rc->getProperties() as $prop) {
            foreach ($prop->getAttributes(Column::class) as $attr) {
                $col = $attr->newInstance();
                $type = $col->type ?? null;
                if ($type !== null && in_array($type, $unfacetableTypes, true)) {
                    $skip[$prop->getName()] = true;
                    continue 2;
                }
            }
            $nativeType = $prop->getType();
            if ($nativeType instanceof \ReflectionNamedType && $nativeType->getName() === 'bool') {
                $skip[$prop->getName()] = true;
            }
        }

        return $skip;
    }
}
