<?php

declare(strict_types=1);

namespace AnvilFeed\Tests;

use AnvilFeed\Config;
use AnvilFeed\LoopGuard;
use AnvilFeed\Logger;
use AnvilFeed\RateGate;
use PHPUnit\Framework\TestCase;

final class LoopGuardTest extends TestCase
{
    public function testIgnoresTheIngestsOwnPaths(): void
    {
        $guard = new LoopGuard(['/api/feed/**']);

        self::assertTrue($guard->ignoresPath('/api/feed/events'));
        self::assertTrue($guard->ignoresPath('/api/feed/events/batch'));
        self::assertTrue($guard->ignoresPath('/api/feed/events?x=1'));
        self::assertFalse($guard->ignoresPath('/api/feedback'));
        self::assertFalse($guard->ignoresPath('/api/bookings'));
    }

    /**
     * The two spellings of the same intention — the ruling's `/api/feed` and the shipped default
     * `/api/feed/**` — must protect the same set of paths, including the collection route itself. A
     * `/**` that did not cover its own directory would leave the default one route short of the one it
     * exists for, and `/api/feedback` must stay reportable either way: the boundary is the slash.
     */
    public function testBothSpellingsOfTheDefaultCoverTheCollectionRouteAndNeitherOvershoots(): void
    {
        foreach (['/api/feed/**', '/api/feed'] as $spelling) {
            $guard = new LoopGuard([$spelling]);

            self::assertTrue($guard->ignoresPath('/api/feed'), $spelling);
            self::assertFalse($guard->ignoresPath('/api/feedback'), $spelling);
        }

        self::assertTrue((new LoopGuard(['/api/feed/**']))->ignoresPath('/api/feed/events'));
    }

    /** The default the SDK actually ships with, taken from Config rather than retyped here. */
    public function testTheConfiguredDefaultIsTheOneThatIsTested(): void
    {
        $config = Config::fromArray([
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
        ], new Logger());
        $guard = new LoopGuard($config->ignoredRequestPaths);

        self::assertTrue($guard->ignoresPath('/api/feed/events'));
        self::assertTrue($guard->ignoresPath('https://app.anvil-coder.tech/api/feed/events'),
            'a host that reports a full URL gets the same answer as one that reports a path');
        self::assertFalse($guard->ignoresPath('/api/bookings'));
    }

    public function testASingleStarStopsAtASegmentBoundary(): void
    {
        $guard = new LoopGuard(['/internal/*']);

        self::assertTrue($guard->ignoresPath('/internal/health'));
        self::assertFalse($guard->ignoresPath('/internal/health/deep'));
    }

    public function testNoPatternsMeansNothingIsIgnored(): void
    {
        $guard = new LoopGuard([]);

        self::assertFalse($guard->ignoresPath('/api/feed/events'));
        self::assertFalse($guard->ignoresPath(null));
        self::assertFalse($guard->ignoresPath(''));
    }

    public function testReentrancyIsShut(): void
    {
        $guard = new LoopGuard([]);

        self::assertTrue($guard->enterSend());
        self::assertFalse($guard->enterSend(), 'the sender never captures its own failures');
        $guard->leaveSend();
        self::assertTrue($guard->enterSend());
    }

    public function testTheRateGateCapsOneFingerprintPerWindow(): void
    {
        $now = 1000.0;
        $gate = new RateGate(3, 60000, function () use (&$now) { return $now; });

        self::assertTrue($gate->allow('f'));
        self::assertTrue($gate->allow('f'));
        self::assertTrue($gate->allow('f'));
        self::assertFalse($gate->allow('f'));
        self::assertTrue($gate->allow('other'), 'a different fault is not suppressed');

        $now += 61.0;
        self::assertTrue($gate->allow('f'), 'the window moves on');
    }

    /**
     * The count is what the one log line per window reports, so it has to be the number of events that
     * were actually dropped — and it has to start again with the window, or the second window's line
     * would carry the first window's total.
     */
    public function testTheSuppressedCountIsPerWindowAndCountsOnlyWhatWasDropped(): void
    {
        $now = 1000.0;
        $gate = new RateGate(2, 60000, function () use (&$now) { return $now; });

        $gate->allow('f');
        $gate->allow('f');
        self::assertSame(0, $gate->suppressed('f'), 'nothing has been dropped yet');

        $gate->allow('f');
        $gate->allow('f');
        self::assertSame(2, $gate->suppressed('f'));
        self::assertSame(0, $gate->suppressed('never-seen'));

        $now += 61.0;
        $gate->allow('f');
        self::assertSame(0, $gate->suppressed('f'), 'a new window starts its own count');
    }

    /**
     * FR-2 d: the table that exists to bound a storm must not itself grow without bound. An application
     * producing thousands of DISTINCT faults is exactly the case this class is reached on.
     */
    public function testTheTableOfTrackedFingerprintsIsBounded(): void
    {
        $gate = new RateGate(1, 60000, static fn (): float => 1000.0);
        for ($i = 0; $i < 5000; $i++) {
            $gate->allow('fingerprint-' . $i);
        }

        $windows = (new \ReflectionProperty(RateGate::class, 'windows'))->getValue($gate);
        self::assertLessThanOrEqual(500, count($windows),
            '5000 distinct faults must not leave 5000 rows behind');
    }

    /**
     * The defect this class exists to prevent, in the shape that defeated the first version: a storm plus
     * churn. The loud fingerprint was tracked first, so under "evict the oldest entry" it was the FIRST
     * one thrown out — the gate evicted precisely what it was capping, and the storm walked back through
     * with a fresh count. Nothing in the log said so.
     *
     * Every distinct fingerprint here is live (the clock is frozen), so the table fills with nothing
     * expired, which is exactly the case the shared overflow bucket answers.
     */
    public function testAStormingFingerprintIsNotEvictedByAChurnOfDistinctOnes(): void
    {
        $now = 1000.0;
        $gate = new RateGate(2, 60000, function () use (&$now) { return $now; });

        self::assertTrue($gate->allow('HOT'));
        self::assertTrue($gate->allow('HOT'));
        self::assertFalse($gate->allow('HOT'), 'capped, as it must be');

        for ($i = 0; $i < 2000; $i++) {   // four times the table, all of them live
            $gate->allow('cold-' . $i);
        }

        self::assertFalse($gate->allow('HOT'), 'the cap survived the churn — nothing evicted a live entry');
        self::assertGreaterThan(0, $gate->suppressed('HOT'));
    }

    /**
     * And the newcomers there was no room to track are capped too, against a shared window. Letting one
     * through because the table is full would be an uncapped storm on the one path that exists to cap
     * storms.
     *
     * The bucket's count and a fingerprint's own count are two different facts, and they are two
     * different questions. `suppressed()` used to fall back to the bucket for every fingerprint it did
     * not track apart, so a caller asking about one that had never been suppressed at all was answered
     * with somebody else's storm (Java's task-8 review finding 4). This test therefore pins both halves:
     * zero for the untracked fingerprint, and the drops still countable by name.
     */
    public function testAFingerprintTheTableHasNoRoomForIsStillCapped(): void
    {
        $now = 1000.0;
        $gate = new RateGate(3, 60000, function () use (&$now) { return $now; });
        for ($i = 0; $i < 600; $i++) {
            $gate->allow('live-' . $i);   // the table fills and stays live
        }

        $allowed = 0;
        for ($i = 0; $i < 50; $i++) {
            $allowed += $gate->allow('newcomer-' . $i) ? 1 : 0;
        }

        self::assertLessThanOrEqual(3, $allowed, 'the overflow bucket caps them together');
        self::assertSame(0, $gate->suppressed('newcomer-0'),
            'a fingerprint the table never tracked has no count of its own — the bucket\'s count is the '
            . 'bucket\'s, and answering with it would name a storm about a fingerprint that was never '
            . 'suppressed apart');
        self::assertGreaterThan(0, $gate->suppressedOverflow(),
            'and the drops are countable by name, so an overflowed storm can still be said');
    }

    /** An expired entry is capping nothing, so it is the one that makes room — and the table stays bounded. */
    public function testAnExpiredEntryIsTheOneThatMakesRoom(): void
    {
        $now = 1000.0;
        $gate = new RateGate(1, 60000, function () use (&$now) { return $now; });
        for ($i = 0; $i < 500; $i++) {
            $gate->allow('old-' . $i);
        }

        $now += 61.0;   // every tracked window has expired
        self::assertTrue($gate->allow('fresh'));

        $windows = (new \ReflectionProperty(RateGate::class, 'windows'))->getValue($gate);
        self::assertArrayHasKey('fresh', $windows, 'a fresh fingerprint is tracked apart, not overflowed');
        self::assertLessThanOrEqual(500, count($windows));
    }

    /**
     * A clock that steps backwards (NTP, `date -s`) must not hold a window open for the size of the step.
     * The gate's own default clock is monotonic; this is the guard for an injected one that is not.
     */
    public function testAClockThatStepsBackwardsDoesNotMuteAFingerprintForEver(): void
    {
        $now = 1000.0;
        $gate = new RateGate(1, 60000, function () use (&$now) { return $now; });

        self::assertTrue($gate->allow('f'));
        self::assertFalse($gate->allow('f'));

        $now -= 3600.0;   // the clock jumped an hour into the past
        self::assertTrue($gate->allow('f'), 'a backwards step opens a new window rather than freezing one');
    }

    /** Finding 6: every shape a rewrite rule or a permissive router can put in front of the guard. */
    public function testMatchingIsCaseInsensitiveAndResolvesDotSegments(): void
    {
        $guard = new LoopGuard(['/api/feed/**']);

        self::assertTrue($guard->ignoresPath('/API/FEED/events'));
        self::assertTrue($guard->ignoresPath('/app/../api/feed/events'));
        self::assertTrue($guard->ignoresPath('//api/feed//events'));
        self::assertTrue($guard->ignoresPath('/api/feed/./events'));
        self::assertTrue($guard->ignoresPath('/api/feed/'));
        self::assertTrue($guard->ignoresPath('https://app.anvil-coder.tech/API/Feed/events'));
        self::assertFalse($guard->ignoresPath('/api/feedback'), 'and still no overshoot');
    }

    /** A pattern written in mixed case by an operator has to work too, not only a mixed-case request. */
    public function testAPatternWrittenInMixedCaseMatches(): void
    {
        $guard = new LoopGuard(['/Internal/Health']);

        self::assertTrue($guard->ignoresPath('/internal/health'));
        self::assertTrue($guard->ignoresPath('/INTERNAL/HEALTH'));
    }

    /**
     * A cap of zero would switch error reporting off entirely and a window of zero would make every
     * event its own window — no cap at all. Both are a typo in a config file, and neither may be obeyed
     * silently.
     */
    public function testANonsensicalCapNeitherSilencesEverythingNorCapsNothing(): void
    {
        $now = 1000.0;
        $clock = function () use (&$now) { return $now; };

        $silent = new RateGate(0, 60000, $clock);
        self::assertTrue($silent->allow('f'), 'a cap of zero still lets the first event through');
        self::assertFalse($silent->allow('f'));

        $uncapped = new RateGate(2, 0, $clock);
        self::assertTrue($uncapped->allow('g'));
        self::assertTrue($uncapped->allow('g'));
        self::assertFalse($uncapped->allow('g'), 'a window of zero is still a window');
    }
}
