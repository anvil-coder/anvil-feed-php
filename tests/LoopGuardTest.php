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

        // The most recent is still tracked and capped; the oldest was evicted rather than remembered.
        self::assertFalse($gate->allow('fingerprint-4999'));
        self::assertTrue($gate->allow('fingerprint-0'), 'the oldest was evicted, not kept for ever');

        $windows = (new \ReflectionProperty(RateGate::class, 'windows'))->getValue($gate);
        self::assertLessThanOrEqual(500, count($windows),
            '5000 distinct faults must not leave 5000 rows behind');
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
