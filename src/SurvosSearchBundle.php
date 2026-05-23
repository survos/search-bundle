<?php

declare(strict_types=1);

namespace Survos\SearchBundle;

use Doctrine\Persistence\ManagerRegistry;
use Mezcalito\UxSearchBundle\Adapter\AdapterProvider;
use Mezcalito\UxSearchBundle\MezcalitoUxSearchBundle;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\Kit\Traits\HasConfigurableRoutes;
use Survos\FieldBundle\Service\FieldReader;
use Survos\FieldBundle\SurvosFieldBundle;
use Survos\SearchBundle\Adapter\PostgresBm25\PostgresBm25Factory;
use Survos\SearchBundle\Adapter\SqliteFts5\SqliteFts5Factory;
use Survos\SearchBundle\Command\SearchCreateCommand;
use Survos\SearchBundle\Controller\AutoSearchController;
use Survos\SearchBundle\Compiler\AutoEntitySearchPass;
use Survos\SearchBundle\DependencyInjection\UxSearchAdapterPass;
use Survos\SearchBundle\Registry\UxSearchRegistry;
use Survos\SearchBundle\Service\FieldSearchConfigurator;
use Survos\SearchBundle\Menu\SearchMenuSubscriber;
use Survos\SearchBundle\Twig\SearchExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;

#[RequiredBundle(SurvosKitBundle::class)]
#[RequiredBundle(SurvosFieldBundle::class)]
#[RequiredBundle(MezcalitoUxSearchBundle::class)]
final class SurvosSearchBundle extends AbstractSurvosBundle
{
    use HasConfigurableRoutes;

    public function configure(DefinitionConfigurator $definition): void
    {
        $children = $definition->rootNode()->children();
        $this->addRouteOptions($children, '');
        $children
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
        $this->captureRouteConfig($config);
        $this->registerRouteLoader($builder);

        $services = $container->services()->defaults()->autowire()->autoconfigure();

        $services
            ->set(FieldSearchConfigurator::class)
                ->arg('$defaultHitsPerPage', $config['default_hits_per_page'])
                ->arg('$defaultHitsPerPageChoices', $config['default_hits_per_page_choices'])
                ->public()
            ->set(UxSearchRegistry::class)
                ->arg('$descriptors', [])
                ->public()
            ->set(AutoSearchController::class)
                ->public()
            ->set(SqliteFts5Factory::class)
                ->arg('$managerRegistry', new Reference(ManagerRegistry::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->tag('mezcalito_ux_search.adapter_factory')
            ->set(PostgresBm25Factory::class)
                ->arg('$managerRegistry', new Reference(ManagerRegistry::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->tag('mezcalito_ux_search.adapter_factory')
            ->set(SearchExtension::class)
                ->arg('$fieldReader', new Reference(FieldReader::class))
                ->tag('twig.extension')
            ->set(SearchCreateCommand::class)
                ->arg('$projectDir', '%kernel.project_dir%')
                ->public();

        if (class_exists(\Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber::class)) {
            $services->set(SearchMenuSubscriber::class)->autowire()->autoconfigure();
        }
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        if (class_exists(AdapterProvider::class)) {
            // EntityMetaPass runs at priority 0; AutoEntitySearchPass must run after it
            $container->addCompilerPass(new AutoEntitySearchPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
            $container->addCompilerPass(new UxSearchAdapterPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -100);
        }
        $this->addRouteLoaderCompilerPass($container);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('twig')) {
            $builder->prependExtensionConfig('twig', [
                'paths' => [dirname(__DIR__) . '/templates' => 'SurvosSearch'],
            ]);
        }
    }
}
