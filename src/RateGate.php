<?php

declare(strict_types=1);

namespace AnvilFeed;

/**
 * At most `cap` events per fingerprint per window. A storm is deduplicated inside the process, before it
 * reaches the queue, the socket or the ingest's 600-requests-per-minute budget.
 *
 * The table is bounded too. An unbounded map keyed by fingerprint is a memory leak that only shows up in
 * the one situation this class exists for — an application producing thousands of DISTINCT errors — and
 * FR-2 d forbids unbounded growth without qualification.
 */
final class RateGate
{
    private const MAX_TRACKED = 500;

    /** @var array<string, array{start: float, count: int, suppressed: int}> */
    private array $windows = [];
    /** @var \Closure(): float */
    private \Closure $clock;

    private readonly int $cap;
    private readonly int $windowMs;

    public function __construct(int $cap, int $windowMs, ?callable $clock = null)
    {
        // A cap of zero would drop everything and a negative window would make every event a new window,
        // i.e. no cap at all. Both are a configuration mistake, and neither may turn error reporting off
        // or leave it uncapped without anybody noticing.
        $this->cap = max(1, $cap);
        $this->windowMs = max(1, $windowMs);
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): float => microtime(true);
    }

    public function allow(string $fingerprint): bool
    {
        $now = ($this->clock)();
        $window = $this->windows[$fingerprint] ?? null;

        if ($window === null || ($now - $window['start']) * 1000 >= $this->windowMs) {
            $this->evictIfFull();
            $this->windows[$fingerprint] = ['start' => $now, 'count' => 1, 'suppressed' => 0];

            return true;
        }
        if ($window['count'] < $this->cap) {
            $this->windows[$fingerprint]['count']++;

            return true;
        }
        $this->windows[$fingerprint]['suppressed']++;

        return false;
    }

    /** How many events this window has dropped for that fingerprint. Zero for one it is not tracking. */
    public function suppressed(string $fingerprint): int
    {
        return $this->windows[$fingerprint]['suppressed'] ?? 0;
    }

    /** Insertion order is age order here, so the oldest tracked fingerprint goes first. */
    private function evictIfFull(): void
    {
        while (count($this->windows) >= self::MAX_TRACKED) {
            array_shift($this->windows);
        }
    }
}
