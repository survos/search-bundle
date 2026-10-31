<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\PostgresBm25;

use Doctrine\DBAL\Connection;
use Survos\SearchBundle\Adapter\AdapterInterface;
use Survos\SearchBundle\Adapter\DbalAdapterTrait;
use Survos\SearchBundle\Search\Filter\RangeFilter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\ResultSet\FacetStat;
use Survos\SearchBundle\Search\ResultSet\FacetTermDistribution;
use Survos\SearchBundle\Search\ResultSet\Hit;
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Survos\SearchBundle\Search\SearchInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class PostgresBm25Adapter implements AdapterInterface, \Survos\SearchBundle\Adapter\SemanticSearchAdapterInterface
{
    use DbalAdapterTrait;

    public function __construct(
        private Connection $connection,
        private ?CacheInterface $facetCache = null,
    ) {
    }

    public function configureParameters(OptionsResolver $resolver): void
    {
        \Survos\SearchBundle\Search\VectorSearch::configure($resolver);
        $resolver->setDefaults([
            'idColumn' => 'id',
            'selectColumns' => [],
            'searchFields' => [],
            'facetColumns' => [],
            'sortColumns' => [],
            'where' => null,
            'params' => [],
            'maxFacetValues' => 100,
            // Drop hits whose scoreExpression is below this (0 = keep all). Useful
            // to filter low-weight matches, e.g. a term found only in OCR text.
            'scoreThreshold' => 0.0,
            // Keep these explicit because pg_textsearch and ParadeDB expose
            // different BM25 operators. Use :bm25Query in both expressions.
        ]);

        $resolver->setRequired(['table', 'matchExpression', 'scoreExpression']);
        $resolver->setAllowedTypes('table', 'string');
        $resolver->setAllowedTypes('idColumn', 'string');
        $resolver->setAllowedTypes('selectColumns', 'string[]');
        $resolver->setAllowedTypes('searchFields', 'string[]');
        $resolver->setAllowedTypes('facetColumns', 'array');
        $resolver->setAllowedTypes('sortColumns', 'array');
        $resolver->setAllowedTypes('where', ['null', 'string']);
        $resolver->setAllowedTypes('params', 'array');
        $resolver->setAllowedTypes('maxFacetValues', 'int');
        $resolver->setAllowedTypes('matchExpression', 'string');
        $resolver->setAllowedTypes('scoreExpression', 'string');
        $resolver->setAllowedTypes('scoreThreshold', ['int', 'float']);
    }

    public function supportsSemanticSearch(SearchInterface $search): bool
    {
        return \Survos\SearchBundle\Search\VectorSearch::available($search->getResolvedAdapterParameters());
    }

    public function search(Query $query, SearchInterface $search): ResultSet
    {
        foreach ($query->getActiveFilters() as $filter) {
            if ('semantic' === $query->getSearchMode() && !array_key_exists($filter->getProperty(), $search->getResolvedAdapterParameter('facetColumns'))) {
                throw new \InvalidArgumentException('Unknown PostgreSQL facet/filter: '.$filter->getProperty());
            }
        }
        $baseParams = $this->queryParameters($query, $search);
        $params = $baseParams;
        $where = $this->baseWhere($query, $search, $params);
        $this->applyFilters($query, $search, $where, $params);

        $limit = max(1, $query->getActiveHitsPerPage());
        $offset = $limit * max(0, $query->getCurrentPage() - 1);
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $scoreExpression = $this->scoreExpression($query, $search);

        $sql = sprintf(
            'SELECT %s, %s AS _score FROM %s d%s %s LIMIT :limit OFFSET :offset',
            $this->selectList($this->connection, $search->getResolvedAdapterParameter('selectColumns')),
            $scoreExpression,
            $this->connection->quoteSingleIdentifier($search->getResolvedAdapterParameter('table')),
            [] === $where ? '' : ' WHERE '.implode(' AND ', $where),
            $this->orderBy($query, $search),
        );

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();
        $hits = array_map(
            static fn (array $row): Hit => new Hit($row, isset($row['_score']) ? (float) $row['_score'] : 0.0),
            $rows,
        );

        $countSql = sprintf(
            'SELECT COUNT(*) FROM %s d%s',
            $this->connection->quoteSingleIdentifier($search->getResolvedAdapterParameter('table')),
            [] === $where ? '' : ' WHERE '.implode(' AND ', $where),
        );

        return (new ResultSet())
            ->setIndexUid($search->getIndexName())
            ->setHits($hits)
            ->setTotalResults((int) $this->connection->executeQuery($countSql, $params)->fetchOne())
            ->setFacetDistributions($this->cachedFacetCompute('semantic' === $query->getSearchMode() ? null : $this->facetCache, 'distributions', $query, $search, fn () => $this->facetDistributions($query, $search, $baseParams)))
            ->setFacetStats($this->cachedFacetCompute('semantic' === $query->getSearchMode() ? null : $this->facetCache, 'stats', $query, $search, fn () => $this->facetStats($query, $search, $baseParams)));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return string[]
     */
    private function baseWhere(Query $query, SearchInterface $search, array &$params): array
    {
        $where = [];
        if (is_string($search->getResolvedAdapterParameter('where'))) {
            $where[] = $search->getResolvedAdapterParameter('where');
        }

        if ('semantic' === $query->getSearchMode()) {
            if ('' !== trim($query->getQueryString())) {
                $where[] = $this->scoreExpression($query, $search).' >= :vectorMinimumSimilarity';
            }

            return $where;
        }

        if ('' !== $query->getQueryString()) {
            $where[] = $search->getResolvedAdapterParameter('matchExpression');
            $params['bm25Query'] = $query->getQueryString();

            $threshold = $search->getResolvedAdapterParameter('scoreThreshold');
            if (is_numeric($threshold) && $threshold > 0) {
                $where[] = sprintf('(%s) >= :scoreThreshold', $search->getResolvedAdapterParameter('scoreExpression'));
                $params['scoreThreshold'] = (float) $threshold;
            }
        }

        return $where;
    }

    private function queryParameters(Query $query, SearchInterface $search): array
    {
        $params = $search->getResolvedAdapterParameter('params');
        if ('semantic' !== $query->getSearchMode()) {
            return $params;
        }
        if (!$this->supportsSemanticSearch($search)) {
            throw new \LogicException('Smart Search is not available for this dataset.');
        }
        if ('' !== trim($query->getQueryString())) {
            $p = $search->getResolvedAdapterParameters();
            $vector = $p['queryEmbedder']->embedQuery(trim($query->getQueryString()));
            \Survos\SearchBundle\Search\VectorSearch::validate($vector, $p['vectorDimensions']);
            $params['vectorQuery'] = json_encode($vector, JSON_THROW_ON_ERROR);
            $params['vectorMinimumSimilarity'] = $query->getMinimumSimilarity() ?? $p['minimumSimilarity'];
        }

        return $params;
    }

    private function scoreExpression(Query $query, SearchInterface $search): string
    {
        if ('' === trim($query->getQueryString())) {
            return '0';
        }
        if ('semantic' === $query->getSearchMode()) {
            return '(1 - ('.$search->getResolvedAdapterParameter('vectorColumn').' <=> CAST(:vectorQuery AS vector)))';
        }

        return $search->getResolvedAdapterParameter('scoreExpression');
    }

    private function orderBy(Query $query, SearchInterface $search): string
    {
        $id = 'd.'.$this->connection->quoteSingleIdentifier($search->getResolvedAdapterParameter('idColumn'));
        $activeSort = $query->getActiveSort();
        // Existing lexical BM25 searches keep their relevance-first behavior.
        if ('semantic' !== $query->getSearchMode() && '' !== $query->getQueryString()) {
            return 'ORDER BY _score DESC, '.$id;
        }
        if (is_string($activeSort) && '' !== $activeSort) {
            if (!str_contains($activeSort, ':')) {
                throw new \InvalidArgumentException('Unknown PostgreSQL sort: '.$activeSort);
            }
            [$property, $direction] = explode(':', $activeSort, 2);
            $column = $search->getResolvedAdapterParameter('sortColumns')[$property] ?? null;
            $direction = strtoupper($direction);
            if (!is_string($column) || !in_array($direction, ['ASC', 'DESC'], true)) {
                throw new \InvalidArgumentException('Unknown PostgreSQL sort: '.$activeSort);
            }

            return sprintf('ORDER BY %s %s, %s', $column, $direction, $id);
        }

        return 'ORDER BY '.('' !== trim($query->getQueryString()) ? '_score DESC, ' : '').$id;
    }

    /**
     * @return array<string, FacetTermDistribution>
     */
    private function facetDistributions(Query $query, SearchInterface $search, array $baseParams): array
    {
        $distributions = [];
        foreach ($search->getFacets() as $facet) {
            $filter = $query->getActiveFilter($facet->getProperty());
            $checkedValues = $filter instanceof TermFilter ? $filter->getValues() : [];
            $column = $this->columnFor($search, 'facetColumns', $facet->getProperty());

            $params = $baseParams;
            $where = $this->baseWhere($query, $search, $params);
            $this->applyFilters($query, $search, $where, $params, $facet->getProperty());
            $params['maxFacetValues'] = $search->getResolvedAdapterParameter('maxFacetValues');

            $sql = sprintf(
                'SELECT %s AS value, COUNT(*) AS total FROM %s d%s GROUP BY %s ORDER BY total DESC, value LIMIT :maxFacetValues',
                $column,
                $this->connection->quoteSingleIdentifier($search->getResolvedAdapterParameter('table')),
                [] === $where ? '' : ' WHERE '.implode(' AND ', $where),
                $column,
            );

            $values = [];
            foreach ($this->connection->executeQuery($sql, $params)->fetchAllAssociative() as $row) {
                if (null !== $row['value'] && '' !== $row['value']) {
                    $values[$row['value']] = (int) $row['total'];
                }
            }

            $distributions[$facet->getProperty()] = (new FacetTermDistribution())
                ->setProperty($facet->getProperty())
                ->setValues($values)
                ->setCheckedValues($checkedValues);
        }

        return $distributions;
    }

    /**
     * @return array<string, FacetStat>
     */
    private function facetStats(Query $query, SearchInterface $search, array $baseParams): array
    {
        $stats = [];
        foreach ($search->getFacets() as $facet) {
            $component = $facet->getDisplayComponent();
            if (null === $component
                || !is_subclass_of($component, \Survos\SearchBundle\Twig\Components\Facet\AbstractFacet::class)
                || !$component::usesFacetStats()) {
                continue;
            }

            $filter = $query->getActiveFilter($facet->getProperty());
            $column = $this->columnFor($search, 'facetColumns', $facet->getProperty());
            $params = $baseParams;
            $where = $this->baseWhere($query, $search, $params);
            $this->applyFilters($query, $search, $where, $params, $facet->getProperty());

            $sql = sprintf(
                'SELECT MIN(%s) AS min_value, MAX(%s) AS max_value FROM %s d%s',
                $column,
                $column,
                $this->connection->quoteSingleIdentifier($search->getResolvedAdapterParameter('table')),
                [] === $where ? '' : ' WHERE '.implode(' AND ', $where),
            );

            $row = $this->connection->executeQuery($sql, $params)->fetchAssociative();
            if (!$row) {
                continue;
            }

            $min = $row['min_value'];
            $max = $row['max_value'];
            if (null === $min || null === $max) {
                if ('semantic' === $query->getSearchMode()) {
                    continue;
                }
                $min = 0;
                $max = 0;
            }
            if (!is_numeric($min) || !is_numeric($max)) {
                continue;
            }

            $stats[$facet->getProperty()] = new FacetStat(
                $facet->getProperty(),
                (float) $min,
                (float) $max,
                $filter instanceof RangeFilter ? $filter->getMin() : null,
                $filter instanceof RangeFilter ? $filter->getMax() : null,
            );
        }

        return $stats;
    }
}
