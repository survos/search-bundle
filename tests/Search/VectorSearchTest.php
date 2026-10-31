<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\SearchBundle\Search\EmbeddingProfile;
use Survos\SearchBundle\Search\VectorSearch;

final class VectorSearchTest extends TestCase
{
    public function testIdentityIncludesEveryCompatibilityBoundary(): void
    {
        $profile = new EmbeddingProfile('model', 2, 'revision', 'text-v1');
        $fingerprint = $profile->fingerprint('same content');
        self::assertSame($fingerprint, (new EmbeddingProfile('model', 2, 'revision', 'text-v1'))->fingerprint('same content'));
        foreach ([new EmbeddingProfile('other', 2, 'revision', 'text-v1'), new EmbeddingProfile('model', 3, 'revision', 'text-v1'), new EmbeddingProfile('model', 2, 'new-revision', 'text-v1'), new EmbeddingProfile('model', 2, 'revision', 'text-v2')] as $other) {
            self::assertNotSame($fingerprint, $other->fingerprint('same content'));
        }
        self::assertNotSame($fingerprint, $profile->fingerprint('changed content'));
    }

    #[DataProvider('invalidVectors')]
    public function testInvalidVectorsAreRejected(array $vector): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VectorSearch::validate($vector, 2);
    }

    public static function invalidVectors(): iterable
    {
        yield [[0.0, 0.0]];
        yield [[1.0]];
        yield [[NAN, 1.0]];
        yield [[INF, 1.0]];
        yield [['1.0', '0.0']];
        yield [['x' => 1.0, 'y' => 0.0]];
    }
}
