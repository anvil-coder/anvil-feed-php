<?php

declare(strict_types=1);

namespace AnvilFeed\Tests;

use AnvilFeed\Config;
use AnvilFeed\Logger;
use AnvilFeed\Transport\DirectTransport;
use PHPUnit\Framework\TestCase;

/**
 * The bundled fallback, against a real socket. It is the transport every host without a PSR-18 client
 * ends up on, it has no seam a mock could use, and the two things it must get right — the status/body/
 * Retry-After split of a real response, and the headers it sends — are invisible to any test that stops
 * at the class boundary.
 */
final class DirectTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $port = self::freePort();
        $command = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg(__DIR__ . '/Support/echo-server.php')
        );
        $pipes = [];
        // Several workers: two of the tests below deliberately hold a connection open for seconds, and a
        // single-threaded server would make every later test in the class time out instead.
        $process = proc_open(
            $command,
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => '6'] + getenv()
        );
        if (!is_resource($process)) {
            return;
        }
        self::$server = $process;
        self::$base = 'http://127.0.0.1:' . $port;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (is_resource($probe)) {
                fclose($probe);

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
            return 8123;
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * Both paths of the fallback, against the same server. The stream path is the one a host WITHOUT
     * ext-curl runs; leaving it to `function_exists` would mean it is never the one this suite exercises.
     *
     * @return array<string, array{bool}>
     */
    public static function bothPaths(): array
    {
        return ['curl' => [true], 'stream' => [false]];
    }

    private function transport(bool $useCurl): DirectTransport
    {
        self::assertNotSame('', self::$base, 'no local HTTP server could be started — this suite is the '
            . 'only place the bundled transport is proved against a real socket, so a run without it is '
            . 'a failed run, not a skipped one');

        return new DirectTransport(Config::fromArray([
            'endpoint' => self::$base . '/api/feed',
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
        ], new Logger()), $useCurl);
    }

    private function boundedTransport(bool $useCurl, int $connectMs, int $requestMs): DirectTransport
    {
        self::assertNotSame('', self::$base, 'the local HTTP server is required for this proof');

        return new DirectTransport(Config::fromArray([
            'endpoint' => self::$base . '/api/feed',
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
            'connect_timeout_ms' => $connectMs,
            'request_timeout_ms' => $requestMs,
        ], new Logger()), $useCurl);
    }

    /**
     * FR-9, and the finding that made this file worth writing: the stream wrapper follows redirects by
     * default AND re-sends the request headers, so one operator typo in `endpoint` hands the `anv_srv_`
     * token to a host the customer never named — while the redirect degrades POST to GET, so the events
     * are not in the second request and the target's answer is read as a delivery.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothPaths')]
    public function testARedirectIsNeverFollowedAndTheTokenNeverReachesTheTarget(bool $useCurl): void
    {
        $marker = bin2hex(random_bytes(8));
        $targetFile = sys_get_temp_dir() . '/anvil-feed-redirect-target-' . $marker;
        @unlink($targetFile);

        $response = $this->transport($useCurl)->post(self::$base . '/redirect?marker=' . $marker, '{}');

        self::assertFileDoesNotExist($targetFile,
            'the redirect target received a request — and with it the Authorization header');
        self::assertSame(302, $response->status, 'the 3xx is handed to the Sender, which drops the batch '
            . 'and says the endpoint is misconfigured');
        self::assertSame('/elsewhere?marker=' . $marker, $response->location,
            'the Location is carried so the log line can name where the endpoint wanted to send the token');
        self::assertFalse($response->transportFailure);
    }

    /**
     * FR-2 (a)/(c): the timeout is a bound on the whole exchange. The server sends its headers and then
     * one byte every 100 ms against a 400 ms budget; the measured failure before this was 12.14 s and a
     * reported *success*.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothPaths')]
    public function testAServerThatDribblesIsCutOffAtTheBudgetRatherThanHeldOpen(bool $useCurl): void
    {
        $transport = $this->boundedTransport($useCurl, 200, 400);

        $started = microtime(true);
        $response = $transport->post(self::$base . '/dribble', '{}');
        $elapsed = microtime(true) - $started;

        self::assertLessThan(1.2, $elapsed, sprintf(
            'a 400 ms budget must not become %.2fs — on a host without ext-curl this runs inside the '
            . 'application\'s shutdown path', $elapsed
        ));
        self::assertTrue($response->transportFailure,
            'an answer that never finished is a transport failure, not a delivery: the events must stay '
            . 'in the queue');
    }

    /** The budget the SENDER passes wins when it is smaller than the configured request timeout. */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothPaths')]
    public function testTheCallersBudgetBoundsTheRequest(bool $useCurl): void
    {
        $transport = $this->boundedTransport($useCurl, 200, 5000);

        $started = microtime(true);
        $response = $transport->post(self::$base . '/dribble', '{}', 300);
        $elapsed = microtime(true) - $started;

        self::assertLessThan(1.2, $elapsed, 'flush() promised the host 2 s; the transport has to honour '
            . 'what is left of it');
        self::assertTrue($response->transportFailure);
    }

    /** The configured `request_timeout_ms` is the ceiling; a generous caller cannot raise it. */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothPaths')]
    public function testAGenerousCallerBudgetIsStillClampedToTheConfiguredTimeout(bool $useCurl): void
    {
        $transport = $this->boundedTransport($useCurl, 200, 300);

        $started = microtime(true);
        $response = $transport->post(self::$base . '/dribble', '{}', 10_000);
        $elapsed = microtime(true) - $started;

        self::assertLessThan(1.2, $elapsed,
            'request_timeout_ms is what the operator set; a caller asking for ten seconds does not get them');
        self::assertTrue($response->transportFailure);
    }

    /**
     * Finding 3: `connect_timeout_ms` was read only on the curl path. 192.0.2.1 is TEST-NET-1 (RFC 5737)
     * — routed nowhere, so the connect hangs rather than being refused.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothPaths')]
    public function testConnectTimeoutIsHonouredOnBothPaths(bool $useCurl): void
    {
        $transport = new DirectTransport(Config::fromArray([
            'endpoint' => 'http://192.0.2.1/api/feed',
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
            'connect_timeout_ms' => 300,
            'request_timeout_ms' => 5000,
        ], new Logger()), $useCurl);

        $started = microtime(true);
        $response = $transport->post('http://192.0.2.1/api/feed/events', '{}');
        $elapsed = microtime(true) - $started;

        self::assertTrue($response->transportFailure);
        self::assertLessThan(2.0, $elapsed, sprintf(
            'connect_timeout_ms is 300; %.2fs means the setting is ignored and the default socket timeout '
            . '(60s here) is what a customer actually gets', $elapsed
        ));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothPaths')]
    public function testItPostsTheBodyWithTheTokenAndWithoutAnOriginHeader(bool $useCurl): void
    {
        $transport = $this->transport($useCurl);
        $response = $transport->post(self::$base . '/api/feed/events', '{"event_id":"a"}');

        self::assertSame(202, $response->status);
        $echoed = json_decode($response->body, true);
        self::assertIsArray($echoed);
        self::assertSame('POST', $echoed['method']);
        self::assertSame('{"event_id":"a"}', $echoed['body']);
        self::assertSame('application/json', $echoed['headers']['content-type'] ?? null);
        self::assertSame('Bearer anv_srv_0123456789abcdef0123456789abcdef',
            $echoed['headers']['authorization'] ?? null);
        self::assertSame('anvil-feed-php/0.1.0', $echoed['headers']['user-agent'] ?? null);
        self::assertArrayNotHasKey('origin', $echoed['headers'],
            'an Origin header makes the ingest answer 403 and treat the server token as leaked (FR-9f)');
        self::assertNull($response->retryAfterSeconds);
        self::assertFalse($response->transportFailure);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothPaths')]
    public function testItReadsRetryAfterOffTheHeaderAndNotOffTheBody(bool $useCurl): void
    {
        $response = $this->transport($useCurl)->post(self::$base . '/rate-limited', '{}');

        self::assertSame(429, $response->status);
        self::assertSame(60, $response->retryAfterSeconds);
        self::assertSame('', $response->body, 'the ingest answers a bare 429; the decision is the header');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothPaths')]
    public function testAMissingRetryAfterIsNullRatherThanZero(bool $useCurl): void
    {
        $response = $this->transport($useCurl)->post(self::$base . '/no-retry-after', '{}');

        self::assertSame(429, $response->status);
        self::assertNull($response->retryAfterSeconds, 'null lets the sender fall back to its documented '
            . 'default; a 0 would turn a rate limit into a hot loop');
    }

    /**
     * Review finding 8. `(int) '99999999999999999999'` saturates to PHP_INT_MAX, and `Sender::pause`
     * would then set `pausedUntil` past the heat death of the universe: a long-lived worker behind a
     * buggy proxy is muted for its whole life, with one "paused for …s" line to show for it. Nine digits
     * is thirty-one years and is the same cap `HttpTransport.retryAfter` applies.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothPaths')]
    public function testARetryAfterOfTwentyDigitsIsNullRatherThanForEver(bool $useCurl): void
    {
        $response = $this->transport($useCurl)->post(self::$base . '/absurd-retry-after', '{}');

        self::assertSame(429, $response->status);
        self::assertNull($response->retryAfterSeconds,
            'the sender falls back to its documented 60 s rather than pausing for ever');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothPaths')]
    public function testAnUnreachableIngestIsATransportFailureAndNeverAnException(bool $useCurl): void
    {
        $transport = new DirectTransport(Config::fromArray([
            'endpoint' => 'http://127.0.0.1:1/api/feed',
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
            'connect_timeout_ms' => 200,
            'request_timeout_ms' => 400,
        ], new Logger()), $useCurl);

        $response = $transport->post('http://127.0.0.1:1/api/feed/events', '{}');

        self::assertTrue($response->transportFailure, 'a refused connection must never look like a 4xx');
        self::assertSame(0, $response->status);
        self::assertNotSame('', $response->failureReason);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothPaths')]
    public function testAnUnresolvableHostIsATransportFailureToo(bool $useCurl): void
    {
        $transport = new DirectTransport(Config::fromArray([
            'endpoint' => 'http://anvil-feed.invalid/api/feed',
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
            'connect_timeout_ms' => 500,
            'request_timeout_ms' => 1000,
        ], new Logger()), $useCurl);

        $response = $transport->post('http://anvil-feed.invalid/api/feed/events', '{}');

        self::assertTrue($response->transportFailure);
    }
}
