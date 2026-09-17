<?php

declare(strict_types=1);

namespace AnvilFeed\Transport;

use AnvilFeed\Config;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** The preferred path: the host application's own PSR-18 client, injected, with its own proxy and CA setup. */
final class PsrTransport implements Transport
{
    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    public function post(string $url, string $body): Response
    {
        try {
            $request = $this->requests->createRequest('POST', $url)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Authorization', 'Bearer ' . ($this->config->token ?? ''))
                ->withHeader('User-Agent', 'anvil-feed-php/0.1.0')
                ->withHeader('Accept', 'application/json')
                ->withBody($this->streams->createStream($body));

            $response = $this->client->sendRequest($request);
            $retryAfter = trim($response->getHeaderLine('Retry-After'));

            return new Response(
                $response->getStatusCode(),
                (string) $response->getBody(),
                $retryAfter !== '' && ctype_digit($retryAfter) ? (int) $retryAfter : null
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
