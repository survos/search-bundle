<?php

/*
 * This file is part of the UxSearch project.
 *
 * (c) Mezcalito (https://www.mezcalito.fr)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Doctrine\Persistence\ManagerRegistry;
use Survos\SearchBundle\Adapter\AdapterProvider;
use Survos\SearchBundle\Adapter\Algolia\AlgoliaFactory;
use Survos\SearchBundle\Adapter\Doctrine\DoctrineFactory;
use Survos\SearchBundle\Adapter\Meilisearch\MeilisearchFactory;
use Survos\SearchBundle\Adapter\Meilisearch\QueryBuilder;
use Survos\SearchBundle\Context\ContextProvider;
use Survos\SearchBundle\EventSubscriber\ContextSubscriber;
use Survos\SearchBundle\Maker\MakeSearch;
use Survos\SearchBundle\Search\Searcher;
use Survos\SearchBundle\Search\SearchProvider;
use Survos\SearchBundle\Search\Url\DefaultUrlFormater;
use Survos\SearchBundle\Search\Url\UrlFormaterProvider;
use Survos\SearchBundle\Twig\Components\ClearRefinements;
use Survos\SearchBundle\Twig\Components\CurrentRefinements;
use Survos\SearchBundle\Twig\Components\Facet;
use Survos\SearchBundle\Twig\Components\Hits;
use Survos\SearchBundle\Twig\Components\HitsPerPage;
use Survos\SearchBundle\Twig\Components\Layout;
use Survos\SearchBundle\Twig\Components\Pagination;
use Survos\SearchBundle\Twig\Components\SearchInput;
use Survos\SearchBundle\Twig\Components\SortBy;
use Survos\SearchBundle\Twig\Components\TotalHits;
use Survos\SearchBundle\Twig\UxSearchExtension;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\LiveComponent\LiveResponder;

return static function (ContainerConfigurator $container) {
    $services = $container->services();
    $services
        ->set(DoctrineFactory::class)
            ->arg('$managerRegistry', service(ManagerRegistry::class)->nullOnInvalid())
            ->tag('survos_search.adapter_factory')
        ->set(MeilisearchFactory::class)
            ->tag('survos_search.adapter_factory')
        ->set(AlgoliaFactory::class)->tag('survos_search.adapter_factory')
        ->set(Searcher::class)
            ->arg('$adapterProvider', service(AdapterProvider::class))
            ->arg('$contextProvider', service(ContextProvider::class))
            ->arg('$eventDispatcher', service('event_dispatcher'))
        ->set(QueryBuilder::class)
        ->set(ContextProvider::class)
        ->set(AdapterProvider::class)
            ->arg('$defaultAdapterName', param('survos_search.default_adapter'))
            ->arg('$adapterConfiguration', param('survos_search.adapters'))
        ->set(SearchProvider::class)
        ->set(UrlFormaterProvider::class)
        ->set(Layout::class)
            ->arg('$searchConfigurationProvider', service(SearchProvider::class))
            ->arg('$searcher', service(Searcher::class))
            ->arg('$contextProvider', service(ContextProvider::class))
            ->arg('$requestStack', service(RequestStack::class))
            ->arg('$urlFormaterProvider', service(UrlFormaterProvider::class))
            ->arg('$serializer', service('serializer'))
            ->call('setLiveResponder', [service(LiveResponder::class)])
            ->tag('twig.component', [
                'key' => 'Survos:Search:Layout',
                'expose_public_props' => true,
                'attributes_var' => 'attributes',
                'live' => true,
                'csrf' => true,
                'route' => 'ux_live_component',
                'method' => 'post',
                'url_reference_type' => true,
            ])
            ->tag('controller.service_arguments')
            ->public()
        ->set(Hits::class)
            ->arg('$contextProvider', service(ContextProvider::class))
            ->tag('twig.component', ['key' => 'Survos:Search:Hits'])
        ->set(TotalHits::class)
            ->arg('$contextProvider', service(ContextProvider::class))
            ->tag('twig.component', ['key' => 'Survos:Search:TotalHits'])
        ->set(SortBy::class)
            ->arg('$contextProvider', service(ContextProvider::class))
            ->tag('twig.component', ['key' => 'Survos:Search:SortBy'])
        ->set(HitsPerPage::class)
            ->arg('$contextProvider', service(ContextProvider::class))
            ->tag('twig.component', ['key' => 'Survos:Search:HitsPerPage'])
        ->set(Pagination::class)
            ->arg('$contextProvider', service(ContextProvider::class))
            ->tag('twig.component', [
                'key' => 'Survos:Search:Pagination',
                'expose_public_props' => true,
            ])
        ->set(Facet::class)
            ->arg('$contextProvider', service(ContextProvider::class))
            ->tag('twig.component', [
                'key' => 'Survos:Search:Facet',
                'expose_public_props' => true,
            ])
        ->set(Facet\RefinementList::class)
            ->arg('$contextProvider', service(ContextProvider::class))
            ->tag('twig.component', ['key' => 'Survos:Search:Facet:RefinementList'])
        ->set(Facet\RangeInput::class)
            ->arg('$contextProvider', service(ContextProvider::class))
            ->tag('twig.component', ['key' => 'Survos:Search:Facet:RangeInput'])
        ->set(Facet\RangeSlider::class)
            ->arg('$contextProvider', service(ContextProvider::class))
            ->tag('twig.component', ['key' => 'Survos:Search:Facet:RangeSlider'])
        ->set(CurrentRefinements::class)
            ->arg('$contextProvider', service(ContextProvider::class))
            ->tag('twig.component', ['key' => 'Survos:Search:CurrentRefinements'])
        ->set(ClearRefinements::class)
            ->arg('$contextProvider', service(ContextProvider::class))
            ->tag('twig.component', ['key' => 'Survos:Search:ClearRefinements'])
        ->set(SearchInput::class)
            ->tag('twig.component', ['key' => 'Survos:Search:SearchInput'])
        ->set(ContextSubscriber::class)
            ->arg('$contextProvider', service(ContextProvider::class))
        ->set(UxSearchExtension::class)->tag('twig.extension')
        ->set(DefaultUrlFormater::class)
            ->arg('$urlGenerator', service(UrlGeneratorInterface::class))
            ->tag('survos_search.url_formater')
    ;

    // The maker command is optional: only register it when MakerBundle is
    // installed, otherwise autoloading MakeSearch (extends AbstractMaker) fatals
    // during container compilation. See Mezcalito/ux-search#47.
    if (class_exists(\Symfony\Bundle\MakerBundle\Maker\AbstractMaker::class)) {
        $services->set('maker.maker.make_search', MakeSearch::class)
            ->tag('maker.command');
    }
};
