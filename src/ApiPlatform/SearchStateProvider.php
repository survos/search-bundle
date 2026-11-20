<?php

declare(strict_types=1);

namespace Survos\SearchBundle\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Survos\SearchBundle\Http\InstantSearchGateway;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Optional read-only HTTP bridge; reuses the gateway's allowlist and filter validation. */
final readonly class SearchStateProvider implements ProviderInterface
{
    public function __construct(private InstantSearchGateway $gateway, private RequestStack $requests)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SearchResponse
    {
        $options = $operation->getStateOptions();
        if (!$options instanceof SearchOptions) {
            throw new \LogicException('Configure SearchOptions on the API operation.');
        }
        $request = $this->requests->getCurrentRequest() ?? throw new \LogicException('SearchStateProvider requires an HTTP request.');
        $input = $request->query->all();
        if (array_diff(array_keys($input), ['q', 'page', 'limit', 'sort', 'filter', 'min', 'max'])) {
            throw new BadRequestHttpException('Unknown search parameter.');
        }
        $page = $this->positiveInt($input['page'] ?? 1, 'page', 10000);
        $limit = $this->positiveInt($input['limit'] ?? 24, 'limit', 100);
        $params = ['query' => $input['q'] ?? '', 'page' => $page - 1, 'hitsPerPage' => $limit];
        $sort = $input['sort'] ?? '';
        if (!is_string($sort)) {
            throw new BadRequestHttpException('Invalid sort.');
        }
        foreach (['filter', 'min', 'max'] as $key) {
            $values = $input[$key] ?? [];
            if (!is_array($values) || count($values) > 50) {
                throw new BadRequestHttpException('Invalid '.$key.' filters.');
            }
            foreach ($values as $field => $value) {
                if (!is_string($field) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $field)) {
                    throw new BadRequestHttpException('Invalid filter field.');
                }
                if ('filter' === $key) {
                    $group = [];
                    foreach (is_array($value) ? $value : [$value] as $term) {
                        if (!is_scalar($term) || is_bool($term)) {
                            throw new BadRequestHttpException('Invalid facet value.');
                        }
                        $group[] = $field.':'.$term;
                    }
                    $params['facetFilters'][] = $group;
                } else {
                    if (!is_scalar($value) || !preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $value)) {
                        throw new BadRequestHttpException('Invalid range bound.');
                    }
                    $params['numericFilters'][] = $field.('min' === $key ? '>=' : '<=').$value;
                }
            }
        }
        try {
            $result = $this->gateway->search([[
                'indexName' => $options->search.('' === $sort ? '' : '::'.$sort), 'params' => $params,
            ]])['results'][0];
        } catch (\InvalidArgumentException $error) {
            throw new BadRequestHttpException($error->getMessage(), $error);
        }

        return new SearchResponse($options->search, $result['query'], $result['hits'], $result['nbHits'],
            $page, $limit, $result['nbPages'], array_map(static fn ($values): array => (array) $values, (array) $result['facets']), (array) $result['facets_stats'], $result['processingTimeMS']);
    }

    private function positiveInt(mixed $value, string $name, int $maximum): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9]\d*$/D', (string) $value)
            || (float) $value > $maximum) {
            throw new BadRequestHttpException('Invalid '.$name.'.');
        }

        return (int) $value;
    }
}
