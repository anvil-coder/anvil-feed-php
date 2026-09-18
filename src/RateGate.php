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
    /**
     * Where a fingerprint goes when the table is full and nothing in it has expired. One shared window
     * for all of them: they are still CAPPED, which is the promise, even though they are no longer
     * counted apart. The alternative — letting a newcomer through because there is no room to remember
     * it — is an uncapped storm on the one code path that exists to cap storms.
     *
     * @var array{start: float, count: int, suppressed: int}|null
     */
    private ?array $overflow = null;
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
        // Monotonic by default: this class only ever measures an ELAPSED interval, and microtime(true)
        // moves with NTP. An injected clock (Client::create) wins, so a host test can drive a window
        // without sleeping.
        $this->clock = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn (): float => hrtime(true) / 1_000_000_000;
    }

    public function allow(string $fingerprint): bool
    {
        $now = ($this->clock)();
        $window = $this->windows[$fingerprint] ?? null;

        if ($window !== null && !$this->hasExpired($window, $now)) {
            if ($window['count'] < $this->cap) {
                $this->windows[$fingerprint]['count']++;

                return true;
            }
            $this->windows[$fingerprint]['suppressed']++;

            return false;
        }

        // A new window for this fingerprint. Re-assigning an existing key does NOT move it to the end of
        // a PHP array, so the old entry is removed first: that is what keeps insertion order equal to age
        // order, which is the invariant makeRoom() reads.
        unset($this->windows[$fingerprint]);
        if (!$this->makeRoom($now)) {
            return $this->allowOverflow($now);
        }
        $this->windows[$fingerprint] = ['start' => $now, 'count' => 1, 'suppressed' => 0];

        return true;
    }

    /**
     * How many events the current window has dropped for that fingerprint — and, for one the table had
     * no room for, how many the overflow bucket it was counted against has dropped. Zero for a
     * fingerprint that has not been seen. The caller uses `1` as the edge that says the line about the
     * storm once per window.
     */
    public function suppressed(string $fingerprint): int
    {
        if (isset($this->windows[$fingerprint])) {
            return $this->windows[$fingerprint]['suppressed'];
        }

        return $this->overflow['suppressed'] ?? 0;
    }

    /**
     * Room for one more, by dropping the oldest EXPIRED entry — never a live one.
     *
     * The first version of this dropped the oldest entry outright, and that is the entry a storming
     * fingerprint occupies: it was tracked from its first occurrence, its window is refreshed in place,
     * so under churn the gate evicted precisely the fingerprint it was capping and the storm walked
     * straight back through with a fresh count of 1. An expired entry is capping nothing, so dropping one
     * costs nothing.
     */
    private function makeRoom(float $now): bool
    {
        if (count($this->windows) < self::MAX_TRACKED) {
            return true;
        }
        foreach ($this->windows as $key => $window) {   // insertion order is age order (see allow())
            if ($this->hasExpired($window, $now)) {
                unset($this->windows[$key]);

                return true;
            }
        }

        return false;   // every tracked fingerprint is live; the newcomer goes to the shared bucket
    }

    /** The shared window of the fingerprints there was no room to track apart. */
    private function allowOverflow(float $now): bool
    {
        if ($this->overflow === null || $this->hasExpired($this->overflow, $now)) {
            $this->overflow = ['start' => $now, 'count' => 1, 'suppressed' => 0];

            return true;
        }
        if ($this->overflow['count'] < $this->cap) {
            $this->overflow['count']++;

            return true;
        }
        $this->overflow['suppressed']++;

        return false;
    }

    /** @param array{start: float, count: int, suppressed: int} $window */
    private function hasExpired(array $window, float $now): bool
    {
        $elapsedMs = ($now - $window['start']) * 1000;

        // A clock that stepped BACKWARDS (NTP, `date -s`) would otherwise hold a window open for as long
        // as the step was large, muting a fingerprint past its window on a long-lived worker.
        return $elapsedMs >= $this->windowMs || $elapsedMs < 0;
    }
}
