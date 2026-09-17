<?php

declare(strict_types=1);

namespace AnvilFeed\Tests;

use AnvilFeed\Event;
use AnvilFeed\Logger;
use AnvilFeed\Queue;
use AnvilFeed\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class QueueTest extends TestCase
{
    private RecordingLogger $written;

    protected function setUp(): void
    {
        $this->written = new RecordingLogger();
    }

    private function queue(int $maxSize): Queue
    {
        return new Queue($maxSize, new Logger($this->written));
    }

    public function testNeverExceedsItsBoundAndDropsTheOldest(): void
    {
        $queue = $this->queue(3);
        foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
            $queue->push(new Event($id, ['event_id' => $id]));
        }

        self::assertSame(3, $queue->count());
        self::assertSame(2, $queue->droppedCount());
        self::assertSame(['c', 'd', 'e'], array_map(static fn (Event $e) => $e->id, $queue->take(3)));
    }

    public function testEveryDropIsSaidOnceAndNamesTheCount(): void
    {
        $queue = $this->queue(2);
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $queue->push(new Event($id, ['event_id' => $id]));
        }

        $warnings = $this->written->matching('local queue full');
        self::assertCount(2, $warnings, 'one warning per drop event — not per event, not one for all of them');
        self::assertStringContainsString('dropped 1 event(s) (1 since start)', $warnings[0]);
        self::assertStringContainsString('dropped 1 event(s) (2 since start)', $warnings[1],
            'the running total is what separates a five-minute outage from a permanently overloaded process');
    }

    public function testARequeuedBatchKeepsItsOrderAndGoesToTheFront(): void
    {
        $queue = $this->queue(10);
        $queue->push(new Event('c', []));
        $queue->unshiftAll([new Event('a', []), new Event('b', [])]);

        self::assertSame(['a', 'b', 'c'], array_map(static fn (Event $e) => $e->id, $queue->take(3)));
    }

    public function testARequeuedBatchIsBoundedToo(): void
    {
        $queue = $this->queue(3);
        $queue->push(new Event('d', []));
        $queue->unshiftAll([new Event('a', []), new Event('b', []), new Event('c', [])]);

        self::assertSame(3, $queue->count(), 'a 503 that repeats a full batch must not push the bound open');
        self::assertSame(['b', 'c', 'd'], array_map(static fn (Event $e) => $e->id, $queue->take(3)));
        self::assertSame(1, $queue->droppedCount());
    }

    public function testTakeNeverReturnsMoreThanAsked(): void
    {
        $queue = $this->queue(100);
        foreach (range(1, 10) as $i) {
            $queue->push(new Event((string) $i, []));
        }

        self::assertCount(4, $queue->take(4));
        self::assertSame(6, $queue->count());
        self::assertSame([], $queue->take(0));
        self::assertCount(6, $queue->take(99), 'asking for more than there is returns what there is');
    }

    public function testABoundBelowOneIsRefusedRatherThanTurningEveryPushIntoADropAndAWarning(): void
    {
        foreach ([0, -1, -1000] as $nonsense) {
            $queue = $this->queue($nonsense);
            $queue->push(new Event('a', []));
            $queue->push(new Event('b', []));

            self::assertSame(1, $queue->count(), "a bound of {$nonsense} must behave as a bound of one");
            self::assertSame(1, $queue->droppedCount(),
                'a negative bound would make the overflow exceed the queue and over-report drops');
            self::assertSame(['b'], array_map(static fn (Event $e) => $e->id, $queue->take(1)));
        }
    }

    public function testClearEmptiesIt(): void
    {
        $queue = $this->queue(10);
        $queue->push(new Event('a', []));
        $queue->clear();

        self::assertSame(0, $queue->count());
        self::assertSame([], $queue->take(5));
    }
}
