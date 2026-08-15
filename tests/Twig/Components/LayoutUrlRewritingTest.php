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

namespace Survos\SearchBundle\Tests\Twig\Components;

use Survos\SearchBundle\Search\AbstractSearch;
use Survos\SearchBundle\Context\ContextProvider;
use Survos\SearchBundle\Search\Filter\RangeFilter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Survos\SearchBundle\Search\Searcher as BaseSearcher;
use Survos\SearchBundle\Search\SearchInterface;
use Survos\SearchBundle\Search\SearchProvider;
use Survos\SearchBundle\Search\Url\CurrentRequest;
use Survos\SearchBundle\Search\Url\UrlFormaterInterface;
use Survos\SearchBundle\Search\Url\UrlFormaterProvider;
use Survos\SearchBundle\Twig\Components\Layout;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class LayoutUrlRewritingTest extends TestCase
{
    public function testDoesNotDispatchWhenUrlRewritingDisabled(): void
    {
        $layout = $this->createLayout(enabled: false);

        $layout->onInitialMount(['name' => 'listing', 'options' => []]);

        $layout->name = 'listing';
        $layout->options = [];
        $layout->onReRender();

        self::assertCount(1, $layout->dispatchedEvents);
        self::assertSame('ux-search:query:update', $layout->dispatchedEvents[0][0]);

        // Verify that ux-search:url:update is NOT dispatched
        foreach ($layout->dispatchedEvents as $event) {
            self::assertNotSame('ux-search:url:update', $event[0]);
        }
    }


    public function testInitialQuerySeedsPublicQueryState(): void
    {
        $layout = $this->createLayout(enabled: false);

        $layout->onInitialMount([
            'name' => 'listing',
            'options' => [],
            'initialQuery' => [
                'query' => 'painting',
                'sort' => 'title:desc',
                'hitsPerPage' => 24,
                'filters' => [
                    'category' => 'object',
                    'price' => ['min' => 10, 'max' => 20],
                ],
            ],
        ]);

        self::assertSame('painting', $layout->query->getQueryString());
        self::assertSame('title:desc', $layout->query->getActiveSort());
        self::assertSame(24, $layout->query->getActiveHitsPerPage());

        $category = $layout->query->getActiveFilter('category');
        self::assertInstanceOf(TermFilter::class, $category);
        self::assertSame(['object'], $category->getValues());

        $price = $layout->query->getActiveFilter('price');
        self::assertInstanceOf(RangeFilter::class, $price);
        self::assertSame(10, $price->getMin());
        self::assertSame(20, $price->getMax());
    }

    public function testFixedFiltersConstrainSearchWithoutExposingRefinements(): void
    {
        $layout = $this->createLayout(enabled: false);

        $layout->onInitialMount([
            'name' => 'listing',
            'options' => [],
            'fixedFilters' => ['core' => 'obj'],
        ]);

        self::assertFalse($layout->query->hasActiveFilter('core'));

        $searchedFilter = DummySearcherState::$lastQuery?->getActiveFilter('core');
        self::assertInstanceOf(TermFilter::class, $searchedFilter);
        self::assertSame(['obj'], $searchedFilter->getValues());

        self::assertFalse($layout->contextProvider->getCurrentContext()->getQuery()->hasActiveFilter('core'));
    }

    public function testDispatchesNamespacedEventWhenEnabled(): void
    {
        $layout = $this->createLayout(enabled: true);

        $layout->onInitialMount(['name' => 'listing', 'options' => []]);

        $layout->name = 'listing';
        $layout->options = [];
        $layout->onReRender();

        self::assertCount(2, $layout->dispatchedEvents);

        // First event should be ux-search:query:update
        self::assertSame('ux-search:query:update', $layout->dispatchedEvents[0][0]);

        // Second event should be ux-search:url:update
        self::assertSame('ux-search:url:update', $layout->dispatchedEvents[1][0]);
        self::assertSame(['url' => 'https://example.test/route?ok=1'], $layout->dispatchedEvents[1][1]);
    }

    private function createLayout(bool $enabled): TestableLayout
    {
        $search = new class extends AbstractSearch {
            public function build(array $options = []): void
            {
            }
        };
        if ($enabled) {
            $search->enableUrlRewriting()->setUrlFormater(TestUrlFormater::class);
        }

        $provider = new SearchProvider(['listing' => $search]);

        $searcher = new DummySearcher();
        $contextProvider = new ContextProvider();

        $request = new Request([], [], ['_route' => 'route']);
        $stack = new RequestStack();
        $stack->push($request);

        $urlFormaterProvider = new UrlFormaterProvider([
            TestUrlFormater::class => new TestUrlFormater(),
        ]);

        $normalizers = [
            new ObjectNormalizer(
                null,
                null,
                null,
                new ReflectionExtractor()
            ),
        ];

        $encoders = [new JsonEncoder()];

        $serializer = new Serializer($normalizers, $encoders);
        DummySearcherState::$lastQuery = null;
        DummySearcherState::$contextProvider = $contextProvider;

        return new TestableLayout($provider, $searcher, $contextProvider, $stack, $urlFormaterProvider, $serializer);
    }
}

final class TestableLayout extends Layout
{
    public array $dispatchedEvents = [];

    public function __construct(
        SearchProvider $searchConfigurationProvider,
        DummySearcher $searcher,
        public readonly ContextProvider $contextProvider,
        RequestStack $requestStack,
        UrlFormaterProvider $urlFormaterProvider,
        Serializer $serializer,
    ) {
        parent::__construct($searchConfigurationProvider, $searcher, $contextProvider, $requestStack, $urlFormaterProvider, $serializer);
    }

    public function dispatchBrowserEvent(string $event, array $data = []): void
    {
        $this->dispatchedEvents[] = [$event, $data];
    }
}

final class DummySearcherState
{
    public static ?Query $lastQuery = null;

    public static ?ContextProvider $contextProvider = null;
}

readonly class DummySearcher extends BaseSearcher
{
    public function __construct()
    {
    }

    public function search(Query $query, SearchInterface $search): ResultSet
    {
        DummySearcherState::$lastQuery = $query;
        DummySearcherState::$contextProvider?->init($query, $search);

        return new ResultSet();
    }
}

final class TestUrlFormater implements UrlFormaterInterface
{
    public function generateUrl(CurrentRequest $currentRequest, SearchInterface $search, Query $query): string
    {
        return 'https://example.test/'.$currentRequest->route.'?ok=1';
    }

    public function applyFilters(CurrentRequest $currentRequest, SearchInterface $search, Query $query): void
    {
        // no-op for this test
    }
}
