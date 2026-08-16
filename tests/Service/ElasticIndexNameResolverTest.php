<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\SearchBundle\Search\SearchInterface;
use Survos\SearchBundle\Service\ElasticIndexNameResolver;

final class ElasticIndexNameResolverTest extends TestCase
{
    public function testTheConfiguredIndexParameterWins(): void
    {
        $resolver = new ElasticIndexNameResolver('kpa_');

        self::assertSame('song', $resolver->base(self::search(index: 'song')));
        self::assertSame('kpa_song', $resolver->uid(self::search(index: 'song')));
    }

    /**
     * The fallback that used to differ between the query and write paths: the adapter sanitised an
     * FQCN, elastic-bundle used the search code raw. Populate one index, query another.
     */
    public function testAnEntityFqcnFallbackIsSanitisedIdentically(): void
    {
        $resolver = new ElasticIndexNameResolver('kpa_');

        self::assertSame('app-entity-wcma', $resolver->base(self::search(indexName: 'App\\Entity\\Wcma')));
        self::assertSame('kpa_app-entity-wcma', $resolver->uid(self::search(indexName: 'App\\Entity\\Wcma')));
    }

    public function testUppercaseAndPunctuationAreStripped(): void
    {
        $resolver = new ElasticIndexNameResolver('');

        // Elasticsearch rejects uppercase index names outright.
        self::assertSame('my-index', $resolver->base(self::search(index: 'My Index!')));
        self::assertSame('search', $resolver->base(self::search(index: '///')), 'an unusable name still has to produce something');
    }

    /** MEILI_PREFIX is written both ways across apps; neither may produce "kpasong". */
    public function testAPrefixGainsASeparatorButKeepsOneItAlreadyHas(): void
    {
        self::assertSame('kpa_song', (new ElasticIndexNameResolver('kpa_'))->uidForRaw('song'));
        self::assertSame('kpa-song', (new ElasticIndexNameResolver('kpa-'))->uidForRaw('song'));
        self::assertSame('kpa-song', (new ElasticIndexNameResolver('kpa'))->uidForRaw('song'));
    }

    /** Resolving an already-resolved name must not double-prefix, as MeiliRegistry::uidFor() guarantees. */
    public function testPrefixingIsIdempotent(): void
    {
        $resolver = new ElasticIndexNameResolver('kpa_');

        self::assertSame('kpa_song', $resolver->uidForRaw($resolver->uidForRaw('song')));
    }

    public function testEmptyPrefixIsADeliberateOptOut(): void
    {
        $resolver = new ElasticIndexNameResolver('');

        self::assertSame('song', $resolver->uidForRaw('song'));
        self::assertFalse($resolver->hasPrefix());
        self::assertSame('*', $resolver->pattern());
    }

    /**
     * The footgun: an unconfigured prefix means bare index names in a cluster namespace shared
     * with every other app, which is how two apps silently write one `article` index.
     */
    public function testAnUnsetPrefixIsAnError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No Elasticsearch index prefix is configured');

        (new ElasticIndexNameResolver())->uidForRaw('song');
    }

    public function testThePatternTracksThePrefix(): void
    {
        self::assertSame('kpa_*', (new ElasticIndexNameResolver('kpa_'))->pattern());
        self::assertSame('kpa-*', (new ElasticIndexNameResolver('kpa'))->pattern());
    }

    /** Locale exists for folio's translated builds; the Elasticsearch default never passes one. */
    public function testLocaleSuffixesTheRawNameNotThePrefix(): void
    {
        $resolver = new ElasticIndexNameResolver('kpa_');

        self::assertSame('song', $resolver->rawFor('song'));
        self::assertSame('song_fr', $resolver->rawFor('song', 'fr'));
        self::assertSame('kpa_song_fr', $resolver->uid(self::search(index: 'song'), 'fr'));
    }

    private static function search(?string $index = null, string $indexName = 'fallback'): SearchInterface
    {
        $search = self::createStub(SearchInterface::class);
        $search->method('getResolvedAdapterParameter')
            ->willReturnCallback(static fn (string $name): mixed => 'index' === $name ? $index : null);
        $search->method('getIndexName')->willReturn($indexName);

        return $search;
    }
}
