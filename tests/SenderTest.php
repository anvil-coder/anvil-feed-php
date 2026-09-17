<?php

declare(strict_types=1);

namespace AnvilFeed\Tests;

use AnvilFeed\Config;
use AnvilFeed\Event;
use AnvilFeed\Logger;
use AnvilFeed\Queue;
use AnvilFeed\Sender;
use AnvilFeed\Tests\Support\RecordingLogger;
use AnvilFeed\Tests\Support\RecordingTransport;
use AnvilFeed\Transport\Response;
use PHPUnit\Framework\TestCase;

final class SenderTest extends TestCase
{
    private float $now = 1_000_000.0;

    private RecordingLogger $written;

    protected function setUp(): void
    {
        $this->now = 1_000_000.0;
        $this->written = new RecordingLogger();
    }

    /** @param array<string, mixed> $options */
    private function config(array $options = []): Config
    {
        return Config::fromArray(array_merge([
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
        ], $options), new Logger($this->written));
    }

    private function event(string $id): Event
    {
        return new Event($id, ['event_id' => $id, 'service' => 'disponio-api']);
    }

    private function queue(int $maxSize = 1000): Queue
    {
        return new Queue($maxSize, new Logger($this->written));
    }

    /** @param array<string, mixed> $options */
    private function sender(Queue $queue, RecordingTransport $transport, array $options = []): Sender
    {
        return new Sender(
            $this->config($options),
            $queue,
            $transport,
            new Logger($this->written),
            fn (): float => $this->now
        );
    }

    private function fixture(string $name): string
    {
        $body = file_get_contents(__DIR__ . '/../../../conformance/responses/' . $name);
        self::assertIsString($body, "the conformance fixture {$name} must be readable");

        return $body;
    }

    public function testASingleEventGoesToTheObjectEndpointAsAnObject(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('11111111-1111-4111-8111-111111111111'));
        $transport = new RecordingTransport(new Response(202,
            '{"accepted":["11111111-1111-4111-8111-111111111111"],"rejected":[]}'));

        $this->sender($queue, $transport)->flush();

        self::assertStringEndsWith('/api/feed/events', $transport->calls[0]['url']);
        self::assertSame('{', $transport->calls[0]['body'][0]);
        self::assertSame(0, $queue->count());
    }

    public function testTwoEventsGoToTheBatchEndpointAsAnArray(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('11111111-1111-4111-8111-111111111111'));
        $queue->push($this->event('22222222-2222-4222-8222-222222222222'));
        $transport = new RecordingTransport(new Response(202, '{"accepted":[],"rejected":[]}'));

        $this->sender($queue, $transport)->flush();

        self::assertStringEndsWith('/api/feed/events/batch', $transport->calls[0]['url']);
        self::assertSame('[', $transport->calls[0]['body'][0]);
    }

    public function testNoBatchEverExceedsTheIngestsFiftyEventCap(): void
    {
        $queue = $this->queue();
        for ($i = 0; $i < 120; $i++) {
            $queue->push($this->event(sprintf('11111111-1111-4111-8111-%012d', $i)));
        }
        $transport = new RecordingTransport();

        $this->sender($queue, $transport)->flush();

        self::assertCount(3, $transport->calls, '120 events are three requests, not one');
        foreach ($transport->calls as $call) {
            $decoded = json_decode($call['body'], true);
            self::assertIsArray($decoded);
            self::assertLessThanOrEqual(50, count($decoded), 'the ingest refuses a batch above 50 with a 400');
        }
        self::assertSame(0, $queue->count());
    }

    public function testA429PausesForExactlyRetryAfterAndKeepsTheEvents(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('11111111-1111-4111-8111-111111111111'));
        $transport = new RecordingTransport(new Response(429, '', 60));

        $sender = $this->sender($queue, $transport);
        $sender->flush();

        self::assertSame(1, $queue->count(), 'a 429 must not drop the event');
        self::assertSame($this->now + 60.0, $sender->pausedUntil());

        $this->now += 59.0;
        $sender->flush();
        self::assertCount(1, $transport->calls, 'no further request inside the Retry-After window');

        $this->now += 2.0;
        $sender->flush();
        self::assertCount(2, $transport->calls, 'sending resumes once the window is over');
    }

    public function testA429WithoutARetryAfterHeaderFallsBackToTheDocumentedSixtySeconds(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('11111111-1111-4111-8111-111111111111'));
        $transport = new RecordingTransport(new Response(429, ''));

        $sender = $this->sender($queue, $transport);
        $sender->flush();

        self::assertSame($this->now + 60.0, $sender->pausedUntil());
        self::assertSame(1, $queue->count());
    }

    public function testA401StopsSendingForTheProcessLifetime(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('11111111-1111-4111-8111-111111111111'));
        $transport = new RecordingTransport(new Response(401, ''));

        $sender = $this->sender($queue, $transport);
        $sender->flush();
        $queue->push($this->event('22222222-2222-4222-8222-222222222222'));
        $sender->flush();

        // The behaviour first, the flag second: a test that asks isStopped() before it asks whether
        // anything was still sent would pass on an implementation that sets the flag and keeps posting.
        self::assertCount(1, $transport->calls, 'nothing is sent again for the lifetime of the process');
        self::assertSame(0, $queue->count(), 'a stopped sender empties the queue instead of growing it');
        self::assertTrue($sender->isStopped());
        self::assertCount(1, $this->written->matching('401'), 'the 401 is said once, not on every flush');
    }

    public function testA400DropsTheBatchAndNeverRetries(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('11111111-1111-4111-8111-111111111111'));
        $transport = new RecordingTransport(new Response(400, $this->fixture('400-schema-violation.json')));

        $this->sender($queue, $transport)->flush();

        self::assertSame(0, $queue->count());
        self::assertCount(1, $transport->calls);
    }

    public function testA400KeepsTheSenderAliveAndIsLoggedOncePerProcess(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('11111111-1111-4111-8111-111111111111'));
        $transport = new RecordingTransport(
            new Response(400, $this->fixture('400-schema-violation.json')),
            new Response(400, $this->fixture('400-schema-violation.json')),
            new Response(202, '{"accepted":[],"rejected":[]}')
        );

        $sender = $this->sender($queue, $transport);
        $sender->flush();
        $queue->push($this->event('22222222-2222-4222-8222-222222222222'));
        $sender->flush();
        $queue->push($this->event('33333333-3333-4333-8333-333333333333'));
        $sender->flush();

        self::assertFalse($sender->isStopped(), 'a 400 is the sender being wrong about one batch, not a stop');
        self::assertCount(3, $transport->calls, 'later events are still sent');
        self::assertCount(1, $this->written->matching('400'), 'the 400 explanation is said once per process');
    }

    public function testA403DropsTheBatchSaysItOnceAndKeepsSending(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('11111111-1111-4111-8111-111111111111'));
        $transport = new RecordingTransport(new Response(403, ''), new Response(403, ''));

        $sender = $this->sender($queue, $transport);
        $sender->flush();
        $queue->push($this->event('22222222-2222-4222-8222-222222222222'));
        $sender->flush();

        self::assertFalse($sender->isStopped());
        self::assertSame(0, $queue->count());
        self::assertCount(2, $transport->calls);
        self::assertCount(1, $this->written->matching('403'));
        self::assertNotSame([], $this->written->matching('Rotate'), 'a 403 has exactly one remedy: rotate');
    }

    public function testA503RepeatsTheWholeBatchAfterRetryAfter(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('11111111-1111-4111-8111-111111111111'));
        $queue->push($this->event('22222222-2222-4222-8222-222222222222'));
        $transport = new RecordingTransport(
            new Response(503, $this->fixture('503-transient.json'), 30),
            new Response(202, '{"accepted":[],"rejected":[]}')
        );

        $sender = $this->sender($queue, $transport);
        $sender->flush();
        self::assertSame(2, $queue->count(), 'the WHOLE batch is repeated, dedup makes that safe');
        self::assertSame($this->now + 30.0, $sender->pausedUntil());

        $this->now += 31.0;
        $sender->flush();
        self::assertSame(0, $queue->count());
    }

    public function testPartialSuccessClearsOnlyTheEventsThatGotAVerdict(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('f4b1a0c2-0f4a-4a1e-9d7a-2b6c5d8e1f30'));
        $queue->push($this->event('9b1c7f2e-5a34-4c18-8e0d-1f7a2b3c4d5e'));
        $transport = new RecordingTransport(new Response(202, $this->fixture('202-partial.json')));

        $this->sender($queue, $transport)->flush();

        self::assertSame(0, $queue->count(), 'accepted and rejected are both final verdicts');
        self::assertNotSame([], $this->written->matching('no_binding'),
            'a rejected event is dropped WITH its reason code, or nobody ever learns why the inbox is empty');
        self::assertNotSame([], $this->written->matching('9b1c7f2e-5a34-4c18-8e0d-1f7a2b3c4d5e'));
    }

    public function testAnOperatorSideRejectionIsSaidOnceRatherThanOncePerEvent(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('f4b1a0c2-0f4a-4a1e-9d7a-2b6c5d8e1f30'));
        $queue->push($this->event('9b1c7f2e-5a34-4c18-8e0d-1f7a2b3c4d5e'));
        $transport = new RecordingTransport(
            new Response(202, $this->fixture('202-partial.json')),
            new Response(202, $this->fixture('202-partial.json'))
        );

        $sender = $this->sender($queue, $transport);
        $sender->flush();
        $queue->push($this->event('9b1c7f2e-5a34-4c18-8e0d-1f7a2b3c4d5e'));
        $sender->flush();

        self::assertCount(1, $this->written->matching('no_binding'),
            'no_binding is the operator\'s to fix; repeating it per event buries the log it appears in');
    }

    public function testATimeoutBacksOffExponentiallyWithJitter(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('11111111-1111-4111-8111-111111111111'));
        $transport = new RecordingTransport(Response::failure('connection refused'));

        $sender = $this->sender($queue, $transport);
        $sender->flush();

        self::assertSame(1, $queue->count());
        self::assertGreaterThanOrEqual($this->now + 0.8, $sender->pausedUntil());
        self::assertLessThanOrEqual($this->now + 1.2, $sender->pausedUntil());
    }

    public function testTheBackoffGrowsAndIsCapped(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('11111111-1111-4111-8111-111111111111'));
        $transport = new RecordingTransport(
            ...array_fill(0, 12, Response::failure('connection refused'))
        );

        $sender = $this->sender($queue, $transport);
        $waits = [];
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $sender->flush();
            $waits[] = $sender->pausedUntil() - $this->now;
            $this->now = $sender->pausedUntil() + 0.001;
        }

        self::assertGreaterThan($waits[0], $waits[3], 'the wait grows with consecutive failures');
        foreach ($waits as $wait) {
            self::assertLessThanOrEqual(36.0, $wait, 'the backoff is capped: 30s plus at most 20% jitter');
        }
        self::assertSame(1, $queue->count(), 'the event stays in the queue through every retry');
    }

    public function testASuccessfulSendForgetsTheBackoff(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('11111111-1111-4111-8111-111111111111'));
        $transport = new RecordingTransport(
            Response::failure('connection refused'),
            Response::failure('connection refused'),
            Response::failure('connection refused'),
            new Response(202, '{"accepted":[],"rejected":[]}'),
            Response::failure('connection refused')
        );

        $sender = $this->sender($queue, $transport);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $sender->flush();
            $this->now = $sender->pausedUntil() + 0.001;
        }
        $sender->flush();                       // the 202
        $queue->push($this->event('22222222-2222-4222-8222-222222222222'));
        $sender->flush();                       // fails again, but as a FIRST failure

        self::assertLessThanOrEqual($this->now + 1.2, $sender->pausedUntil(),
            'a delivery that worked resets the attempt counter; otherwise one bad minute costs 30s for ever');
    }

    public function testA413HalvesTheBatchAndSendsBothHalves(): void
    {
        $queue = $this->queue();
        foreach (range(0, 3) as $i) {
            $queue->push($this->event(sprintf('11111111-1111-4111-8111-%012d', $i)));
        }
        $transport = new RecordingTransport(
            new Response(413, $this->fixture('413-payload-too-large.json')),
            new Response(202, '{"accepted":[],"rejected":[]}'),
            new Response(202, '{"accepted":[],"rejected":[]}')
        );

        $this->sender($queue, $transport)->flush();

        self::assertCount(3, $transport->calls, 'one refused batch of four, then two halves of two');
        self::assertSame(0, $queue->count());
        self::assertCount(2, json_decode($transport->calls[1]['body'], true));
        self::assertCount(2, json_decode($transport->calls[2]['body'], true));
    }

    public function testTheSecondHalfOfASplitIsNeverLostWhenTheFirstHalfPauses(): void
    {
        $queue = $this->queue();
        foreach (range(0, 3) as $i) {
            $queue->push($this->event(sprintf('11111111-1111-4111-8111-%012d', $i)));
        }
        $transport = new RecordingTransport(
            new Response(413, $this->fixture('413-payload-too-large.json')),
            new Response(429, '', 60)
        );

        $sender = $this->sender($queue, $transport);
        $sender->flush();

        self::assertSame(4, $queue->count(),
            'the half that was never attempted must go back into the queue, not onto the floor');
        self::assertSame(
            ['11111111-1111-4111-8111-000000000000', '11111111-1111-4111-8111-000000000001',
             '11111111-1111-4111-8111-000000000002', '11111111-1111-4111-8111-000000000003'],
            array_map(static fn (Event $e): string => $e->id, $queue->take(4)),
            'and it must go back in order'
        );
    }

    public function testASingleEventTheIngestCallsTooLargeIsDroppedRatherThanRetriedForEver(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('11111111-1111-4111-8111-111111111111'));
        $transport = new RecordingTransport(new Response(413, $this->fixture('413-payload-too-large.json')));

        $sender = $this->sender($queue, $transport);
        $sender->flush();

        self::assertSame(0, $queue->count(), 'there is nothing left to split');
        self::assertCount(1, $transport->calls);
        self::assertNotSame([], $this->written->matching('11111111-1111-4111-8111-111111111111'));
    }

    public function testABodyIsSplitBeforeItReachesTheIngestsSizeLimit(): void
    {
        $queue = $this->queue();
        foreach (range(0, 5) as $i) {
            $queue->push(new Event(
                sprintf('11111111-1111-4111-8111-%012d', $i),
                ['event_id' => sprintf('11111111-1111-4111-8111-%012d', $i), 'title' => str_repeat('x', 50_000)]
            ));
        }
        $transport = new RecordingTransport();

        $this->sender($queue, $transport)->flush();

        self::assertGreaterThan(1, count($transport->calls), 'a 300 KB batch cannot go out as one body');
        foreach ($transport->calls as $call) {
            self::assertLessThanOrEqual(262144, strlen($call['body']),
                'the ingest answers 413 above 262144 bytes; the sender must not get there by itself');
        }
        self::assertSame(0, $queue->count());
    }

    public function testTheEventIdIsMatchedExactlyAsItWasSpelled(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('F4B1A0C2-0F4A-4A1E-9D7A-2B6C5D8E1F30'));
        $transport = new RecordingTransport(new Response(202,
            '{"accepted":["F4B1A0C2-0F4A-4A1E-9D7A-2B6C5D8E1F30"],"rejected":[]}'));

        $this->sender($queue, $transport)->flush();

        self::assertStringContainsString('F4B1A0C2-0F4A-4A1E-9D7A-2B6C5D8E1F30', $transport->calls[0]['body'],
            'the id goes on the wire exactly as it was spelled — the ingest echoes it back verbatim and an '
            . 'SDK that re-spells it can never match the answer against what it posted');
        self::assertSame(0, $queue->count(), 'an upper-case id must clear the buffer, not resend for ever');
    }

    public function testAnEventThatCannotBeEncodedIsDroppedInsteadOfPoisoningTheQueue(): void
    {
        $queue = $this->queue();
        $queue->push(new Event('11111111-1111-4111-8111-111111111111',
            ['event_id' => '11111111-1111-4111-8111-111111111111', 'title' => "\xB1\x31 invalid utf-8"]));
        $queue->push($this->event('22222222-2222-4222-8222-222222222222'));
        $transport = new RecordingTransport(new Response(202, '{"accepted":[],"rejected":[]}'));

        $this->sender($queue, $transport)->flush();

        self::assertCount(1, $transport->calls, 'the encodable event still travels');
        self::assertSame('{', $transport->calls[0]['body'][0], 'and it travels alone, as an object');
        self::assertSame(0, $queue->count());
        self::assertNotSame([], $this->written->matching('cannot be encoded'));
    }

    public function testATransportThatThrowsNeverReachesTheHostApplication(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('11111111-1111-4111-8111-111111111111'));
        $throwing = new class implements \AnvilFeed\Transport\Transport {
            public function post(string $url, string $body): \AnvilFeed\Transport\Response
            {
                throw new \RuntimeException('the host application\'s HTTP client exploded');
            }
        };

        $sender = new Sender($this->config(), $queue, $throwing, new Logger($this->written),
            fn (): float => $this->now);
        $sender->flush();

        self::assertSame(1, $queue->count(), 'treated as a transport failure: the event is kept');
        self::assertGreaterThan($this->now, $sender->pausedUntil());
    }

    public function testFlushRespectsItsDeadlineAndKeepsWhatItCouldNotSend(): void
    {
        $queue = $this->queue();
        foreach (range(0, 2) as $i) {
            $queue->push($this->event(sprintf('11111111-1111-4111-8111-%012d', $i)));
        }
        $transport = new RecordingTransport();

        $this->sender($queue, $transport)->flush($this->now - 1.0);

        self::assertSame([], $transport->calls, 'a deadline already past means no request at all');
        self::assertSame(3, $queue->count(), 'and nothing is lost by the attempt');
    }

    public function testADisabledClientSendsNothing(): void
    {
        $queue = $this->queue();
        $queue->push($this->event('11111111-1111-4111-8111-111111111111'));
        $transport = new RecordingTransport();

        $this->sender($queue, $transport, ['enabled' => false])->flush();

        self::assertSame([], $transport->calls);
    }

    public function testAnEmptyQueueIsNotARequest(): void
    {
        $transport = new RecordingTransport();

        $this->sender($this->queue(), $transport)->flush();

        self::assertSame([], $transport->calls, 'a shutdown hook on a healthy request must cost nothing');
    }
}
