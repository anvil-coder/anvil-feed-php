<?php

declare(strict_types=1);

namespace AnvilFeed\Tests\EndToEnd;

use AnvilFeed\AnvilFeed;
use AnvilFeed\Client;
use AnvilFeed\Config;
use AnvilFeed\GitContext;
use AnvilFeed\Logger;
use AnvilFeed\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PHP SDK against the PUBLISHED contract, over a socket, end to end.
 *
 * Everything before task 12 measured this SDK against something this repository wrote — `MockServerTest`'s
 * `php -S` router and `IngestStub.java` are both readings of the spec by the same hands that wrote the
 * implementation, so neither can catch a misunderstanding they share with it. `conformance/stub/server.mjs`
 * is written from dark-factory's own `docs/anvil-feed-ingest.md`, `AnvilFeedIngestController` and
 * `AnvilFeedEventMapper`, enforces their rules in the controller's order, and journals every request. This
 * file drives the SDK through its PUBLIC entry points only — `AnvilFeed::init` → `captureException` /
 * `captureMessage` / `captureTestEvent` → `flush` — and then reads that journal.
 *
 * What is NOT repeated here. Task 4's `MockServerTest` already walks every entry of
 * `conformance/responses/index.json` at the socket, and `ResponseDecisionTest` walks the same index at the
 * parser. The status paths below exist for the claims those cannot make: that the answer came from a
 * replay of the SERVER's rules, that the bytes on the wire validate against `schema/v1.0`, that a
 * redelivery is the same `event_id` twice at the ingest's own dedup gate, and — through
 * `conformance/stub/compare.mjs` — that the Java SDK put the same document on the same socket.
 *
 * No test here sleeps. The clock is injected and frozen, and a pause is an equality read against the
 * journal's own timestamps.
 *
 * A machine without `node` FAILS this suite rather than skipping it: `conformance/tools/validate.mjs` is
 * already a prerequisite of this repository's own build, `phpunit.xml` carries `failOnSkipped="true"`, and
 * a conformance suite that quietly skips itself on the machine that matters is worse than no suite.
 */
final class AgainstStubTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';
    private const TOKEN = 'anv_srv_0123456789abcdef0123456789abcdef';

    /**
     * The run whose single request `conformance/stub/compare.mjs` compares against the Java SDK's. Exactly
     * one accepted request may carry it, which the comparison asserts for itself.
     */
    private const CROSS_LANGUAGE_RUN = 'c9';

    /** The message BOTH SDKs throw, so that `exception.message` can be compared rather than masked. */
    public const CROSS_LANGUAGE_MESSAGE = 'Cannot read property id of undefined';

    /** The context both SDKs capture with, so that the two wire bodies can be compared field for field. */
    public const CROSS_LANGUAGE_CONTEXT = [
        'request' => ['method' => 'POST', 'url' => 'https://shop.example/api/checkout', 'status_code' => 500],
        'user_id' => 'u-4711',
        'breadcrumbs' => ['cart.add sku=A1', 'checkout.start'],
        'tags' => ['tier' => 'gold'],
    ];

    /** @var resource|null */
    private static $process = null;
    private static int $port = 0;
    private static string $journal = '';

    /** Frozen, so every pause below is an exact equality and nothing sleeps. */
    private float $now = 1_700_000_000.0;

    private ?Client $client = null;
    private RecordingLogger $written;

    public static function setUpBeforeClass(): void
    {
        $journalDirectory = self::ROOT . '/conformance/stub/.journals';
        if (!is_dir($journalDirectory) && !mkdir($journalDirectory, 0o777, true) && !is_dir($journalDirectory)) {
            self::fail('could not create ' . $journalDirectory);
        }
        self::$journal = $journalDirectory . '/php.jsonl';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            'exec node ' . escapeshellarg(self::ROOT . '/conformance/stub/server.mjs'),
            $descriptors,
            $pipes,
            null,
            [
                'STUB_JOURNAL' => self::$journal,
                // `testing` is a binding the operator switched OFF: one of the four answers a first
                // integration meets, and the only one no other scenario can produce.
                'STUB_DISABLED_ENVIRONMENTS' => 'testing',
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin',
            ]
        );
        if (!is_resource($process)) {
            self::fail('the ingest stub could not be started. Node 22+ has to be on PATH; see '
                . 'conformance/stub/README.md.');
        }
        self::$process = $process;

        $listening = fgets($pipes[1]);
        if (!is_string($listening) || preg_match('/listening (\d+)/', $listening, $matches) !== 1) {
            $stderr = stream_get_contents($pipes[2]);
            // tearDownAfterClass does NOT run when setUpBeforeClass fails, so the node process this
            // method started would outlive the suite — holding a port, appending to a journal a later run
            // then reads, and staying alive until somebody notices. Kill it before failing.
            self::stopTheStub();
            self::fail('the ingest stub did not report a port. stdout: ' . var_export($listening, true)
                . ' stderr: ' . var_export($stderr, true));
        }
        self::$port = (int) $matches[1];
        fgets($pipes[1]);   // "journal <path>"; the path was handed in, so nothing is read out of it
    }

    public static function tearDownAfterClass(): void
    {
        self::stopTheStub();
    }

    /** Idempotent, and called from setUpBeforeClass's failure path too — see the comment there. */
    private static function stopTheStub(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
    }

    protected function setUp(): void
    {
        $this->written = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        // Every capture registers a shutdown flush; draining here keeps the end of the phpunit process
        // from posting to a stub that has already been terminated.
        $this->client?->queue()->clear();
        $this->client = null;
        AnvilFeed::reset();
    }

    // ------------------------------------------------------------------ the harness

    private function endpoint(string $run, string $scenario = 'accepted'): string
    {
        return sprintf('http://127.0.0.1:%d/%s/%s/api/feed', self::$port, $run, $scenario);
    }

    /**
     * The client every test drives, installed on the public facade. `Client::create` builds its own
     * `DirectTransport`, which is the transport every host without a PSR-18 client runs.
     *
     * @param array<string, mixed> $options
     */
    private function install(string $run, string $scenario = 'accepted', array $options = []): Client
    {
        $config = Config::fromArray(array_merge([
            'endpoint' => $this->endpoint($run, $scenario),
            'token' => self::TOKEN,
            'service' => 'disponio-api',
            'environment' => 'production',
            'context_lines' => 0,
            'max_frames' => 3,
        ], $options), new Logger($this->written));

        AnvilFeed::reset();
        $this->client = Client::create(
            $config,
            null,
            new Logger($this->written),
            GitContext::none(),
            fn (): float => $this->now
        );
        AnvilFeed::init($this->client);

        return $this->client;
    }

    /** Tells the stub what to answer to the next requests of this run, one scenario per request. */
    private function script(string $run, string ...$answers): void
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['run' => $run, 'answers' => $answers], JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
        ]]);
        $answer = @file_get_contents(sprintf('http://127.0.0.1:%d/__control', self::$port), false, $context);
        self::assertIsString($answer, 'the stub did not accept the scenario script');
        self::assertStringContainsString('"ok":true', $answer);
    }

    /**
     * Every journal line of one run, in arrival order.
     *
     * @return list<array<string, mixed>>
     */
    private function journal(string $run): array
    {
        $lines = file(self::$journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines, 'the stub journal ' . self::$journal . ' is unreadable');

        $entries = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry) && ($entry['run'] ?? null) === $run) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** A raw POST, for the cases where the subject is the CONTRACT rather than the SDK. */
    private function post(string $path, string $body, array $headers = []): array
    {
        $header = "Content-Type: application/json\r\nAuthorization: Bearer " . self::TOKEN . "\r\n";
        foreach ($headers as $name => $value) {
            $header .= $name . ': ' . $value . "\r\n";
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => $header, 'content' => $body, 'ignore_errors' => true,
        ]]);
        $answer = @file_get_contents(sprintf('http://127.0.0.1:%d%s', self::$port, $path), false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return ['status' => $status, 'body' => is_string($answer) ? $answer : ''];
    }

    // ------------------------------------------------------------------ the contract itself

    /**
     * The server's own golden example, unmodified, answered exactly as the published document prints the
     * answer. If one line of this file is the proof that the stub is the contract, it is this one.
     */
    public function testTheSectionThreeExampleIsAcceptedVerbatim(): void
    {
        $example = file_get_contents(self::ROOT . '/conformance/valid/section-3-example.json');
        $answer = $this->post('/raw/accepted/api/feed/events', (string) $example);

        self::assertSame(202, $answer['status']);
        self::assertSame(
            '{"accepted":["f4b1a0c2-0f4a-4a1e-9d7a-2b6c5d8e1f30"],"rejected":[]}',
            $answer['body']
        );
    }

    /**
     * Every fixture `conformance/cases.json` marks refused, answered with the reason code it names — by a
     * stub that got those rules from `AnvilFeedEventMapper`, not from this repository's fixtures.
     *
     * @return array<string, array{string, string}>
     */
    public static function refusedFixtures(): array
    {
        $cases = json_decode((string) file_get_contents(self::ROOT . '/conformance/cases.json'), true);
        self::assertIsArray($cases);

        $rows = [];
        foreach ($cases as $case) {
            if (($case['reason_code'] ?? null) !== null) {
                $rows[(string) $case['file']] = [(string) $case['file'], (string) $case['reason_code']];
            }
        }
        self::assertGreaterThanOrEqual(9, count($rows), 'conformance/cases.json lost refused fixtures');

        return $rows;
    }

    #[DataProvider('refusedFixtures')]
    public function testARefusedFixtureIsAnsweredWithTheReasonCodeTheContractNames(
        string $file,
        string $reasonCode
    ): void {
        $answer = $this->post(
            '/raw/accepted/api/feed/events',
            (string) file_get_contents(self::ROOT . '/conformance/' . $file)
        );

        self::assertSame(202, $answer['status'], $file . ' should be refused per event, not per request');
        $body = json_decode($answer['body'], true);
        self::assertSame([], $body['accepted'], $file . ' was accepted');
        self::assertSame($reasonCode, $body['rejected'][0]['reason_code'], $file);
    }

    /**
     * The one published sentence the running code does not keep. `docs/anvil-feed-ingest.md:55` says an
     * `Origin` is refused with 403 "whatever the token says"; `AnvilFeedIngestController` checks the token
     * first (:196-207) and the `Origin` afterwards (:216-222). Both directions are pinned here so that a
     * later change to either side is a red test rather than a surprise, and the disagreement is reported
     * to dark-factory rather than papered over.
     */
    public function testAnOriginIsRefusedAfterTheTokenIsChecked(): void
    {
        self::assertSame(403, $this->post('/raw/accepted/api/feed/events', '{}',
            ['Origin' => 'https://somebodys-page.example'])['status']);

        $withBadToken = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer nope\r\n"
                . "Origin: https://somebodys-page.example\r\n",
            'content' => '{}',
            'ignore_errors' => true,
        ]]);
        @file_get_contents(
            sprintf('http://127.0.0.1:%d/raw/accepted/api/feed/events', self::$port),
            false,
            $withBadToken
        );
        self::assertStringContainsString('401', $http_response_header[0] ?? '',
            'the controller answers 401 to an Origin carried by a token it never resolved');
    }

    // ------------------------------------------------------------------ the SDK, end to end

    /**
     * Scenario 1. A real thrown exception through the public API: exactly one request, one event, one
     * `202`, and the body the SDK put on the socket is valid against `schema/v1.0/event.schema.json`.
     *
     * This is also the run `conformance/stub/compare.mjs` reads: the context is
     * {@see self::CROSS_LANGUAGE_CONTEXT}, which the Java test captures with too.
     */
    public function testARealExceptionIsAcceptedAndWhatWentOnTheWireIsSchemaValid(): void
    {
        $client = $this->install(self::CROSS_LANGUAGE_RUN);

        $id = AnvilFeed::captureException(
            new \RuntimeException(self::CROSS_LANGUAGE_MESSAGE),
            self::CROSS_LANGUAGE_CONTEXT
        );
        self::assertNotNull($id);
        AnvilFeed::flush();

        $requests = $this->journal(self::CROSS_LANGUAGE_RUN);
        self::assertCount(1, $requests, 'one capture is one request');
        self::assertSame(202, $requests[0]['status']);
        self::assertSame('object', $requests[0]['endpoint_shape'],
            'a single event is posted to /api/feed/events as an object');
        self::assertCount(1, $requests[0]['events']);
        self::assertSame('accepted', $requests[0]['events'][0]['verdict']);
        self::assertSame($id, $requests[0]['events'][0]['event_id']);
        self::assertTrue(
            $requests[0]['events'][0]['schema_valid'],
            'the body is not valid against schema/v1.0: '
                . json_encode($requests[0]['events'][0]['schema_errors'])
        );
        self::assertStringStartsWith('Bearer anv_srv_', $requests[0]['headers']['authorization']);
        self::assertArrayNotHasKey('origin', $requests[0]['headers'], 'this SDK must never set an Origin');

        $body = $requests[0]['body'];
        self::assertSame('php', $body['language']);
        self::assertSame('production', $body['environment']);
        self::assertSame('disponio-api', $body['service']);
        self::assertSame(self::CROSS_LANGUAGE_CONTEXT['request'], $body['request']);
        self::assertSame(['id' => 'u-4711'], $body['user']);
        self::assertSame(['cart.add sku=A1', 'checkout.start'], $body['breadcrumbs']);
        self::assertSame(['tier' => 'gold'], $body['tags']);
        self::assertSame(self::CROSS_LANGUAGE_MESSAGE, $body['exception']['message'],
            'the message is what compare.mjs holds the two SDKs to; the type and the frames it cannot');
        self::assertSame(0, $client->queue()->count(), 'an accepted event is cleared');
        self::assertFalse($client->sender()->isStopped());
    }

    /**
     * Scenario 2, first half. A hundred captures of ONE fault leave the process bounded by
     * `fingerprint_cap` per window — the local cap, measured where it matters: on the socket.
     */
    public function testAStormOfOneFaultIsBoundedByTheFingerprintCapOnTheWire(): void
    {
        $this->install('storm', 'accepted', ['fingerprint_cap' => 10, 'fingerprint_window_ms' => 60000]);

        for ($i = 0; $i < 100; $i++) {
            AnvilFeed::captureException(new \RuntimeException('the same fault, again'));
        }
        AnvilFeed::flush();

        $delivered = 0;
        foreach ($this->journal('storm') as $entry) {
            $delivered += count($entry['events']);
        }
        self::assertSame(10, $delivered, '100 captures of one fingerprint, cap 10, one window');
    }

    /** Scenario 2, second half. Whatever does leave, leaves in batches the ingest accepts: at most 50. */
    public function testEveryRequestIsABatchOfAtMostFifty(): void
    {
        $this->install('batching', 'accepted', ['fingerprint_cap' => 1000]);

        for ($i = 0; $i < 100; $i++) {
            AnvilFeed::captureMessage('distinct fault number ' . $i, 'warning');
        }
        AnvilFeed::flush();

        $entries = $this->journal('batching');
        self::assertCount(2, $entries, '100 events at batch_size 50');
        $total = 0;
        foreach ($entries as $entry) {
            self::assertSame('array', $entry['endpoint_shape'], 'a batch goes to /api/feed/events/batch');
            self::assertLessThanOrEqual(50, count($entry['events']), 'a batch carries at most 50 events');
            self::assertSame(202, $entry['status']);
            $total += count($entry['events']);
        }
        self::assertSame(100, $total);
    }

    /**
     * Scenario 3. A `503` and then a `202`: the SAME `event_id` arrives twice, and the ingest's own dedup
     * gate — `(organisation, event_id)` — is the one that counts it once. At-least-once delivery is the
     * contract; exactly-once is the server's job and the journal's `deliveries` is where that is visible.
     */
    public function testA503AndThenA202DeliverTheSameEventIdTwice(): void
    {
        $this->script('redelivery', 'transient');
        $client = $this->install('redelivery', 'accepted');

        $id = AnvilFeed::captureException(new \RuntimeException('a fault during a store failure'));
        self::assertNotNull($id);
        AnvilFeed::flush();

        $afterTheFailure = $this->journal('redelivery');
        self::assertCount(1, $afterTheFailure);
        self::assertSame(503, $afterTheFailure[0]['status']);
        self::assertSame('30', $afterTheFailure[0]['retry_after']);
        self::assertSame(1, $client->queue()->count(), 'the WHOLE batch is kept after a 503');
        self::assertSame($this->now + 30.0, $client->sender()->pausedUntil());

        AnvilFeed::flush();
        self::assertCount(1, $this->journal('redelivery'), 'nothing goes out during the pause');

        $this->now += 30.0;
        AnvilFeed::flush();

        $afterTheRetry = $this->journal('redelivery');
        self::assertCount(2, $afterTheRetry);
        self::assertSame(202, $afterTheRetry[1]['status']);
        self::assertSame($id, $afterTheRetry[1]['events'][0]['event_id'], 'the same id, resent');
        self::assertSame(2, $afterTheRetry[1]['events'][0]['deliveries'],
            'the ingest saw the id twice and stored it once');
        self::assertSame(0, $client->queue()->count());
    }

    /**
     * Scenario 4. A `429` with `Retry-After`: nothing goes out before the deadline the SERVER named, and
     * the deadline is read off the answer rather than off a constant the SDK could have guessed.
     */
    public function testA429SendsNothingBeforeTheRetryAfterDeadline(): void
    {
        $this->script('ratelimit', 'rate-limited');
        $client = $this->install('ratelimit', 'accepted');

        AnvilFeed::captureException(new \RuntimeException('a fault during a rate limit'));
        AnvilFeed::flush();

        $entries = $this->journal('ratelimit');
        self::assertCount(1, $entries);
        self::assertSame(429, $entries[0]['status']);
        self::assertSame('60', $entries[0]['retry_after']);
        self::assertSame($this->now + 60.0, $client->sender()->pausedUntil());
        self::assertSame(1, $client->queue()->count(), 'no event is dropped by a 429');

        $this->now += 59.0;
        AnvilFeed::flush();
        self::assertCount(1, $this->journal('ratelimit'), 'one second before the deadline: no request');

        $this->now += 1.0;
        AnvilFeed::flush();
        $resumed = $this->journal('ratelimit');
        self::assertCount(2, $resumed, 'at the deadline the send resumes');
        self::assertSame(202, $resumed[1]['status']);
        // The proof is the COUNT after +59 s, not a wall-clock stamp: the stub's `at` is real time and the
        // SDK's clock is injected, so comparing them would assert that this machine's clock runs forwards.
    }

    /**
     * Scenario 5. A `401` stops the process for its lifetime — captures included, not only sends — and
     * says so once.
     */
    public function testA401StopsTheProcessForItsLifetimeAndSaysSoOnce(): void
    {
        $client = $this->install('unauthorized', 'accepted', ['token' => 'anv_srv_too-short']);

        AnvilFeed::captureException(new \RuntimeException('a fault with a bad token'));
        AnvilFeed::flush();

        $entries = $this->journal('unauthorized');
        self::assertCount(1, $entries);
        self::assertSame(401, $entries[0]['status']);
        self::assertSame(
            'Bearer anv_srv_… (17 characters)',
            $entries[0]['headers']['authorization'],
            'the SDK presented the token it was configured with; the 401 was EARNED by that token and not '
                . 'by a request the ingest could not read'
        );
        self::assertTrue($client->sender()->isStopped());
        self::assertSame(0, $client->queue()->count(), 'the queue is drained, not retried');

        self::assertNull(
            AnvilFeed::captureException(new \RuntimeException('after the 401')),
            'a stopped client stops ACCEPTING, not merely stops sending'
        );
        AnvilFeed::flush();
        self::assertCount(1, $this->journal('unauthorized'), 'nothing more is ever sent');

        self::assertCount(1, $this->written->matching('the ingest answered 401'),
            'exactly one line for the lifetime of the process');
    }

    /** Scenario 6. A redirect is never followed, so the target receives nothing at all. */
    public function testARedirectIsNeverFollowedAndTheTargetReceivesNothing(): void
    {
        $client = $this->install('redirected', 'redirect');

        AnvilFeed::captureException(new \RuntimeException('a fault behind a redirect'));
        AnvilFeed::flush();

        $entries = $this->journal('redirected');
        self::assertCount(1, $entries, 'the redirect itself, and nothing else');
        self::assertSame(302, $entries[0]['status']);
        self::assertStringContainsString('/target/api/feed/events', (string) $entries[0]['location']);
        foreach ($entries as $entry) {
            self::assertStringNotContainsString('/target/', (string) $entry['path'],
                'the redirect target received a request; the token would have travelled with it');
        }
        self::assertSame(0, $client->queue()->count());
        self::assertNotEmpty($this->written->matching('never follows a redirect'));
    }

    /**
     * Scenario 7. The PII fixture as a caller's context: what reaches the socket carries no denylisted
     * value, and the blocks `conformance/pii/expected.json` describes are reproduced field for field.
     */
    public function testNoDenylistedValueEverReachesTheSocket(): void
    {
        $raw = json_decode((string) file_get_contents(self::ROOT . '/conformance/pii/raw.json'), true);
        $expected = json_decode((string) file_get_contents(self::ROOT . '/conformance/pii/expected.json'), true);
        $this->install('pii');

        AnvilFeed::captureException(new \RuntimeException($raw['exception']['message']), [
            'request' => $raw['request'],
            'user_id' => $raw['user']['id'],
            'breadcrumbs' => $raw['breadcrumbs'],
            'tags' => $raw['tags'],
        ]);
        AnvilFeed::flush();

        $entries = $this->journal('pii');
        self::assertCount(1, $entries);
        $body = $entries[0]['body'];

        self::assertSame($expected['request'], $body['request']);
        self::assertSame($expected['user'], $body['user'], 'user.ip and user.ip_hash are never sent');
        self::assertSame($expected['breadcrumbs'], $body['breadcrumbs']);
        self::assertSame($expected['tags'], $body['tags']);
        self::assertSame($expected['exception']['message'], $body['exception']['message']);

        $onTheWire = json_encode($body);
        foreach ([
            'eyJhbGciOiJIUzI1NiJ9.abc', 'sk-live-99887766', '4111111111111111',
            'CH9300762011623852957', 'ada@example.org', '203.0.113.9', 'sid=abc', 'abc123',
        ] as $secret) {
            self::assertStringNotContainsString($secret, (string) $onTheWire,
                'a denylisted value reached the socket');
        }
        self::assertTrue($entries[0]['events'][0]['schema_valid'],
            'the scrubbed body must itself be a sendable event');
    }

    /**
     * Scenario 8. The loop guard: an exception raised while serving `/api/feed/**` is not reported, or the
     * SDK's own failures become the SDK's own traffic.
     */
    public function testACaptureFromInsideTheFeedPathNeverReachesTheIngest(): void
    {
        $client = $this->install('loopguard');

        $id = AnvilFeed::captureException(new \RuntimeException('a fault while serving the ingest'), [
            'request' => ['method' => 'POST', 'url' => 'https://app.anvil-coder.tech/api/feed/events'],
        ]);

        self::assertNull($id, 'an ignored request path is one of the seven documented nulls');
        self::assertSame(0, $client->queue()->count());
        AnvilFeed::flush();
        self::assertCount(0, $this->journal('loopguard'));
    }

    /**
     * Scenario 9, at the core's level: `captureTestEvent()` reads the ingest's answer. The four verdicts a
     * developer is shown are assembled in the adapters — `anvil-feed:test` and
     * `anvil.feed.send-test-event` — and `AnvilFeed\Laravel\Tests\EndToEndTestCommandTest` proves those
     * four against this same stub. What belongs here is the raw material: an accepted id, and a rejection
     * that names the ingest's own reason code.
     */
    public function testATestEventIsAcceptedAndCarriesTheIdTheCockpitIsSearchedFor(): void
    {
        $client = $this->install('testevent');

        $id = AnvilFeed::captureTestEvent();
        self::assertNotNull($id);
        AnvilFeed::flush();

        $entries = $this->journal('testevent');
        self::assertCount(1, $entries);
        self::assertSame(202, $entries[0]['status']);
        self::assertSame([$id], $entries[0]['response']['accepted']);
        self::assertSame('AnvilFeed.TestEvent', $entries[0]['body']['exception']['type']);
        self::assertTrue($entries[0]['events'][0]['schema_valid']);
        self::assertFalse($client->sender()->isStopped());
    }

    /** An unbound environment is the first thing a real integration meets, and it is not retryable. */
    public function testAnUnboundEnvironmentIsAnsweredNoBindingAndTheEventIsNotResent(): void
    {
        $client = $this->install('nobinding', 'accepted', ['environment' => 'staging']);

        AnvilFeed::captureTestEvent();
        AnvilFeed::flush();

        $entries = $this->journal('nobinding');
        self::assertCount(1, $entries);
        self::assertSame('no_binding', $entries[0]['response']['rejected'][0]['reason_code']);
        self::assertSame(0, $client->queue()->count(), 'a verdict is final, even a refusing one');
        self::assertFalse($client->sender()->isStopped());

        AnvilFeed::flush();
        self::assertCount(1, $this->journal('nobinding'), 'a 4xx-equivalent verdict is never retried');
    }

    /**
     * A binding that exists and is switched off. It is the third of the four verdicts a first integration
     * meets, it is FINAL — the event is not resent — and it is the one an SDK is most tempted to retry,
     * because a 202 with an empty `accepted` list looks like nothing happened.
     */
    public function testADisabledBindingIsFinalAndTheEventIsNotResent(): void
    {
        $client = $this->install('bindingdisabled', 'accepted', ['environment' => 'testing']);

        AnvilFeed::captureTestEvent();
        AnvilFeed::flush();

        $entries = $this->journal('bindingdisabled');
        self::assertCount(1, $entries);
        self::assertSame('binding_disabled', $entries[0]['response']['rejected'][0]['reason_code']);
        self::assertSame(0, $client->queue()->count());
        self::assertFalse($client->sender()->isStopped());

        AnvilFeed::flush();
        self::assertCount(1, $this->journal('bindingdisabled'));
    }

    /**
     * A server that answers the status line and then withholds the body past any sane request budget. The
     * event is KEPT — a read timeout is transient and the batch has not been refused — the sender is not
     * stopped, and the whole flush returns long before the stub would have finished.
     *
     * The elapsed time is measured rather than slept through: the assertion is that the budget reached the
     * socket, and a flush that took the stub's five seconds would fail it.
     */
    public function testASlowIngestIsATransientFailureBoundedByTheConfiguredBudget(): void
    {
        $client = $this->install('slowingest', 'slow', [
            'request_timeout_ms' => 300,
            'connect_timeout_ms' => 300,
        ]);

        AnvilFeed::captureException(new \RuntimeException('a fault behind a slow ingest'));
        $startedAt = microtime(true);
        AnvilFeed::flush();
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        self::assertCount(1, $this->journal('slowingest'), 'the request did reach the stub');
        self::assertLessThan(3000, $elapsedMs,
            'the 300 ms budget has to reach the socket; the stub withholds the body for five seconds');
        self::assertSame(1, $client->queue()->count(), 'a read timeout is transient, not a refusal');
        self::assertFalse($client->sender()->isStopped());
        self::assertNotEmpty($this->written->matching('transport failure'));
    }

    /**
     * The two long-window answers of `conformance/responses/index.json`. 137 and 900 are deliberately not
     * the documented 60 and 30: an SDK that lost the `Retry-After` header and fell back to its own
     * constant is indistinguishable from one that read it, for as long as every answer carries the
     * default.
     */
    public function testThePauseIsTheRetryAfterTheServerSentAndNotAConstantTheSdkKnows(): void
    {
        $this->script('longwindow', 'rate-limited-long-window');
        $client = $this->install('longwindow');
        AnvilFeed::captureException(new \RuntimeException('a fault during a long rate-limit window'));
        AnvilFeed::flush();

        self::assertSame('137', $this->journal('longwindow')[0]['retry_after']);
        self::assertSame($this->now + 137.0, $client->sender()->pausedUntil());

        $this->script('longwindow2', 'transient-long-window');
        $client = $this->install('longwindow2');
        AnvilFeed::captureException(new \LogicException('a fault during a long maintenance window'));
        AnvilFeed::flush();

        self::assertSame('900', $this->journal('longwindow2')[0]['retry_after']);
        self::assertSame($this->now + 900.0, $client->sender()->pausedUntil());
        self::assertSame(1, $client->queue()->count(), 'a 503 keeps the whole batch');
    }

    /**
     * The mapper trims nothing: a padded id reaches `UUID.fromString` with its padding
     * (AnvilFeedEventMapper.java:97-114). A stub that trimmed first would answer 202 to a body the running
     * ingest refuses per event — which is the proxy this whole task exists to make expensive.
     */
    public function testAPaddedEventIdIsRefusedBecauseTheMapperTrimsNothing(): void
    {
        $event = json_decode(
            (string) file_get_contents(self::ROOT . '/conformance/valid/minimal.json'),
            true
        );
        $event['event_id'] = '  ' . $event['event_id'] . '  ';

        $answer = $this->post('/raw/accepted/api/feed/events', (string) json_encode($event));
        $body = json_decode($answer['body'], true);

        self::assertSame(202, $answer['status']);
        self::assertSame([], $body['accepted']);
        self::assertSame('schema_violation', $body['rejected'][0]['reason_code']);
        self::assertStringContainsString('canonical UUID', $body['rejected'][0]['message']);
    }

    /** Disabled is the fourth verdict, and it reaches no socket at all. */
    public function testADisabledSdkSendsNothing(): void
    {
        $client = $this->install('disabled', 'accepted', ['enabled' => false]);

        self::assertNull(AnvilFeed::captureTestEvent());
        AnvilFeed::flush();

        self::assertCount(0, $this->journal('disabled'));
        self::assertSame(0, $client->queue()->count());
    }
}
