<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Search;

use Mezcalito\UxSearchBundle\Search\AbstractSearch;
use Survos\SearchBundle\Service\FieldSearchConfigurator;
use Symfony\Contracts\Service\Attribute\Required;

abstract class AbstractFieldSearch extends AbstractSearch
{
    private ?FieldSearchConfigurator $fieldSearchConfigurator = null;

    #[Required]
    public function setFieldSearchConfigurator(FieldSearchConfigurator $fieldSearchConfigurator): void
    {
        $this->fieldSearchConfigurator = $fieldSearchConfigurator;
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
        if (!$this->fieldSearchConfigurator instanceof FieldSearchConfigurator) {
            throw new \LogicException(sprintf('The "%s" service was not injected. Is this search class registered as a Symfony service?', FieldSearchConfigurator::class));
        }

        $this->fieldSearchConfigurator->configure($this, $this->getFieldClass($options));
    }
}
