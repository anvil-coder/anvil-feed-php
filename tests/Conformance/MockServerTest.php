<?php

declare(strict_types=1);

namespace AnvilFeed\Tests\Conformance;

use AnvilFeed\AnvilFeed;
use AnvilFeed\Client;
use AnvilFeed\Config;
use AnvilFeed\GitContext;
use AnvilFeed\Logger;
use AnvilFeed\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every status path of the published ingest contract, answered by a real HTTP server, driving the WHOLE
 * client: `AnvilFeed::init` → `captureException` → `flush`, with the transport the client builds for
 * itself.
 *
 * Why a socket and not a double. {@see ResponseDecisionTest} already drives the Sender's decision table
 * from `conformance/responses/index.json` with a scripted `Transport` — that is the right shape for a
 * decision, and it is not repeated here. What it cannot reach is everything BELOW the `Response` object:
 * the status line, the `Retry-After` header, a 30x with a `Location`, a server that never answers, the
 * `Authorization` header actually leaving the process, and the object-vs-array body rule the ingest
 * refuses the other half of with a 400. Those exist on a socket or nowhere, and `DirectTransport` — the
 * transport every host without a PSR-18 client runs — has no seam a double could take.
 *
 * Two things the stub does that make this more than a reaction test. It enforces the ingest's own
 * REQUEST rules first, whatever the scenario says (`Origin` ⇒ 403, no bearer ⇒ 401, oversize ⇒ 413,
 * non-JSON or a batch over 50 ⇒ 400), so every test here is also a standing check that the SDK never
 * *earns* one of those answers. And every entry of `conformance/responses/index.json` is a scenario, so
 * the walk below cannot fall behind the published contract.
 *
 * The clock is injected and frozen (`Client::create(..., clock:)`), which is what lets a pause be
 * asserted as an exact equality rather than as a window — and lets the whole file run without a sleep.
 *
 * A run in which the server cannot be started is a FAILED run, never a skipped one.
 */
final class MockServerTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $base = '';

    private const TOKEN = 'anv_srv_0123456789abcdef0123456789abcdef';
    private const RESPONSES = __DIR__ . '/../../../../conformance/responses';

    /** Distinct classes, so the per-fingerprint storm cap does not quietly swallow the later captures. */
    private const FAULTS = [
        \RuntimeException::class, \LogicException::class, \DomainException::class,
        \OverflowException::class, \RangeException::class, \UnderflowException::class,
    ];

    /** The injected clock. Frozen, so `pausedUntil()` is an exact number and nothing has to sleep. */
    private float $now = 1_000_000.0;

    private string $run = '';
    private ?Client $client = null;
    private RecordingLogger $written;

    public static function setUpBeforeClass(): void
    {
        // Up to three attempts: freePort() closes its probe socket before `php -S` binds, so another
        // process can take the number in between — and a readiness probe that then succeeded against a
        // FOREIGN server would fail these tests in a way that named nothing.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (self::startServer()) {
                return;
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
    }

    protected function setUp(): void
    {
        $this->run = bin2hex(random_bytes(6));
        $this->written = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        // Every capture registers a shutdown flush. Draining here means the end of the PHPUnit process
        // does not try to talk to a server that has already been terminated.
        $this->client?->queue()->clear();
        $this->client = null;
        AnvilFeed::reset();
        @unlink($this->requestLog());
        @unlink($this->leakMarker());
    }

    // ---------------------------------------- the whole published response index, over the socket

    /**
     * Every entry of `conformance/responses/index.json`, walked end to end. Adding a response fixture
     * adds a socket-level case here and a parser-level case in {@see ResponseDecisionTest} at once; the
     * commit that adds one cannot leave either runner behind.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function publishedResponses(): array
    {
        $index = json_decode((string) file_get_contents(self::RESPONSES . '/index.json'), true);
        self::assertIsArray($index);
        self::assertGreaterThanOrEqual(9, count($index), 'conformance/responses/index.json lost entries');

        $cases = [];
        foreach ($index as $entry) {
            $cases[(string) $entry['file']] = [$entry];
        }

        return $cases;
    }

    /** @param array<string, mixed> $entry */
    #[DataProvider('publishedResponses')]
    public function testTheClientMakesTheDecisionTheIndexNamesOverARealSocket(array $entry): void
    {
        $published = json_decode((string) file_get_contents(self::RESPONSES . '/' . $entry['file']), true);
        // A 202 names a verdict per event, so the batch is sized to the fixture's verdicts; anything
        // else refuses or defers the whole request and two events are enough to show it.
        $events = (int) $entry['status'] === 202
            ? max(1, count($published['accepted'] ?? []) + count($published['rejected'] ?? []))
            : 2;

        $requests = $this->drive(basename((string) $entry['file'], '.json'), $events);

        self::assertNotSame([], $requests, (string) $entry['file'] . ': the request never reached the stub');
        self::assertFalse($this->client?->sender()->isStopped(),
            (string) $entry['file'] . ': none of these is a credential failure; only a 401 stops the process');

        $retryAfter = $entry['retry_after'] === null ? null : (int) $entry['retry_after'];
        switch ($entry['retry']) {
            case 'none':
                self::assertSame(0, $this->queueCount(), (string) $entry['file']
                    . ': the verdict is final — nothing of this batch may be sent again');
                self::assertLessThanOrEqual($this->now, (float) $this->client?->sender()->pausedUntil(),
                    (string) $entry['file'] . ': a final verdict is not a reason to stop sending anything else');
                break;

            case 'after-delay':
            case 'whole-batch':
                self::assertSame($events, $this->queueCount(), (string) $entry['file']
                    . ': a retryable answer must never cost an event');
                self::assertNotNull($retryAfter, (string) $entry['file'] . ': the index must name a delay');
                // The exact equality is the point of the injected clock: the pause is the header's
                // value, not a backoff of the sender's own that happens to land nearby.
                self::assertSame($this->now + (float) $retryAfter,
                    (float) $this->client?->sender()->pausedUntil(),
                    (string) $entry['file'] . ': the pause is exactly Retry-After');
                break;

            default:
                self::fail('unknown retry verdict ' . var_export($entry['retry'], true));
        }

        foreach ($this->reasonCodes($published) as $code) {
            self::assertNotSame([], $this->written->matching($code), (string) $entry['file']
                . ": the reason code {$code} is the whole message; an SDK that swallows it leaves the "
                . 'customer with an empty inbox and no explanation');
        }
    }

    /**
     * The finding this round exists for. `Sender::apply` reads
     * `$response->retryAfterSeconds ?? self::DEFAULT_RATE_LIMIT_PAUSE_SECONDS`, and until the index
     * carried a window other than 60 nothing in this repository could tell the two apart: every fixture
     * used the SDK's own fallback constant, so a regression that dropped the header was invisible.
     *
     * `429-rate-limited-long-window.json` asks for 137 s and `503-transient-long-window.json` for 900 s
     * — read out of the index rather than retyped here, so a change to the published contract cannot
     * leave this test green and wrong.
     *
     * @return array<string, array{string, int}>
     */
    public static function nonDefaultWindows(): array
    {
        $windows = [];
        foreach (json_decode((string) file_get_contents(self::RESPONSES . '/index.json'), true) as $entry) {
            // 60 and 30 are Sender::DEFAULT_RATE_LIMIT_PAUSE_SECONDS and DEFAULT_TRANSIENT_PAUSE_SECONDS.
            if ($entry['retry_after'] !== null && !in_array((int) $entry['retry_after'], [60, 30], true)) {
                $windows[(string) $entry['file']] = [
                    basename((string) $entry['file'], '.json'),
                    (int) $entry['retry_after'],
                ];
            }
        }
        self::assertGreaterThanOrEqual(2, count($windows),
            'the index must carry at least one 429 and one 503 whose Retry-After is NOT the SDK fallback, '
            . 'or nothing here can tell a parsed header from a hard-coded constant');

        return $windows;
    }

    #[DataProvider('nonDefaultWindows')]
    public function testThePauseIsTheHeadersValueAndNotTheSdksFallback(string $scenario, int $seconds): void
    {
        $this->drive($scenario, 2);
        $waited = (float) $this->client?->sender()->pausedUntil() - $this->now;

        self::assertSame((float) $seconds, $waited);
        self::assertNotSame(60.0, $waited, 'that is the 429 fallback constant, not the header');
        self::assertNotSame(30.0, $waited, 'that is the 503 fallback constant, not the header');
        self::assertSame(2, $this->queueCount());
        self::assertNotSame([], $this->written->matching('paused for ' . $seconds . 's'));
    }

    /**
     * …and the other branch of the `??`. `docs/anvil-feed-ingest.md` sends `Retry-After` with every 429
     * and every 503, but a proxy can strip a header, and the documented fallback has to be exactly the
     * documented number rather than zero — which would be a hot loop against a server asking for room.
     *
     * @return array<string, array{string, float}>
     */
    public static function missingHeaderFallbacks(): array
    {
        return [
            '429 without Retry-After' => ['no-retry-after-429', 60.0],
            '503 without Retry-After' => ['no-retry-after-503', 30.0],
        ];
    }

    #[DataProvider('missingHeaderFallbacks')]
    public function testAMissingRetryAfterFallsBackToTheDocumentedDefault(string $scenario, float $seconds): void
    {
        $this->drive($scenario, 2);

        self::assertSame($this->now + $seconds, (float) $this->client?->sender()->pausedUntil());
        self::assertSame(2, $this->queueCount());
    }

    // ------------------------------------------------------------------- 202

    /**
     * The healthy path, and the contract rule that only exists on the wire: ONE event goes to `/events`
     * as a JSON object. The credential, the content type, the absent `Origin` and the body size are
     * asserted for every request of every test by {@see assertNothingTheIngestWouldRefuseWasSent}.
     */
    public function testASingleEventIsPostedAsAnObjectToEventsAndClearsTheQueue(): void
    {
        $requests = $this->drive('accepted', 1);

        self::assertCount(1, $requests);
        self::assertStringEndsWith('/api/feed/events', $requests[0]['path']);
        self::assertSame('object', $requests[0]['shape'], 'a single event is an OBJECT; the ingest '
            . 'answers 400 to an array on this route on purpose');
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame(0, $this->queueCount());
        self::assertSame([], $this->written->matching('anvil-feed: dropped'));
    }

    /** …and two or more go to `/events/batch` as a JSON array. The ingest refuses the other shape. */
    public function testABatchIsPostedAsAnArrayToEventsBatch(): void
    {
        $requests = $this->drive('accepted', 3);

        self::assertCount(1, $requests);
        self::assertStringEndsWith('/api/feed/events/batch', $requests[0]['path']);
        self::assertSame('array', $requests[0]['shape']);
        self::assertCount(3, $requests[0]['ids']);
        self::assertSame(0, $this->queueCount());
    }

    // ----------------------------------------------------------- 4xx and 5xx

    /**
     * A 401 is the end of sending for this process. The queue is drained — a stopped sender that keeps
     * queueing is a memory leak with a nice name — and a capture made afterwards must return null
     * rather than pile up behind a door that will not open again.
     */
    public function testA401StopsTheProcessDrainsTheQueueAndRefusesLaterCaptures(): void
    {
        $this->drive('unauthorized', 2);

        self::assertTrue($this->client?->sender()->isStopped());
        self::assertSame(0, $this->queueCount());
        self::assertNull(
            AnvilFeed::captureException(new \RuntimeException('after the credential was refused')),
            'a stopped client must stop accepting events, not merely stop sending them'
        );
        self::assertNotSame([], $this->written->matching('401'));
    }

    public function testA403DropsAndTellsTheOperatorToRotateTheToken(): void
    {
        $requests = $this->drive('forbidden', 1);

        self::assertCount(1, $requests, 'retrying a 403 cannot change it');
        self::assertSame(0, $this->queueCount());
        self::assertFalse($this->client?->sender()->isStopped(), 'a 403 is not a 401');
        self::assertNotSame([], $this->written->matching('Rotate the token'));
    }

    /**
     * 413: halve the batch and try the halves, down to the single event, which is dropped because there
     * is nothing left to split. The request COUNT is the proof — a sender that dropped the batch whole
     * would make exactly one request and pass every other assertion here — and it is asserted EXACTLY.
     * Four events against a server that always answers 413: 4, 2, 1 (the 1 is dropped), then the two
     * halves that were pushed back, 3, 1 and 2, 1 and 1. Eight requests, four dropped events, an empty
     * queue. "More than one" would also pass for a sender that halved once and dropped the rest.
     */
    public function testA413HalvesTheBatchRatherThanDroppingItWhole(): void
    {
        $requests = $this->drive('413-payload-too-large', 4);

        self::assertCount(8, $requests, 'the batch is halved down to single events, not dropped whole');
        self::assertSame(1, $requests[count($requests) - 1]['events'],
            'the split ends at a single event, which is the only thing that may be dropped for size');
        self::assertSame(0, $this->queueCount());
        self::assertNotSame([], $this->written->matching('payload_too_large'));
        self::assertNotSame([], $this->written->matching('Halving the batch'));
    }

    /** A rate limit never costs an event, and a flush during the pause must not open a connection. */
    public function testAPausedSenderDoesNotReachTheSocketAgain(): void
    {
        $requests = $this->drive('429-rate-limited', 3);

        self::assertCount(1, $requests);
        self::assertSame(3, $this->queueCount());

        AnvilFeed::flush();
        self::assertCount(1, $this->requests(), 'a paused sender does not open a connection');
        self::assertSame(3, $this->queueCount());
    }

    /**
     * A 5xx that is not 503. It is not in `responses/index.json` — the contract documents 503 as the
     * transient answer — but a load balancer answers 500 and 502 all the same, and the rule is
     * exponential backoff with jitter rather than a fixed pause, with the events kept.
     */
    public function testAnUnexpected5xxBacksOffWithJitterAndKeepsTheEvents(): void
    {
        $this->drive('unexpected-500', 2);

        self::assertSame(2, $this->queueCount());
        self::assertFalse($this->client?->sender()->isStopped());
        $waited = (float) $this->client?->sender()->pausedUntil() - $this->now;
        // BACKOFF_BASE_MS 1000 with ±20% jitter on the first attempt — a range, not a fixed pause.
        self::assertGreaterThanOrEqual(0.8, $waited);
        self::assertLessThanOrEqual(1.2, $waited);
        self::assertNotSame([], $this->written->matching('the ingest answered 500'));
    }

    /**
     * A server that accepted the request and then never answered. The client's own budget has to be
     * what ends the call — FR-2 c, and the reason `request_timeout_ms` exists at all — and the event
     * has to survive: a timeout says nothing about whether the ingest would accept it.
     *
     * The elapsed time is asserted, not just the outcome. The stub sleeps three seconds; a client that
     * honours a 300 ms budget comes back in well under one, and a client that does not passes every
     * other assertion in this method.
     */
    public function testATimeoutIsATransientFailureBoundedByTheConfiguredBudget(): void
    {
        $started = microtime(true);
        $this->drive('timeout', 1, ['request_timeout_ms' => 300]);
        $elapsed = microtime(true) - $started;

        self::assertLessThan(2.0, $elapsed,
            'a 300 ms budget must not become the server\'s three seconds');
        self::assertSame(1, $this->queueCount(), 'a timeout keeps the event');
        self::assertFalse($this->client?->sender()->isStopped());
        self::assertNotSame([], $this->written->matching('transport failure'));
    }

    /**
     * One typo in `endpoint` and a 30x is what the SDK meets. It is never followed: the redirect target
     * would receive the `anv_srv_` token, and the redirect degrades POST to GET, so its 202 would be
     * read as a delivery for events that were never in the second request.
     *
     * The assertion is the absence of the file the redirect target writes the credential into — not the
     * absence of a second call in a mock, which is the same claim asserted against the wrong thing.
     */
    public function testARedirectIsNeverFollowedAndTheTokenNeverReachesTheTarget(): void
    {
        $this->drive('redirect', 1);

        self::assertFileDoesNotExist($this->leakMarker(),
            'the redirect target was reached — and the Authorization header went with it');
        foreach ($this->requests() as $request) {
            self::assertNotSame('leaked', $request['scenario']);
        }
        self::assertSame(0, $this->queueCount(), 'the events are dropped, not silently counted as sent');
        self::assertNotSame([], $this->written->matching('never follows a redirect'));
        self::assertNotSame([], $this->written->matching('Location'));
    }

    // ------------------------------------------ the request side of the contract

    /**
     * The stub's request rules have to be LIVE, or the standing check in every test above is a comment.
     * Each of these is a request the real ingest refuses, made deliberately by this test — and the
     * answer is the status the contract names. The SDK never makes one, which is what everything above
     * proves.
     *
     * @return array<string, array{array<string, string>, string, int}>
     */
    public static function requestsTheIngestRefuses(): array
    {
        $event = '{"event_id":"9b1c7f2e-5a34-4c18-8e0d-1f7a2b3c4d5e"}';

        return [
            'an Origin header with a server token' => [
                ['Origin' => 'https://evil.example', 'Authorization' => 'Bearer ' . self::TOKEN],
                $event,
                403,
            ],
            'no Authorization at all' => [[], $event, 401],
            // The server's order (dark-factory issue #338): the credential is checked before Origin is read,
            // so an Origin on a refused token is a 401, not the contract's "403 whatever the token says".
            'an Origin header with a malformed token' => [
                ['Origin' => 'https://evil.example', 'Authorization' => 'Bearer hunter2'],
                $event,
                401,
            ],
            'a token that is not an anvil key' => [['Authorization' => 'Bearer hunter2'], $event, 401],
            'a body that is not JSON' => [
                ['Authorization' => 'Bearer ' . self::TOKEN],
                'not json at all',
                400,
            ],
        ];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('requestsTheIngestRefuses')]
    public function testTheStubEnforcesTheIngestsOwnRequestRules(array $headers, string $body, int $expected): void
    {
        self::assertNotSame('', self::$base, 'the local ingest stub could not be started');

        self::assertSame($expected, $this->rawPost('accepted', $headers, $body));
    }

    /** A batch above the ingest's limit of 50, and a body above its 262144 bytes. */
    public function testTheStubRefusesAnOversizedBatchAndAnOversizedBody(): void
    {
        self::assertNotSame('', self::$base, 'the local ingest stub could not be started');
        $headers = ['Authorization' => 'Bearer ' . self::TOKEN];
        $event = '{"event_id":"9b1c7f2e-5a34-4c18-8e0d-1f7a2b3c4d5e"}';

        self::assertSame(400, $this->rawPost('accepted', $headers,
            '[' . implode(',', array_fill(0, 51, $event)) . ']'));
        self::assertSame(413, $this->rawPost('accepted', $headers,
            '{"event_id":"9b1c7f2e-5a34-4c18-8e0d-1f7a2b3c4d5e","title":"' . str_repeat('x', 300000) . '"}'));
    }

    /**
     * And the SDK's own side of the same rules, asserted where it can be: a token the ingest would
     * refuse earns a 401 from the stub's RULE rather than from a scenario, and the client stops. This
     * is what makes the standing checks in every other test worth writing.
     */
    public function testAnUnusableTokenEarnsTheRefusalRatherThanBeingAnsweredByAScenario(): void
    {
        $this->drive('accepted', 1, ['token' => 'anv_srv_not_a_real_key'], expectDelivery: false);

        self::assertTrue($this->client?->sender()->isStopped(),
            'the stub refused the credential on its own rule — and the client stopped, as a 401 requires');
        self::assertSame(0, $this->queueCount());
    }

    // ------------------------------------------------------------- machinery

    /**
     * `AnvilFeed::init` → `captureException` × n → `flush`. The transport is the one `Client::create`
     * builds for itself, so what is exercised is the chain a host application runs and not a wiring
     * this test invented. The clock is injected and frozen.
     *
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>> the requests the server actually received
     */
    private function drive(string $scenario, int $events, array $options = [], bool $expectDelivery = true): array
    {
        self::assertNotSame('', self::$base, 'the local ingest stub could not be started — this file is '
            . 'the only place the PHP SDK is held against a real HTTP conversation, so a run without it '
            . 'is a failed run, not a skipped one');
        self::assertLessThanOrEqual(count(self::FAULTS), $events);

        $logger = new Logger($this->written);
        $config = Config::fromArray(array_merge([
            'endpoint' => self::$base . '/' . $this->run . '/' . $scenario . '/api/feed',
            'token' => self::TOKEN,
            'service' => 'disponio-api',
            'environment' => 'production',
        ], $options), $logger);

        $this->client = Client::create($config, null, $logger, GitContext::none(), fn (): float => $this->now);
        AnvilFeed::init($this->client);

        for ($i = 0; $i < $events; $i++) {
            $class = self::FAULTS[$i];
            self::assertNotNull(
                AnvilFeed::captureException(new $class('fault ' . $i)),
                'the capture was dropped before it could be sent'
            );
        }
        self::assertSame($events, $this->queueCount());

        AnvilFeed::flush();
        $requests = $this->requests();

        if ($expectDelivery) {
            $this->assertNothingTheIngestWouldRefuseWasSent($requests);
        }

        return $requests;
    }

    /**
     * The standing check. The stub applies these refusals before it looks at the scenario, so a run
     * that came back with the status it asked for is already evidence — but the recorded request says
     * it directly, for every request of every scenario, in the terms the contract uses.
     *
     * @param list<array<string, mixed>> $requests
     */
    private function assertNothingTheIngestWouldRefuseWasSent(array $requests): void
    {
        foreach ($requests as $request) {
            self::assertSame('', $request['origin'], 'this SDK never sends an Origin; a server token '
                . 'presented with one is treated as leaked and answered 403 before it is even read');
            self::assertSame('Bearer ' . self::TOKEN, $request['authorization']);
            self::assertSame('application/json', $request['content_type']);
            self::assertStringStartsWith('anvil-feed-php/', $request['user_agent']);
            self::assertNotSame('unreadable', $request['shape'], 'the body must be JSON the ingest can read');
            self::assertLessThanOrEqual(50, $request['events'], 'the ingest answers 400 above 50');
            self::assertLessThanOrEqual(262144, $request['bytes'], 'the ingest answers 413 above 262144');
        }
    }

    /**
     * A request this test makes itself, to prove the stub's own rules bite. Nothing of the SDK is
     * involved; that is the point.
     *
     * @param array<string, string> $headers
     */
    private function rawPost(string $scenario, array $headers, string $body): int
    {
        $lines = ['Content-Type: application/json'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $lines),
            'content' => $body,
            'timeout' => 5,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        @file_get_contents(
            self::$base . '/' . $this->run . '/' . $scenario . '/api/feed/events',
            false,
            $context
        );

        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                return (int) $match[1];
            }
        }

        return 0;
    }

    private function queueCount(): int
    {
        return $this->client?->queue()->count() ?? -1;
    }

    /** @return list<array<string, mixed>> */
    private function requests(): array
    {
        $log = $this->requestLog();
        if (!is_file($log)) {
            return [];
        }
        $requests = [];
        foreach (explode("\n", trim((string) file_get_contents($log))) as $line) {
            if ($line !== '') {
                $requests[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $requests;
    }

    /**
     * @param array<string, mixed>|null $published
     * @return list<string>
     */
    private function reasonCodes(?array $published): array
    {
        $codes = [];
        foreach ($published['rejected'] ?? [] as $rejection) {
            if (is_array($rejection) && isset($rejection['reason_code'])) {
                $codes[] = (string) $rejection['reason_code'];
            }
        }

        return $codes;
    }

    private function requestLog(): string
    {
        return sys_get_temp_dir() . '/anvil-feed-stub-' . $this->run . '.jsonl';
    }

    private function leakMarker(): string
    {
        return sys_get_temp_dir() . '/anvil-feed-leaked-' . $this->run;
    }

    private static function startServer(): bool
    {
        $port = self::freePort();
        if ($port === 0) {
            return false;
        }
        $pipes = [];
        $process = proc_open(
            // `exec` so that proc_terminate() reaches `php -S` itself rather than the shell that
            // started it — without it the orphan survives only by /bin/sh's last-command optimisation.
            sprintf(
                'exec %s -S 127.0.0.1:%d %s',
                escapeshellarg(PHP_BINARY),
                $port,
                escapeshellarg(__DIR__ . '/stub-router.php')
            ),
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            // The timeout scenario holds a worker for three seconds on purpose; a single-threaded
            // server would turn that into a timeout for every test that ran after it.
            ['PHP_CLI_SERVER_WORKERS' => '8'] + getenv()
        );
        if (!is_resource($process)) {
            return false;
        }
        self::$server = $process;
        $base = 'http://127.0.0.1:' . $port;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            // Not merely "something answers on the port": the port was free a moment ago and anything
            // could have taken it in between. The probe asks OUR stub for a scenario only it knows.
            $answer = @file_get_contents(
                $base . '/probe/an-unknown-scenario/api/feed/events',
                false,
                stream_context_create(['http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . self::TOKEN,
                    'content' => '{"event_id":"9b1c7f2e-5a34-4c18-8e0d-1f7a2b3c4d5e"}',
                    'timeout' => 0.5,
                    'ignore_errors' => true,
                ]])
            );
            if (is_string($answer) && str_contains($answer, 'unknown scenario')) {
                self::$base = $base;
                @unlink(sys_get_temp_dir() . '/anvil-feed-stub-probe.jsonl');

                return true;
            }
            usleep(50_000);
        }
        self::stopServer();

        return false;
    }

    private static function stopServer(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::$server = null;
        self::$base = '';
    }

    /** 0 when no port could be found — a fixed fallback is how two suites collide on a busy box. */
    private static function freePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!is_resource($socket)) {
            return 0;
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
