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
     * One line per WINDOW — not one per storm, and not one per dropped event. Two windows of the same
     * fault cost exactly two lines.
     *
     * The client used to decide this by asking the gate a SECOND question after the gate had answered the
     * first: `suppressed($fingerprint) !== 1`. The gate now hands back the edge together with the verdict
     * and consumes it, so nothing can step over the edge between the two reads (Java's task-8 review
     * finding 3). The count here is measured, not argued: 1000 captures, 20 queued, 2 lines.
     */
    public function testAStormSpanningTwoWindowsCostsExactlyTwoLogLines(): void
    {
        $now = 1000.0;
        $sink = new RecordingLogger();
        $logger = new Logger($sink);
        $config = Config::fromArray([
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
            'fingerprint_cap' => 10,
            'fingerprint_window_ms' => 60000,
        ], $logger);
        $client = Client::create($config, new RecordingTransport(), $logger, GitContext::none(),
            function () use (&$now) { return $now; });

        for ($i = 0; $i < 500; $i++) {
            $client->captureMessage('the same fault every time', 'error');
        }
        self::assertCount(1, $sink->matching('suppressing locally'), 'the first window said it once');

        $now += 61.0;   // the window moves on
        for ($i = 0; $i < 500; $i++) {
            $client->captureMessage('the same fault every time', 'error');
        }

        self::assertCount(2, $sink->matching('suppressing locally'),
            'and the second window said it once more — 1000 captures, 980 dropped, two lines');
        self::assertSame(20, $client->queue()->count(), 'ten per window got through, and only ten');
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

    // ------------------------------------------------ the caller's stack (review finding 1)

    /**
     * A message event's stack must be the CALLER's. When it was the SDK's own, the three constant frames
     * `Client.php#{main}`, `Client.php#{closure}`, `Client.php#capture` led every stack — and wherever
     * this package is not installed under a `vendor/` directory they are `in_app`, win the promotion, and
     * every captureMessage() in the application collapses into ONE fingerprint. The local cap then mutes
     * the whole message stream of the process after ten events.
     */
    public function testAMessageEventCarriesTheCallersFramesAndNoneOfOurs(): void
    {
        $client = $this->client();
        $client->captureMessage('Payment reconciliation skipped for 3 accounts', 'warning');
        $frames = $client->queue()->take(1)[0]->payload['exception']['stacktrace'];

        self::assertNotSame([], $frames);
        // The path as it goes on the wire: RELATIVE to the project root, never absolute. Composing the
        // expectation from the rule rather than pinning a string keeps this true on every checkout.
        self::assertSame(
            EventFactory::relativeTo(EventFactory::normalizeRoot(EventFactory::discoverProjectRoot()), __FILE__),
            $frames[0]['file'],
            'the stack starts where the caller called'
        );
        self::assertStringStartsNotWith('/', $frames[0]['file'], 'an absolute path never leaves the process');
        $ours = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
        foreach ($frames as $frame) {
            self::assertStringNotContainsString($ours, $frame['file'],
                'no frame of this SDK may appear in a customer-facing stack: ' . $frame['file']);
        }
    }

    /**
     * The second, independent half of the same defence. Dropping the frames keeps them out of the stack;
     * this keeps them out of the in-app PROMOTION even if one ever survives — which is what decides the
     * fingerprint. `vendor/` is not a reliable answer here: in a Composer `path` repository, a monorepo
     * checkout or this repository itself, the SDK is not under a `vendor/` directory at all.
     */
    public function testThisSdkIsNeverItsOwnInAppCodeWhereverItIsInstalled(): void
    {
        self::assertFalse(EventFactory::isInApp(dirname(__DIR__) . '/src/Client.php'));
        self::assertFalse(EventFactory::isInApp(dirname(__DIR__) . '/src/Transport/DirectTransport.php'));
        self::assertTrue(EventFactory::isInApp('/srv/app/app/Services/OrderService.php'),
            'and the customer\'s own code is still in-app');
    }

    public function testTheTestEventCarriesTheCallersFramesToo(): void
    {
        $client = $this->client();
        $client->captureTestEvent();
        $frames = $client->queue()->take(1)[0]->payload['exception']['stacktrace'];

        self::assertSame(
            EventFactory::relativeTo(EventFactory::normalizeRoot(EventFactory::discoverProjectRoot()), __FILE__),
            $frames[0]['file']
        );
        self::assertStringStartsNotWith('/', $frames[0]['file'], 'an absolute path never leaves the process');
    }

    /**
     * The failure the collapse produces, stated as the rule that prevents it: two call sites, two
     * identities. `testAMessageFromAnotherSiteIsAnotherIdentity` below is the second site — the two
     * fingerprints are compared through a shared static because they must come from two different
     * methods to be a real proof (the same method twice would pass even with the SDK's own frames).
     */
    private static ?string $fingerprintFromTheOtherSite = null;

    public function testAMessageFromOneSiteHasItsOwnIdentity(): void
    {
        $this->recordTheFingerprintOfAMessageHere();
    }

    public function testAMessageFromAnotherSiteIsAnotherIdentity(): void
    {
        $first = self::$fingerprintFromTheOtherSite ?? $this->fingerprintOfAMessage();
        $second = $this->fingerprintOfAMessage();

        self::assertNotSame($first, $second,
            'two call sites must be two incidents — otherwise the cap mutes the whole message stream');
    }

    private function recordTheFingerprintOfAMessageHere(): void
    {
        self::$fingerprintFromTheOtherSite = $this->fingerprintOfAMessage();
        self::assertNotNull(self::$fingerprintFromTheOtherSite);
    }

    private function fingerprintOfAMessage(): string
    {
        $client = $this->client();
        $client->captureMessage('the very same text from both sites', 'error');

        return Fingerprint::ofEvent($client->queue()->take(1)[0]->payload);
    }

    // --------------------------------- the beforeSend view (review findings 3 and 4)

    /**
     * FR-5: the SDK redacts before sending. The most obvious use of the hook is enrichment, and a value
     * a hook adds has never met the denylist — so the hook's output goes through the SAME
     * scrub → bound → prune pipeline as everything else.
     */
    public function testWhatABeforeSendHookAddsIsScrubbedLikeEverythingElse(): void
    {
        // context_lines => 0 for the reason Task 1's scrubber test gives: with context code on, the frame
        // quotes THIS file, and the literal below comes back as source rather than as a scrubbed field.
        $client = $this->client(['context_lines' => 0]);
        $client->beforeSend(static function (array $payload): array {
            $payload['tags'] = ['password' => 'hunter2', 'tenant' => 'acme'];
            $payload['breadcrumbs'] = ['api_key=sk_live_4242424242'];

            return $payload;
        });
        $client->captureException(new \RuntimeException('boom'));
        $payload = $client->queue()->take(1)[0]->payload;

        self::assertSame('[Filtered]', $payload['tags']['password']);
        self::assertSame('acme', $payload['tags']['tenant'], 'and nothing else was swept away');
        self::assertStringNotContainsString('sk_live_4242424242', json_encode($payload));
    }

    /**
     * The delivery key. `Sender::applyVerdicts` matches the ingest's answer on `Event->id`; a body
     * naming another id is never found in `accepted`, is re-sent once and then dropped with a log line
     * blaming the network for what host code did. The id handed back to the caller is also the one
     * `captureTestEvent()` exists to be searched for.
     */
    public function testAHookCannotRewriteTheFieldsThisSdkOwns(): void
    {
        $sink = new RecordingLogger();
        $client = $this->client([], null, new Logger($sink));
        $client->beforeSend(static function (array $payload): array {
            $payload['event_id'] = '00000000-0000-4000-8000-000000000000';
            $payload['service'] = 'somebody-elses-project';
            $payload['environment'] = 'staging';
            $payload['title'] = 'a different incident entirely';
            $payload['exception']['type'] = 'NotTheRealType';
            $payload['user'] = ['id' => 'u-1'];   // …while an ordinary addition still works

            return $payload;
        });

        $id = $client->captureException(new \RuntimeException('boom'));
        $event = $client->queue()->take(1)[0];

        self::assertSame($id, $event->id);
        self::assertSame($id, $event->payload['event_id'], 'the body names the id the caller was given');
        self::assertSame('disponio-api', $event->payload['service']);
        self::assertSame('production', $event->payload['environment']);
        self::assertSame('RuntimeException: boom', $event->payload['title']);
        self::assertSame('RuntimeException', $event->payload['exception']['type']);
        self::assertSame(['id' => 'u-1'], $event->payload['user']);
        self::assertCount(1, $sink->matching('beforeSend changed'));
    }

    /** The fingerprint the cap was charged against must be the fingerprint of what is actually sent. */
    public function testTheQueuedEventStillCarriesTheIdentityTheCapWasChargedAgainst(): void
    {
        $client = $this->client();
        $client->beforeSend(static function (array $payload): array {
            $payload['exception']['stacktrace'] = [];
            $payload['title'] = 'rewritten';

            return $payload;
        });
        $client->captureException(new \RuntimeException('boom'));
        $payload = $client->queue()->take(1)[0]->payload;

        self::assertNotSame([], $payload['exception']['stacktrace']);
        self::assertSame('RuntimeException: boom', $payload['title']);
    }

    /**
     * An event this SDK built correctly and host code then broke must not be posted for the ingest to
     * answer `400 schema_violation` about, with nothing in the log naming the cause.
     */
    public function testAHookThatBreaksTheContractDropsTheEventHereRatherThanAtTheIngest(): void
    {
        $sink = new RecordingLogger();
        $client = $this->client([], null, new Logger($sink));
        // `language`, not `level`: a removed or foreign level is REPAIRED by the second pass (coerced to
        // `error`, see EventFactory::coerceLevel), and `title` is read-only and restored — `language` is a
        // required field the pass neither restores nor repairs, so it still stands for a broken contract.
        $client->beforeSend(static fn (array $payload) => array_diff_key($payload, ['language' => 1]));

        self::assertNull($client->captureException(new \RuntimeException('boom')));
        self::assertSame(0, $client->queue()->count());
        self::assertCount(1, $sink->matching('beforeSend returned an event without language'));
    }

    /** The sibling of the test above: a level the wire does not know is not a broken contract but an error. */
    public function testAHookThatSetsAnUnknownLevelSendsTheEventAsAnError(): void
    {
        $client = $this->client();
        $client->beforeSend(static function (array $payload): array {
            $payload['level'] = 'info';

            return $payload;
        });

        self::assertNotNull($client->captureException(new \RuntimeException('boom')));
        self::assertSame('error', $client->queue()->take(1)[0]->payload['level']);
    }

    // ------------------------------------------------- the injected clock (finding 5)

    /**
     * The window, driven without a sleep. The gate's default clock is monotonic; this proves the seam a
     * host's own suite (and the Laravel adapter's) uses.
     */
    public function testTheGatesClockCanBeInjectedThroughCreate(): void
    {
        $now = 1000.0;
        $client = Client::create(
            Config::fromArray([
                'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
                'service' => 'disponio-api',
                'fingerprint_cap' => 1,
                'fingerprint_window_ms' => 60000,
            ], new Logger()),
            new RecordingTransport(),
            new Logger(),
            GitContext::none(),
            function () use (&$now) { return $now; }
        );

        self::assertNotNull($client->captureException(new \RuntimeException('boom')));
        self::assertNull($client->captureException(new \RuntimeException('boom')));

        $now += 61.0;
        self::assertNotNull($client->captureException(new \RuntimeException('boom')),
            'the window moved on because the injected clock did');
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
