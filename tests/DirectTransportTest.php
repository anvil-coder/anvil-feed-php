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
        $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
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
        if (self::$base === '') {
            self::markTestSkipped('no local HTTP server could be started for the transport proof');
        }

        return new DirectTransport(Config::fromArray([
            'endpoint' => self::$base . '/api/feed',
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
        ], new Logger()), $useCurl);
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
