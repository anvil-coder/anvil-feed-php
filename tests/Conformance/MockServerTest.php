<?php

declare(strict_types=1);

namespace AnvilFeed\Tests\Conformance;

use AnvilFeed\AnvilFeed;
use AnvilFeed\Client;
use AnvilFeed\Config;
use AnvilFeed\GitContext;
use AnvilFeed\Logger;
use AnvilFeed\Tests\Support\RecordingLogger;
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
 * The scenario travels in the URL rather than in a header, because `DirectTransport` sends what the
 * contract says it sends and a test may not add to it. `tests/Conformance/stub-router.php` switches on it
 * and answers with the published bodies from `conformance/responses/`.
 *
 * A run in which the server cannot be started is a FAILED run, never a skipped one: this file is the only
 * place the PHP SDK is held against a real ingest conversation.
 */
final class MockServerTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $base = '';

    private const TOKEN = 'anv_srv_0123456789abcdef0123456789abcdef';

    /** Distinct classes, so the per-fingerprint storm cap does not quietly swallow the later captures. */
    private const FAULTS = [
        \RuntimeException::class, \LogicException::class, \DomainException::class,
        \OverflowException::class, \RangeException::class, \UnderflowException::class,
    ];

    private string $run = '';
    private ?Client $client = null;
    private RecordingLogger $written;

    public static function setUpBeforeClass(): void
    {
        $port = self::freePort();
        $pipes = [];
        $process = proc_open(
            sprintf(
                '%s -S 127.0.0.1:%d %s',
                escapeshellarg(PHP_BINARY),
                $port,
                escapeshellarg(__DIR__ . '/stub-router.php')
            ),
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            // The timeout scenario holds a worker for three seconds on purpose; a single-threaded server
            // would turn that into a timeout for every test that ran after it.
            ['PHP_CLI_SERVER_WORKERS' => '8'] + getenv()
        );
        if (!is_resource($process)) {
            return;
        }
        self::$server = $process;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (is_resource($probe)) {
                fclose($probe);
                self::$base = 'http://127.0.0.1:' . $port;

                return;
            }
            usleep(50_000);
        }
        self::stopServer();
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

    // ------------------------------------------------------------------- 202

    /**
     * The healthy path, and the two contract rules that only exist on the wire: ONE event goes to
     * `/events` as a JSON object, and the credential travels as a bearer token — while no `Origin` header
     * is ever sent, which is the very thing the ingest answers 403 to.
     */
    public function testASingleEventIsPostedAsAnObjectToEventsAndClearsTheQueue(): void
    {
        $requests = $this->drive('accepted', 1);

        self::assertCount(1, $requests);
        self::assertStringEndsWith('/api/feed/events', $requests[0]['path']);
        self::assertSame('object', $requests[0]['shape'], 'a single event is an OBJECT; the ingest '
            . 'answers 400 to an array on this route on purpose');
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('Bearer ' . self::TOKEN, $requests[0]['authorization']);
        self::assertSame('', $requests[0]['origin'], 'this SDK never sends an Origin; a server token '
            . 'presented with one is treated as leaked and answered 403');
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

    /**
     * A 202 that accepts one event and refuses the other. BOTH verdicts are final — the rejected id is
     * not retried — and the reason code has to reach the operator, because `no_binding` is the one
     * failure that looks exactly like "everything is fine" from inside the application.
     */
    public function testAPartial202ClearsBothVerdictsAndNamesTheReasonCode(): void
    {
        $this->drive('partial', 2);

        self::assertSame(0, $this->queueCount(), 'a refusing verdict still clears the event');
        self::assertNotSame([], $this->written->matching('no_binding'));
        self::assertNotSame([], $this->written->matching('cockpit'));
    }

    // ------------------------------------------------------------ 4xx

    public function testA400DropsTheBatchSaysWhyOnceAndDoesNotRetry(): void
    {
        $requests = $this->drive('bad-request', 1);

        self::assertCount(1, $requests, 'a 400 is never retried — the sender is the thing that is wrong');
        self::assertSame(0, $this->queueCount());
        self::assertNotSame([], $this->written->matching('schema_violation'));
        self::assertNotSame([], $this->written->matching('400'));
    }

    /**
     * A 401 is the end of sending for this process. The queue is drained — a stopped sender that keeps
     * queueing is a memory leak with a nice name — and a capture made afterwards must return null rather
     * than pile up behind a door that will not open again.
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
     * is nothing left to split. The proof is the request COUNT — a sender that dropped the batch whole
     * would make exactly one request and pass every other assertion here.
     */
    public function testA413HalvesTheBatchRatherThanDroppingItWhole(): void
    {
        $requests = $this->drive('too-large', 4);

        self::assertGreaterThan(1, count($requests), 'the batch must be split, not dropped whole');
        self::assertSame(1, count($requests[count($requests) - 1]['ids']),
            'the split ends at a single event, which is the only thing that may be dropped for size');
        self::assertSame(0, $this->queueCount());
        self::assertNotSame([], $this->written->matching('payload_too_large'));
        self::assertNotSame([], $this->written->matching('Halving the batch'));
    }

    /**
     * 429 is the one status that must never cost an event. The pause is exactly `Retry-After` — read off
     * the real header, which is the part no `Response` double exercises — and a flush during the pause
     * must not reach the socket at all.
     */
    public function testA429PausesForExactlyRetryAfterAndKeepsEveryEvent(): void
    {
        $before = microtime(true);
        $requests = $this->drive('rate-limited', 3);

        self::assertCount(1, $requests);
        self::assertSame(3, $this->queueCount(), 'a rate limit never drops an event');
        $pausedUntil = (float) $this->client?->sender()->pausedUntil();
        self::assertGreaterThanOrEqual($before + 59.0, $pausedUntil);
        self::assertLessThanOrEqual(microtime(true) + 60.0, $pausedUntil);

        AnvilFeed::flush();
        self::assertCount(1, $this->requests(), 'a paused sender does not open a connection');
        self::assertSame(3, $this->queueCount());
    }

    /** 503 repeats the WHOLE batch after Retry-After; (organisation, event_id) makes that idempotent. */
    public function testA503KeepsTheWholeBatchAndPausesForExactlyRetryAfter(): void
    {
        $before = microtime(true);
        $this->drive('transient', 3);

        self::assertSame(3, $this->queueCount());
        $pausedUntil = (float) $this->client?->sender()->pausedUntil();
        self::assertGreaterThanOrEqual($before + 29.0, $pausedUntil);
        self::assertLessThanOrEqual(microtime(true) + 30.0, $pausedUntil);
        self::assertFalse($this->client?->sender()->isStopped());
    }

    /**
     * A server that accepted the request and then never answered. The client's own budget has to be what
     * ends the call — FR-2 c, and the reason `request_timeout_ms` exists at all — and the event has to
     * survive: a timeout says nothing about whether the ingest would accept it.
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
     * would receive the `anv_srv_` token, and the redirect degrades POST to GET, so its 202 would be read
     * as a delivery for events that were never in the second request.
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

    // ------------------------------------------------------------- machinery

    /**
     * `AnvilFeed::init` → `captureException` × n → `flush`. The transport is the one `Client::create`
     * builds for itself, so what is exercised is the chain a host application runs and not a wiring this
     * test invented.
     *
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>> the requests the server actually received
     */
    private function drive(string $scenario, int $events, array $options = []): array
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

        $this->client = Client::create($config, null, $logger, GitContext::none());
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

        return $this->requests();
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

    private function requestLog(): string
    {
        return sys_get_temp_dir() . '/anvil-feed-stub-' . $this->run . '.jsonl';
    }

    private function leakMarker(): string
    {
        return sys_get_temp_dir() . '/anvil-feed-leaked-' . $this->run;
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

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!is_resource($socket)) {
            return 8124;
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
