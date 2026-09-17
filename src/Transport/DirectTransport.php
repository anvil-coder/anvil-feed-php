<?php

declare(strict_types=1);

namespace AnvilFeed\Transport;

use AnvilFeed\Config;

/**
 * The bundled fallback, used when the host application injects no PSR-18 client: curl where ext-curl is
 * present, a stream context otherwise. Eighty lines instead of a dependency, so that the core works in a
 * tree that has no HTTP library at all.
 *
 * Why not php-http/discovery: it is a runtime dependency with a composer plugin that picks, at runtime,
 * whichever HTTP client the host application happens to have. That is one more party between this library
 * and the single endpoint it is allowed to contact (X3, C13), it changes behaviour when an unrelated
 * package is installed, and it makes the failure mode of a customer's production depend on their lock
 * file. An injected ClientInterface covers every host that already has one; this covers the rest.
 *
 * No Origin header is ever set. A server token presented with one is refused with 403 and, per the
 * published contract, has to be rotated.
 */
final class DirectTransport implements Transport
{
    private readonly bool $useCurl;

    /**
     * @param ?bool $useCurl Leave it null: curl where ext-curl is present, the stream context otherwise.
     *                       It is settable only so that the STREAM path can be proved against a real
     *                       socket on a machine that has ext-curl — which is every machine this is
     *                       developed on, and none of the ones where that path is the only one there is.
     */
    public function __construct(private readonly Config $config, ?bool $useCurl = null)
    {
        $this->useCurl = $useCurl ?? function_exists('curl_init');
    }

    public function post(string $url, string $body): Response
    {
        try {
            return $this->useCurl ? $this->viaCurl($url, $body) : $this->viaStream($url, $body);
        } catch (\Throwable $anything) {
            // FR-2 b is absolute. A warning promoted to an exception by a host's error handler, a disabled
            // function, an open_basedir refusal — none of them may leave this method.
            return Response::failure($anything->getMessage());
        }
    }

    private function viaCurl(string $url, string $body): Response
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return Response::failure('curl_init failed');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT_MS => $this->config->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $this->config->requestTimeoutMs,
            CURLOPT_HTTPHEADER => $this->headers(),
        ]);

        $raw = curl_exec($handle);
        if ($raw === false) {
            $reason = curl_error($handle);
            curl_close($handle);

            return Response::failure($reason);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        return new Response(
            $status,
            substr((string) $raw, $headerSize),
            self::retryAfter(substr((string) $raw, 0, $headerSize))
        );
    }

    private function viaStream(string $url, string $body): Response
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $this->headers()),
            'content' => $body,
            'timeout' => $this->config->requestTimeoutMs / 1000,
            'ignore_errors' => true,
        ]]);

        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            return Response::failure('stream transport failed');
        }
        /** @var list<string> $headers  set by file_get_contents in this very scope */
        $headers = $http_response_header ?? [];
        $status = 0;
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return new Response($status, $result, self::retryAfter(implode("\r\n", $headers)));
    }

    /** @return list<string> */
    private function headers(): array
    {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . ($this->config->token ?? ''),
            'User-Agent: anvil-feed-php/0.1.0',
            'Accept: application/json',
        ];
    }

    /**
     * Only the delta-seconds form. RFC 9110 also allows an HTTP-date, which this ingest never sends
     * (`Retry-After: 60` and `Retry-After: 30` are literals in AnvilFeedIngestController); an unreadable
     * value becomes null and the sender falls back to its documented default rather than to 0.
     */
    private static function retryAfter(string $headerBlock): ?int
    {
        return preg_match('/^retry-after:\s*(\d+)/im', $headerBlock, $m) === 1 ? (int) $m[1] : null;
    }
}
