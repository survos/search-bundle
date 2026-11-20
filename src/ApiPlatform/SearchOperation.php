<?php

declare(strict_types=1);

namespace Survos\SearchBundle\ApiPlatform;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\QueryParameter;

/** Declare one GET search envelope, with a fixed allowlisted search and documented inputs. */
final class SearchOperation extends HttpOperation
{
    public function __construct(string $uriTemplate, string $search, ?string $name = null, ?string $security = null)
    {
        parent::__construct(
            method: 'GET',
            uriTemplate: $uriTemplate, uriVariables: [], name: $name, security: $security,
            provider: SearchStateProvider::class, stateOptions: new SearchOptions($search),
            output: SearchResponse::class, formats: ['json' => ['application/json']],
            paginationEnabled: false, normalizationContext: ['preserve_empty_objects' => true],
            parameters: [
                'q' => new QueryParameter(description: 'Full-text query (maximum 300 characters).', schema: ['type' => 'string', 'maxLength' => 300]),
                'page' => new QueryParameter(description: 'One-based result page.', schema: ['type' => 'integer', 'minimum' => 1], castToNativeType: true),
                'limit' => new QueryParameter(description: 'Hits per page (1–100; default 24).', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], castToNativeType: true),
                'sort' => new QueryParameter(description: 'A sort key declared by this named search.', schema: ['type' => 'string']),
                'filter' => new QueryParameter(description: 'Facet map: filter[chamber]=house or filter[chamber][]=house. OR within a field, AND across fields.'),
                'min' => new QueryParameter(description: 'Inclusive lower range bounds, e.g. min[year]=2025.'),
                'max' => new QueryParameter(description: 'Inclusive upper range bounds, e.g. max[year]=2026.'),
            ],
        );
    }
}
