<?php

declare(strict_types=1);

namespace AnvilFeed\Tests;

use AnvilFeed\Config;
use AnvilFeed\Logger;
use AnvilFeed\Transport\PsrTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as PsrResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The preferred path: the host application's own PSR-18 client. What has to hold is that the request is
 * shaped the way the ingest expects, and that nothing the client does — including throwing something
 * outside its own interface — ever leaves this class (FR-2 b).
 */
final class PsrTransportTest extends TestCase
{
    private function config(): Config
    {
        return Config::fromArray([
            'endpoint' => 'https://app.anvil-coder.tech/api/feed',
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
        ], new Logger());
    }

    private function transport(ClientInterface $client): PsrTransport
    {
        $factory = new Psr17Factory();

        return new PsrTransport($this->config(), $client, $factory, $factory);
    }

    public function testTheRequestCarriesTheTokenTheContentTypeAndNoOriginHeader(): void
    {
        $client = new class implements ClientInterface {
            public ?RequestInterface $seen = null;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->seen = $request;

                return new PsrResponse(202, [], '{"accepted":[],"rejected":[]}');
            }
        };

        $response = $this->transport($client)->post('https://app.anvil-coder.tech/api/feed/events', '{"a":1}');

        self::assertSame(202, $response->status);
        self::assertSame('{"accepted":[],"rejected":[]}', $response->body);
        self::assertNotNull($client->seen);
        self::assertSame('POST', $client->seen->getMethod());
        self::assertSame('https://app.anvil-coder.tech/api/feed/events', (string) $client->seen->getUri());
        self::assertSame('application/json', $client->seen->getHeaderLine('Content-Type'));
        self::assertSame('Bearer anv_srv_0123456789abcdef0123456789abcdef',
            $client->seen->getHeaderLine('Authorization'));
        self::assertSame('anvil-feed-php/0.1.0', $client->seen->getHeaderLine('User-Agent'));
        self::assertFalse($client->seen->hasHeader('Origin'),
            'an Origin header makes the ingest answer 403 and treat the server token as leaked (FR-9f)');
        self::assertSame('{"a":1}', (string) $client->seen->getBody());
    }

    public function testRetryAfterIsReadOffTheResponseHeader(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new PsrResponse(429, ['Retry-After' => '60'], '');
            }
        };

        $response = $this->transport($client)->post('https://example.test/api/feed/events', '{}');

        self::assertSame(429, $response->status);
        self::assertSame(60, $response->retryAfterSeconds);
    }

    public function testAnHttpDateRetryAfterIsNullRatherThanZero(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new PsrResponse(429, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], '');
            }
        };

        $response = $this->transport($client)->post('https://example.test/api/feed/events', '{}');

        self::assertNull($response->retryAfterSeconds, 'the sender must fall back to its documented '
            . 'default; (int) "Wed, …" is 0 and would turn a rate limit into a hot loop');
    }

    /** The same length cap as the bundled transport — one reader, one rule (review finding 8). */
    public function testARetryAfterOfTwentyDigitsIsNullRatherThanForEver(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new PsrResponse(429, ['Retry-After' => '99999999999999999999'], '');
            }
        };

        $response = $this->transport($client)->post('https://example.test/api/feed/events', '{}');

        self::assertNull($response->retryAfterSeconds,
            'PHP_INT_MAX seconds is a muted worker, not a pause');
    }

    public function testAClientExceptionBecomesATransportFailure(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('connect() timed out') extends \RuntimeException
                    implements ClientExceptionInterface {};
            }
        };

        $response = $this->transport($client)->post('https://example.test/api/feed/events', '{}');

        self::assertTrue($response->transportFailure);
        self::assertSame(0, $response->status, 'never a status code: a 4xx drops events, this one keeps them');
        self::assertSame('connect() timed out', $response->failureReason);
    }

    public function testAClientThatThrowsOutsideItsOwnInterfaceIsAbsorbedToo(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \TypeError('a host application\'s decorator, not a PSR-18 exception');
            }
        };

        $response = $this->transport($client)->post('https://example.test/api/feed/events', '{}');

        self::assertTrue($response->transportFailure);
    }
}
