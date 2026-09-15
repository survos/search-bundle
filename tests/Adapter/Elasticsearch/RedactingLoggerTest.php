<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Adapter\Elasticsearch;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Survos\SearchBundle\Adapter\Elasticsearch\RedactingLogger;

final class RedactingLoggerTest extends TestCase
{
    public function testTransportHeaderLineLosesTheApiKey(): void
    {
        $sink = new class extends AbstractLogger {
            public array $lines = [];
            public function log($level, string|\Stringable $message, array $context = []): void { $this->lines[] = (string) $message; }
        };

        (new RedactingLogger($sink))->debug("Headers: {\"Host\":[\"elasticsearch:9200\"],\"Authorization\":[\"ApiKey c2VjcmV0+/==\"]}\nBody: {}");

        self::assertStringNotContainsString('c2VjcmV0', $sink->lines[0]);
        self::assertStringContainsString('"Authorization":["[redacted]"]', $sink->lines[0]);
        self::assertStringContainsString('"Host":["elasticsearch:9200"]', $sink->lines[0]);
    }
}
