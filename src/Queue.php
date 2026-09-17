<?php

declare(strict_types=1);

namespace AnvilFeed;

/**
 * A bounded FIFO. FR-2 d: it never exceeds max_queue_size. FR-3 b: an overflow is visible, never silent —
 * one warning per drop event, carrying the running count, so a support engineer reading a log can tell
 * "the endpoint was down for five minutes" from "this process produces more errors than it can report".
 *
 * The OLDEST goes when it is full. The newest error is the one somebody is looking at right now.
 */
final class Queue
{
    /** @var list<Event> */
    private array $events = [];

    private int $dropped = 0;

    public function __construct(private readonly int $maxSize, private readonly Logger $logger)
    {
    }

    public function push(Event $event): void
    {
        $this->events[] = $event;
        $this->trim();
    }

    /**
     * Puts a batch back at the FRONT, in its original order — the 429 and 503 cases. Order matters: an
     * incident's first occurrence is what the inbox shows as "first seen".
     *
     * @param list<Event> $events
     */
    public function unshiftAll(array $events): void
    {
        if ($events === []) {
            return;
        }
        $this->events = array_merge(array_values($events), $this->events);
        $this->trim();
    }

    /** @return list<Event> */
    public function take(int $count): array
    {
        return array_values(array_splice($this->events, 0, max(0, $count)));
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function droppedCount(): int
    {
        return $this->dropped;
    }

    public function clear(): void
    {
        $this->events = [];
    }

    private function trim(): void
    {
        $overflow = count($this->events) - $this->maxSize;
        if ($overflow <= 0) {
            return;
        }
        array_splice($this->events, 0, $overflow);
        $this->dropped += $overflow;
        $this->logger->warning(sprintf(
            'anvil-feed: local queue full at %d, dropped %d event(s) (%d since start).',
            $this->maxSize,
            $overflow,
            $this->dropped
        ));
    }
}
