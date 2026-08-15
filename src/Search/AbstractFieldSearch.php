<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Search;

use Survos\SearchBundle\Adapter\AdapterInterface;
use Survos\SearchBundle\Adapter\AdapterProvider;
use Survos\SearchBundle\Adapter\Elasticsearch\ElasticsearchAdapter;
use Survos\SearchBundle\Service\FieldSearchConfigurator;
use Survos\SearchBundle\Service\ParameterTranslatorInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;

abstract class AbstractFieldSearch extends AbstractSearch
{
    private ?FieldSearchConfigurator $fieldSearchConfigurator = null;
    private ?AdapterProvider $adapterProvider = null;

    /** @var iterable<ParameterTranslatorInterface> */
    private iterable $parameterTranslators = [];

    #[Required]
    public function setFieldSearchConfigurator(FieldSearchConfigurator $fieldSearchConfigurator): void
    {
        $this->fieldSearchConfigurator = $fieldSearchConfigurator;
    }

    #[Required]
    public function setAdapterProvider(AdapterProvider $adapterProvider): void
    {
        $this->adapterProvider = $adapterProvider;
    }

    /** @param iterable<ParameterTranslatorInterface> $parameterTranslators */
    #[Required]
    public function setParameterTranslators(
        #[AutowireIterator('survos_search.parameter_translator')]
        iterable $parameterTranslators,
    ): void
    {
        $this->parameterTranslators = $parameterTranslators;
    }

    /**
     * Return the class carrying #[Field] metadata. This may be a Doctrine entity,
     * a DTO, or a search-facing projection class.
     *
     * @param array<string, mixed> $options
     */
    abstract protected function getFieldClass(array $options = []): string;

    /**
     * @param array<string, mixed> $options
     */
    public function build(array $options = []): void
    {
        $fieldClass = $this->getFieldClass($options);
        $adapter = $this->resolveAdapter();
        $translator = $this->resolveTranslator($adapter);

        // The alias has to be decided before configuring, not after: the Doctrine ORM adapter
        // needs "o.title" for DQL, the others address columns and document fields directly.
        $this->getFieldSearchConfigurator()->configure(
            $this,
            $fieldClass,
            $this->allowedFieldNames(),
            $translator?->columnPrefix(),
        );

        $this->configureFields($translator);

        if ($translator !== null && $adapter !== null && class_exists($fieldClass)) {
            $translator->translate($this, $fieldClass, $adapter);
        }
    }

    /**
     * Restrict which #[Field] descriptors are considered. Empty means all of them.
     *
     * @return string[]
     */
    protected function allowedFieldNames(): array
    {
        return [];
    }

    /**
     * Hook between field configuration and engine translation, for facets and sorts that come
     * from somewhere other than #[Field] metadata. Anything added here is visible to the
     * translator, which is the point -- a facet declared after translation would never be
     * mapped.
     */
    protected function configureFields(?ParameterTranslatorInterface $translator): void
    {
    }

    /**
     * Resolved by adapter TYPE, never by sniffing a DSN string: a search names its adapter
     * (#[AsSearch(adapter: 'es')]) and only the provider knows what that name points at.
     */
    protected function resolveAdapter(): ?AdapterInterface
    {
        if ($this->adapterProvider === null) {
            return null;
        }

        try {
            return $this->adapterProvider->getAdapter($this->getAdapterName());
        } catch (\Throwable) {
            // Unknown or unconfigured adapter: let the normal search path report it rather than
            // failing here, where the message would be less useful.
            return null;
        }
    }

    protected function resolveTranslator(?AdapterInterface $adapter): ?ParameterTranslatorInterface
    {
        if ($adapter === null) {
            return null;
        }

        foreach ($this->parameterTranslators as $translator) {
            if ($translator->supports($adapter)) {
                return $translator;
            }
        }

        return null;
    }

    /**
     * Kept for searches that hand-write engine-specific parameters -- Postgres to_tsvector
     * expressions, say -- and need to skip them when pointed at Elasticsearch, so one class can
     * serve both engines.
     */
    protected function usesElasticsearch(): bool
    {
        return class_exists(ElasticsearchAdapter::class)
            && $this->resolveAdapter() instanceof ElasticsearchAdapter;
    }

    protected function getFieldSearchConfigurator(): FieldSearchConfigurator
    {
        if (!$this->fieldSearchConfigurator instanceof FieldSearchConfigurator) {
            throw new \LogicException(sprintf('The "%s" service was not injected. Is this search class registered as a Symfony service?', FieldSearchConfigurator::class));
        }

        return $this->fieldSearchConfigurator;
    }
}
