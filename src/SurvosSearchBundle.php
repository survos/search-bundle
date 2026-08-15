<?php

declare(strict_types=1);

namespace Survos\SearchBundle;

use Doctrine\Persistence\ManagerRegistry;
use Survos\FieldBundle\Service\FieldReader;
use Survos\FieldBundle\SurvosFieldBundle;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\Kit\Traits\HasConfigurableRoutes;
use Survos\SearchBundle\Adapter\AdapterFactoryInterface;
use Survos\SearchBundle\Adapter\AdapterProvider;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchFactory;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchQueryBuilder;
use Survos\SearchBundle\Adapter\PostgresBm25\PostgresBm25Factory;
use Survos\SearchBundle\Adapter\SqliteFts5\SqliteFts5Factory;
use Survos\SearchBundle\Attribute\AsSearch;
use Survos\SearchBundle\Command\SearchCreateCommand;
use Survos\SearchBundle\Command\SearchIndexCommand;
use Survos\SearchBundle\Compiler\AutoEntitySearchPass;
use Survos\SearchBundle\Controller\AutoSearchController;
use Survos\SearchBundle\DependencyInjection\AdapterFactoryPass;
use Survos\SearchBundle\DependencyInjection\RegisterSearchPass;
use Survos\SearchBundle\DependencyInjection\UrlFormaterPass;
use Survos\SearchBundle\DependencyInjection\UxSearchAdapterPass;
use Survos\SearchBundle\EventSubscriber\HitEntityHydrator;
use Survos\SearchBundle\Menu\SearchMenuSubscriber;
use Survos\SearchBundle\Registry\UxSearchRegistry;
use Survos\SearchBundle\Search\Url\UrlFormaterInterface;
use Survos\SearchBundle\Service\FieldSearchConfigurator;
use Survos\SearchBundle\Twig\SearchExtension;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\UX\Icons\UXIconsBundle;

#[RequiredBundle(SurvosKitBundle::class)]
#[RequiredBundle(SurvosFieldBundle::class)]
#[RequiredBundle(UXIconsBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosSearchBundle extends AbstractSurvosBundle
{
    use HasConfigurableRoutes;

    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $definition->rootNode();
        $children = $rootNode->children();
        $this->addRouteOptions($children, '');
        $children
            ->scalarNode('default_adapter')->defaultValue('default')->end()
            ->arrayNode('adapters')
                ->normalizeKeys(false)
                ->useAttributeAsKey('name')
                ->arrayPrototype()
                    ->beforeNormalization()
                        ->ifString()
                        ->then(static fn (string $value): array => ['dsn' => $value])
                    ->end()
                    ->children()
                        ->scalarNode('dsn')->isRequired()->end()
                    ->end()
                ->end()
                ->defaultValue(['default' => ['dsn' => 'doctrine://default']])
            ->end()
            ->integerNode('default_hits_per_page')->defaultValue(24)->end()
            ->arrayNode('default_hits_per_page_choices')
                ->integerPrototype()->end()
                ->defaultValue([12, 24, 48])
            ->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);
        $container->import('../config/ux_search_services.php');
        $this->captureRouteConfig($config);
        $this->registerRouteLoader($builder);

        $builder->setParameter('survos_search.default_adapter', $config['default_adapter']);
        $builder->setParameter('survos_search.adapters', $config['adapters']);

        $builder->registerAttributeForAutoconfiguration(AsSearch::class, static function (ChildDefinition $definition, AsSearch $attribute, \Reflector $reflector): void {
            $tagAttributes = get_object_vars($attribute);
            if (null === $tagAttributes['name'] && $reflector instanceof \ReflectionClass) {
                $tagAttributes['name'] = strtolower(str_replace('Search', '', $reflector->getShortName()));
            }
            $definition->addTag('survos_search.search', $tagAttributes);
            $definition->addTag('kernel.reset', ['method' => 'reset']);
        });
        $builder->registerForAutoconfiguration(AdapterFactoryInterface::class)->addTag('survos_search.adapter_factory');
        $builder->registerForAutoconfiguration(UrlFormaterInterface::class)->addTag('survos_search.url_formater');

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services
            ->set(FieldSearchConfigurator::class)
                ->arg('$defaultHitsPerPage', $config['default_hits_per_page'])
                ->arg('$defaultHitsPerPageChoices', $config['default_hits_per_page_choices'])
                ->public()
            ->set(UxSearchRegistry::class)->arg('$descriptors', [])->public()
            ->set(AutoSearchController::class)->public()
            ->set(SqliteFts5Factory::class)
                ->arg('$managerRegistry', new Reference(ManagerRegistry::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->tag('survos_search.adapter_factory')
            ->set(PostgresBm25Factory::class)
                ->arg('$managerRegistry', new Reference(ManagerRegistry::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->tag('survos_search.adapter_factory')
            ->set(ElasticsearchQueryBuilder::class)
            ->set(ElasticsearchFactory::class)->tag('survos_search.adapter_factory')
            ->set(HitEntityHydrator::class)
            ->set(SearchExtension::class)
                ->arg('$fieldReader', new Reference(FieldReader::class))
                ->tag('twig.extension')
            ->set(SearchCreateCommand::class)->arg('$projectDir', '%kernel.project_dir%')->public()
            ->set(SearchIndexCommand::class)->public();

        if (class_exists(\Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber::class)) {
            $services->set(SearchMenuSubscriber::class)->autowire()->autoconfigure();
        }
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new AutoEntitySearchPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
        $container->addCompilerPass(new UxSearchAdapterPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -100);
        $container->addCompilerPass(new RegisterSearchPass());
        $container->addCompilerPass(new AdapterFactoryPass());
        $container->addCompilerPass(new UrlFormaterPass());
        $this->addRouteLoaderCompilerPass($container);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('twig')) {
            $builder->prependExtensionConfig('twig', [
                'paths' => [dirname(__DIR__).'/templates' => 'SurvosSearch'],
            ]);
        }

        $builder->prependExtensionConfig('twig_component', [
            'defaults' => [
                'Survos\\SearchBundle\\Twig\\Components\\' => [
                    'template_directory' => '@SurvosSearch/',
                    'name_prefix' => 'Survos:Search',
                ],
            ],
        ]);

        if ($this->isAssetMapperAvailable($builder)) {
            $builder->prependExtensionConfig('framework', [
                'asset_mapper' => [
                    'paths' => [dirname(__DIR__).'/assets/dist' => '@survos/search-bundle'],
                ],
            ]);
        }
    }

    private function isAssetMapperAvailable(ContainerBuilder $builder): bool
    {
        if (!interface_exists(AssetMapperInterface::class)) {
            return false;
        }

        $bundlesMetadata = $builder->getParameter('kernel.bundles_metadata');

        return isset($bundlesMetadata['FrameworkBundle'])
            && is_file($bundlesMetadata['FrameworkBundle']['path'].'/Resources/config/asset_mapper.php');
    }
}
