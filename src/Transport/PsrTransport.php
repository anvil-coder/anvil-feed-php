<?php

declare(strict_types=1);

namespace AnvilFeed\Transport;

use AnvilFeed\Config;
use AnvilFeed\EventFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The preferred path: the host application's own PSR-18 client, injected, with its own proxy and CA setup.
 *
 * Two things this path cannot promise for the host, and both are the host's to configure:
 *
 *  - **The timeout.** PSR-18 has no timeout knob, so `$timeoutMs` cannot be enforced here; whatever the
 *    injected client was constructed with is the bound. Give the client a timeout at or below
 *    `request_timeout_ms` or FR-2 (c) holds only on the bundled transport.
 *  - **Redirects.** A client configured to follow them will re-present the `Authorization` header to the
 *    redirect target. Configure `allow_redirects` off (Guzzle: `['allow_redirects' => false]`); the
 *    bundled DirectTransport never follows one.
 */
final class PsrTransport implements Transport
{
    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    public function post(string $url, string $body, ?int $timeoutMs = null): Response
    {
        try {
            $request = $this->requests->createRequest('POST', $url)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Authorization', 'Bearer ' . ($this->config->token ?? ''))
                ->withHeader('User-Agent', EventFactory::USER_AGENT)
                ->withHeader('Accept', 'application/json')
                ->withBody($this->streams->createStream($body));

            $response = $this->client->sendRequest($request);
            $location = trim($response->getHeaderLine('Location'));

            return new Response(
                $response->getStatusCode(),
                (string) $response->getBody(),
                Response::retryAfterSeconds($response->getHeaderLine('Retry-After')),
                false,
                '',
                $location === '' ? null : $location
            );
        } catch (ClientExceptionInterface $failure) {
            return Response::failure($failure->getMessage());
        } catch (\Throwable $anythingElse) {
            // A host's client is somebody else's code. FR-2 b holds even when it throws something
            // outside its own interface.
            return Response::failure($anythingElse->getMessage());
        }
    }
}
