<?php

declare(strict_types=1);

namespace AnvilFeed\Tests;

use AnvilFeed\AnvilFeed;
use AnvilFeed\Client;
use AnvilFeed\Config;
use AnvilFeed\EventFactory;
use AnvilFeed\Fingerprint;
use AnvilFeed\GitContext;
use AnvilFeed\Logger;
use AnvilFeed\Tests\Support\RecordingLogger;
use AnvilFeed\Tests\Support\RecordingTransport;
use AnvilFeed\Transport\Response;
use PHPUnit\Framework\TestCase;

final class ThrowingTransport implements \AnvilFeed\Transport\Transport
{
    public function post(string $url, string $body, ?int $timeoutMs = null): Response
    {
        throw new \RuntimeException('the transport itself exploded');
    }
}

/**
 * The loop in its purest form: a transport that reports its own failure through the very client that is
 * flushing it. Without the reentrancy guard this recurses until the process dies.
 */
final class SelfReportingTransport implements \AnvilFeed\Transport\Transport
{
    public ?Client $client = null;
    public int $captureAttempts = 0;
    public int $posts = 0;

    public function post(string $url, string $body, ?int $timeoutMs = null): Response
    {
        $this->posts++;
        $this->captureAttempts++;
        $this->client?->captureException(new \RuntimeException('the ingest is down'));

        return Response::failure('connection refused');
    }
}

final class ClientTest extends TestCase
{
    protected function tearDown(): void
    {
        AnvilFeed::reset();
        unset($_SERVER['REQUEST_URI']);
    }

    /** @param array<string, mixed> $options */
    private function client(
        array $options = [],
        ?\AnvilFeed\Transport\Transport $transport = null,
        ?Logger $logger = null,
    ): Client {
        $config = Config::fromArray(array_merge([
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
        ], $options), $logger ?? new Logger());

        return Client::create($config, $transport ?? new RecordingTransport(), $logger ?? new Logger(),
            GitContext::none());
    }

    public function testCaptureQueuesExactlyOneEvent(): void
    {
        $client = $this->client();
        $id = $client->captureException(new \RuntimeException('boom'));

        self::assertNotNull($id);
        self::assertSame(1, $client->queue()->count());
        self::assertSame($id, $client->queue()->take(1)[0]->payload['event_id']);
    }

    public function testNothingIsQueuedWithoutAToken(): void
    {
        $client = Client::create(
            Config::fromArray(['service' => 'disponio-api'], new Logger()),
            new RecordingTransport(),
            new Logger(),
            GitContext::none()
        );

        self::assertNull($client->captureException(new \RuntimeException('boom')));
        self::assertSame(0, $client->queue()->count());
    }

    public function testAStormIsSuppressedLocally(): void
    {
        $client = $this->client(['fingerprint_cap' => 2]);
        for ($i = 0; $i < 20; $i++) {
            $client->captureMessage('the same fault every time', 'error');
        }

        self::assertLessThanOrEqual(2, $client->queue()->count());
    }

    /**
     * The cap is the reason the fingerprint exists, so it is worth pinning exactly rather than by "no
     * more than": 10 through, the other 990 dropped, and ONE log line for the whole window — a line per
     * dropped event would be the same flood in the log file that the queue was spared.
     */
    public function testAThousandOccurrencesOfOneFaultCostTenEventsAndOneLogLine(): void
    {
        $sink = new RecordingLogger();
        $logger = new Logger($sink);
        $client = $this->client([], null, $logger);

        for ($i = 0; $i < 1000; $i++) {
            $client->captureMessage('the same fault every time', 'error');
        }

        self::assertSame(10, $client->queue()->count(), 'fingerprint_cap defaults to 10 per window');
        self::assertCount(1, $sink->matching('suppressing locally'));
        self::assertCount(1, $sink->matching('anvil-feed:'), 'nothing else was said at all');
    }

    /**
     * A second, unrelated fault is not silenced by the first one's storm — and "unrelated" means what the
     * INGEST means by it. Two different message texts raised from the same line are one group, because
     * IncidentFingerprint deliberately leaves the message out (messages carry order numbers, and one
     * incident per order is no incident at all). The cap therefore suppresses exactly what the cockpit
     * would have shown as one incident, which is the only reading under which suppressing is safe.
     */
    public function testADifferentFaultIsNotSuppressedByAnothersStorm(): void
    {
        $client = $this->client(['fingerprint_cap' => 1]);

        self::assertNotNull($client->captureException(new \RuntimeException('fault A')));
        self::assertNull($client->captureException(new \RuntimeException('fault A')));
        self::assertNotNull($client->captureException(new \LogicException('a different type entirely')));

        self::assertNotNull($client->captureMessage('a log line', 'error'));
        self::assertNull($client->captureMessage('a different text, the same call site', 'error'),
            'the message is not part of the identity — the server says so, and we must agree with it');
    }

    public function testBeforeSendCanDropAnEvent(): void
    {
        $client = $this->client();
        $client->beforeSend(static fn (array $payload) => null);

        self::assertNull($client->captureException(new \RuntimeException('boom')));
        self::assertSame(0, $client->queue()->count());
    }

    public function testBeforeSendCanRewriteAnEvent(): void
    {
        $client = $this->client();
        $client->beforeSend(static function (array $payload): array {
            $payload['tags'] = ['reviewed' => 'yes'];

            return $payload;
        });
        $client->captureException(new \RuntimeException('boom'));

        self::assertSame(['reviewed' => 'yes'], $client->queue()->take(1)[0]->payload['tags']);
    }

    /**
     * FR-2 for the hook: it is host code, and host code that throws may cost the modification but never
     * the event and never the request. The line is said once, because a hook that throws throws on every
     * event of the storm behind it.
     */
    public function testAThrowingBeforeSendCostsTheModificationAndNotTheEvent(): void
    {
        $sink = new RecordingLogger();
        $client = $this->client([], null, new Logger($sink));
        $client->beforeSend(static function (array $payload): array {
            throw new \LogicException('the host hook is broken');
        });

        $first = $client->captureException(new \RuntimeException('boom'));
        $second = $client->captureMessage('something else entirely', 'error');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(2, $client->queue()->count());
        self::assertSame('RuntimeException: boom', $client->queue()->take(1)[0]->payload['title'],
            'the event went out as this SDK built it');
        self::assertCount(1, $sink->matching('beforeSend threw'), 'said once, not once per event');
    }

    /** A hook that returns rubbish is a hook that dropped the event, not one that corrupts the wire. */
    public function testABeforeSendThatReturnsSomethingUnusableDropsTheEvent(): void
    {
        $client = $this->client();
        $client->beforeSend(static fn (array $payload) => []);

        self::assertNull($client->captureException(new \RuntimeException('boom')));
        self::assertSame(0, $client->queue()->count());
    }

    /** FR-2 b, the whole of it: nothing in here ever reaches the caller. */
    public function testAFailingTransportNeverReachesTheCaller(): void
    {
        $client = $this->client([], new ThrowingTransport());
        $client->captureException(new \RuntimeException('boom'));

        $client->flush();

        self::assertTrue(true, 'reaching this line is the assertion');
    }

    public function testAnExceptionOnAnIgnoredPathIsNotReported(): void
    {
        $client = $this->client();
        $id = $client->captureException(new \RuntimeException('the ingest itself failed'), [
            'request' => ['method' => 'POST', 'url' => '/api/feed/events'],
        ]);

        self::assertNull($id);
        self::assertSame(0, $client->queue()->count());
    }

    /**
     * The same shot without an explicit context. A Laravel exception handler that does not pass the
     * request still runs inside one, and the ingest's own route is the loop either way.
     */
    public function testTheServedRequestIsConsultedWhenTheCallerPassesNoContext(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/feed/events/batch?tenant=7';
        $client = $this->client();

        self::assertNull($client->captureException(new \RuntimeException('the ingest itself failed')));

        $_SERVER['REQUEST_URI'] = '/api/bookings';
        self::assertNotNull($client->captureException(new \RuntimeException('an ordinary fault')));
    }

    /**
     * The reentrancy leg of the loop guard, driven through the real sender: the transport reports its own
     * failure back into the client while the flush is running. Nothing may be queued by that call.
     */
    public function testTheSendersOwnFailureIsNeverCaptured(): void
    {
        $transport = new SelfReportingTransport();
        $client = $this->client([], $transport);
        $transport->client = $client;

        $client->captureException(new \RuntimeException('the fault the customer cares about'));
        self::assertSame(1, $client->queue()->count());

        $client->flush();

        self::assertGreaterThan(0, $transport->captureAttempts, 'the transport really did try to report');
        self::assertSame(1, $client->queue()->count(),
            'only the original event is left — the sender never queued its own failure');
    }

    /** FR-8 a: the call an integration guide ends with returns the id it queued, and the shape is fixed. */
    public function testTheTestEventIsQueuedAndNamesItself(): void
    {
        $client = $this->client();
        $id = $client->captureTestEvent();

        self::assertNotNull($id);
        $payload = $client->queue()->take(1)[0]->payload;
        self::assertSame($id, $payload['event_id']);
        self::assertSame(EventFactory::TEST_TYPE, $payload['exception']['type']);
        self::assertSame(EventFactory::TEST_MESSAGE, $payload['exception']['message']);
    }

    /**
     * The fingerprint the cap is keyed on has to be the one the ingest will compute for the very same
     * payload — otherwise the cap suppresses a group the cockpit never shows as one.
     */
    public function testTheCapIsKeyedOnTheFingerprintTheIngestWillCompute(): void
    {
        $client = $this->client();
        $client->captureException(new \RuntimeException('boom'));
        $payload = $client->queue()->take(1)[0]->payload;

        self::assertSame(
            Fingerprint::of(
                $payload['service'],
                $payload['exception']['type'],
                $payload['title'],
                $payload['exception']['stacktrace'] ?? []
            ),
            Fingerprint::ofEvent($payload)
        );
    }

    /** An explicitly disabled SDK is not a slower SDK: nothing is built, nothing is queued. */
    public function testADisabledClientQueuesNothingAndStillAnswers(): void
    {
        $client = $this->client(['enabled' => false]);

        self::assertNull($client->captureException(new \RuntimeException('boom')));
        self::assertNull($client->captureMessage('anything'));
        self::assertNull($client->captureTestEvent());
        $client->flush();
        self::assertSame(0, $client->queue()->count());
    }

    /** After a 401 the sender is stopped for the process lifetime, and capturing into it is waste. */
    public function testNothingIsCapturedOnceTheIngestHasRefusedTheToken(): void
    {
        $client = $this->client([], new RecordingTransport(new Response(401, '')));
        $client->captureException(new \RuntimeException('boom'));
        $client->flush();

        self::assertTrue($client->sender()->isStopped());
        self::assertNull($client->captureException(new \RuntimeException('another one')));
        self::assertSame(0, $client->queue()->count());
    }

    /** The queue really is drained by a flush, through the real Sender and the real Transport contract. */
    public function testAFlushHandsTheQueueToTheTransportAndClearsIt(): void
    {
        $transport = new RecordingTransport();
        $client = $this->client([], $transport);
        $client->captureException(new \RuntimeException('boom'));

        $client->flush();

        self::assertCount(1, $transport->calls);
        self::assertSame(0, $client->queue()->count());
    }

    /** The facade is a no-op until init(), so a library may call it in an application that never wired us. */
    public function testTheFacadeIsSilentUntilItHasAClientAndThenDelegates(): void
    {
        self::assertNull(AnvilFeed::client());
        self::assertNull(AnvilFeed::captureException(new \RuntimeException('boom')));
        self::assertNull(AnvilFeed::captureMessage('nobody is listening'));
        self::assertNull(AnvilFeed::captureTestEvent());
        AnvilFeed::flush();

        $client = $this->client();
        AnvilFeed::init($client);

        self::assertSame($client, AnvilFeed::client());
        self::assertNotNull(AnvilFeed::captureException(new \RuntimeException('boom')));
        self::assertSame(1, $client->queue()->count());
    }
}
