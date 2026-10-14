<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Menu;

use Survos\MenuBundle\Attribute\AsMenuListener;
use Survos\MenuBundle\Event\MenuEvent;
use Survos\MenuBundle\MenuSlot;
use Survos\SearchBundle\Registry\UxSearchRegistry;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;
use Survos\TablerBundle\Service\IconService;
use Survos\TablerBundle\Service\RouteAliasService;
use Symfony\Component\Routing\RouterInterface;

class SearchMenuSubscriber extends AbstractAdminMenuSubscriber
{
    public function __construct(
        private readonly UxSearchRegistry $searchRegistry,
        ?RouterInterface $router = null,
        ?RouteAliasService $routeAliasService = null,
        ?IconService $iconService = null,
    ) {
        parent::__construct($router, $routeAliasService, $iconService);
    }

    protected function getLabel(): string
    {
        return 'Search';
    }

    protected function getBrowseRoute(): ?string
    {
        return null;
    }

    protected function getResourceClasses(): array
    {
        return [];
    }

    #[AsMenuListener(MenuSlot::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $searches = $this->searchRegistry->all();
        if ([] === $searches) {
            return;
        }

        $menu = $event->getMenu();
        $submenu = $this->addSubmenu($menu, $this->getLabel());

        foreach ($searches as $descriptor) {
            // label auto-derived from (string) $descriptor via __toString()
            $this->add($submenu, 'survos_entity_ux_search', $descriptor);
        }
    }
}
