<?php

declare(strict_types=1);

namespace Survos\SearchBundle;

use Doctrine\Persistence\ManagerRegistry;
use Mezcalito\UxSearchBundle\Adapter\AdapterProvider;
use Survos\FieldBundle\Service\FieldReader;
use Survos\SearchBundle\Adapter\PostgresBm25\PostgresBm25Factory;
use Survos\SearchBundle\Adapter\SqliteFts5\SqliteFts5Factory;
use Survos\SearchBundle\Command\SearchCreateCommand;
use Survos\SearchBundle\DependencyInjection\UxSearchAdapterPass;
use Survos\SearchBundle\Service\FieldSearchConfigurator;
use Survos\SearchBundle\Twig\SearchExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SurvosSearchBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->integerNode('default_hits_per_page')->defaultValue(24)->end()
            ->arrayNode('default_hits_per_page_choices')
                ->integerPrototype()->end()
                ->defaultValue([12, 24, 48])
            ->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services()->defaults()->autowire()->autoconfigure();

        $services
            ->set(FieldSearchConfigurator::class)
                ->arg('$defaultHitsPerPage', $config['default_hits_per_page'])
                ->arg('$defaultHitsPerPageChoices', $config['default_hits_per_page_choices'])
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
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        if (class_exists(AdapterProvider::class)) {
            $container->addCompilerPass(new UxSearchAdapterPass());
        }
    }
}
