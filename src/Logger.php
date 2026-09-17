<?php

declare(strict_types=1);

namespace AnvilFeed;

use Psr\Log\LoggerInterface;

/**
 * Logging that cannot become the second failure. FR-2 b: no error inside this SDK ever reaches the
 * caller, and that has to hold for the logger too — a PSR-3 implementation from a host application is
 * somebody else's code and may do anything at all, including throw.
 */
final class Logger
{
    /** @var array<string, true> */
    private array $said = [];

    public function __construct(private readonly ?LoggerInterface $delegate = null)
    {
    }

    public function warning(string $message): void
    {
        $this->write('warning', $message);
    }

    public function error(string $message): void
    {
        $this->write('error', $message);
    }

    /** Says it the first time and never again — for the init-time messages that would otherwise be a flood. */
    public function once(string $key, string $message): void
    {
        if (isset($this->said[$key])) {
            return;
        }
        $this->said[$key] = true;
        $this->write('warning', $message);
    }

    private function write(string $level, string $message): void
    {
        try {
            if ($this->delegate !== null) {
                $this->delegate->log($level, $message);

                return;
            }
            error_log($message);
        } catch (\Throwable) {
            // Deliberately empty. There is nowhere left to report to.
        }
    }
}
