<?php

declare(strict_types=1);

namespace AnvilFeed\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * A PSR-3 sink that keeps what was written. Without it "log ONCE per process" and "one warning per drop
 * event naming the count" are claims no test can hold anybody to — the messages would go to error_log and
 * the assertion would be that the code compiles.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = (string) $level . ': ' . (string) $message;
    }

    /** @return list<string> */
    public function matching(string $needle): array
    {
        return array_values(array_filter($this->lines, static fn (string $l) => str_contains($l, $needle)));
    }
}
