<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\Elasticsearch;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * elastic/transport logs every request's headers as JSON at debug, Authorization included,
 * which put a production API key into the app logs. Redact credentials before they leave.
 */
final class RedactingLogger extends AbstractLogger
{
    public function __construct(private readonly LoggerInterface $inner) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->inner->log($level, self::redact((string) $message), $context);
    }

    public static function redact(string $message): string
    {
        return preg_replace('/("(?:authorization|proxy-authorization)"\s*:\s*\[\s*")[^"]*/i', '$1[redacted]', $message) ?? $message;
    }
}
