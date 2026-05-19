<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Controller;

use Survos\FieldBundle\Registry\EntityMetaRegistry;
use Survos\SearchBundle\Registry\UxSearchRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AutoSearchController extends AbstractController
{
    public function __construct(
        private readonly EntityMetaRegistry $entityMetaRegistry,
        private readonly UxSearchRegistry $uxSearchRegistry,
    ) {}

    #[Route('/entity/{code}/search', name: 'survos_entity_ux_search', methods: ['GET'])]
    public function __invoke(string $code): Response
    {
        $entity = $this->entityMetaRegistry->getByCode($code)
            ?? throw $this->createNotFoundException(sprintf('No entity registered for code "%s".', $code));

        $search = $this->uxSearchRegistry->forCode($code)
            ?? throw $this->createNotFoundException(sprintf('No UX Search configuration registered for "%s".', $code));

        return $this->render('@SurvosSearch/auto_search.html.twig', [
            'descriptor' => $entity,
            'search' => $search,
        ]);
    }
}
