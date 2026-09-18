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

    /** @var array<string, array{start: float, count: int, suppressed: int, stormSaid: bool}> */
    private array $windows = [];
    /**
     * Where a fingerprint goes when the table is full and nothing in it has expired. One shared window
     * for all of them: they are still CAPPED, which is the promise, even though they are no longer
     * counted apart. The alternative — letting a newcomer through because there is no room to remember
     * it — is an uncapped storm on the one code path that exists to cap storms.
     *
     * @var array{start: float, count: int, suppressed: int, stormSaid: bool}|null
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
        $this->windows[$fingerprint] = ['start' => $now, 'count' => 1, 'suppressed' => 0, 'stormSaid' => false];

        return true;
    }

    /**
     * How many events the current window has dropped FOR THAT FINGERPRINT. Zero for one that is not
     * tracked apart — never seen, expired and evicted, or counted against the shared bucket; ask
     * `suppressedOverflow()` for that one.
     *
     * It used to fall back to the overflow bucket, and then the sentence "zero for a fingerprint that has
     * not been seen" was untrue the moment the bucket was non-empty: a caller could be told a storm about
     * a fingerprint that had never been suppressed at all, and the client's storm line named it. The two
     * counters are two different facts and are now two different questions (Java's task-8 review
     * finding 4; the Java twin's `suppressed`/`suppressedOverflow` pair, rule for rule).
     */
    public function suppressed(string $fingerprint): int
    {
        return $this->windows[$fingerprint]['suppressed'] ?? 0;
    }

    /** How many events the SHARED bucket has dropped in its current window — the ones nothing tracked. */
    public function suppressedOverflow(): int
    {
        return $this->overflow['suppressed'] ?? 0;
    }

    /**
     * True exactly ONCE per window for the thing that just suppressed this fingerprint — its own window,
     * or the shared bucket when the table had no room to track it apart. Call it only immediately after
     * `allow()` has answered false; by construction, whatever just refused is what this consumes the edge
     * on.
     *
     * It lives here, in the gate, because the caller's version of it — read `suppressed(...)` and compare
     * with 1 — was a SECOND question about a number the gate had already moved past. The edge and the
     * state it is read off cannot come apart if only one of them exists. (The Java twin's
     * `firstSuppression`, where the two reads were two synchronized calls with a gap between them and two
     * threads storming one fingerprint could step over the edge together and both stay silent — losing
     * the line in exactly the case a storm arrives in; task-8 review finding 3. PHP has no such race, and
     * C9/C17 want the two cores to read alike anyway.)
     */
    public function firstSuppression(string $fingerprint): bool
    {
        $tracked = isset($this->windows[$fingerprint]);
        $target = $tracked ? $this->windows[$fingerprint] : $this->overflow;
        if ($target === null || $target['suppressed'] === 0 || $target['stormSaid']) {
            return false;
        }
        if ($tracked) {
            $this->windows[$fingerprint]['stormSaid'] = true;
        } else {
            $this->overflow['stormSaid'] = true;
        }

        return true;
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
            $this->overflow = ['start' => $now, 'count' => 1, 'suppressed' => 0, 'stormSaid' => false];

            return true;
        }
        if ($this->overflow['count'] < $this->cap) {
            $this->overflow['count']++;

            return true;
        }
        $this->overflow['suppressed']++;

        return false;
    }

    /** @param array{start: float, count: int, suppressed: int, stormSaid: bool} $window */
    private function hasExpired(array $window, float $now): bool
    {
        $elapsedMs = ($now - $window['start']) * 1000;

        // A clock that stepped BACKWARDS (NTP, `date -s`) would otherwise hold a window open for as long
        // as the step was large, muting a fingerprint past its window on a long-lived worker.
        return $elapsedMs >= $this->windowMs || $elapsedMs < 0;
    }
}
