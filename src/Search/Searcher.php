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

namespace Survos\SearchBundle\Search;

use Survos\SearchBundle\Adapter\AdapterProvider;
use Survos\SearchBundle\Context\ContextProvider;
use Survos\SearchBundle\Event\PostSearchEvent;
use Survos\SearchBundle\Event\PreSearchEvent;
use Survos\SearchBundle\EventSubscriber\ContextSubscriber;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

readonly class Searcher
{
    public function __construct(
        private AdapterProvider $adapterProvider,
        private ContextProvider $contextProvider,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function supportsSemanticSearch(SearchInterface $search): bool
    {
        $adapter = $this->adapterProvider->getAdapter($search->getAdapterName());
        if ($adapter instanceof \Survos\SearchBundle\Adapter\SemanticSearchAdapterInterface) {
            return $adapter->supportsSemanticSearch($search);
        }

        // Existing Doctrine searches can supply their own semantic predicate hook.
        return (bool) ($search->getResolvedAdapterParameters()['smartSearchAvailable'] ?? false);
    }

    public function search(Query $query, SearchInterface $search): ResultSet\ResultSet
    {
        $searchEventDispatcher = $search->getEventDispatcher();
        $search->addEventSubscriber(new ContextSubscriber($this->contextProvider));

        // Dispatch on the search's own dispatcher (per-search subscribers, e.g.
        // ContextSubscriber) and on the global dispatcher so application/bundle
        // subscribers can hook search events the usual way.
        $preEvent = new PreSearchEvent($query, $search);
        $searchEventDispatcher->dispatch($preEvent);
        $this->eventDispatcher->dispatch($preEvent);

        $adapter = $this->adapterProvider->getAdapter($search->getAdapterName());

        $optionResolver = new OptionsResolver();
        $adapter->configureParameters($optionResolver);
        $search->setResolvedAdapterParameters($optionResolver->resolve($search->getAdapterParameters()));

        if ('semantic' === $query->getSearchMode() && !$this->supportsSemanticSearch($search)) {
            throw new \LogicException('Smart Search is not available for this dataset and adapter.');
        }

        $results = $adapter->search($query, $search);

        $postEvent = new PostSearchEvent($query, $search, $results);
        $searchEventDispatcher->dispatch($postEvent);
        $this->eventDispatcher->dispatch($postEvent);

        return $results;
    }
}
