<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Controller;

use Survos\SearchBundle\Http\InstantSearchGateway;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class InstantSearchController
{
    public function __construct(private InstantSearchGateway $gateway) {}

    #[Route('/instant-search', name: 'survos_search_instant', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            if (strlen($request->getContent()) > 65536) { throw new \InvalidArgumentException('Request is too large.'); }
            $data = $request->toArray();
            if (!isset($data['requests']) || !is_array($data['requests'])) { throw new \InvalidArgumentException('Search requests are required.'); }
            return new JsonResponse($this->gateway->search($data['requests']));
        } catch (\InvalidArgumentException|\Symfony\Component\HttpFoundation\Exception\JsonException $error) {
            return new JsonResponse(['message' => $error->getMessage()], 400);
        }
    }
}
