<?php

declare(strict_types=1);

namespace AnvilFeed\Transport;

use AnvilFeed\Config;

/**
 * The bundled fallback, used when the host application injects no PSR-18 client: curl where ext-curl is
 * present, a raw socket otherwise. Eighty lines instead of a dependency, so that the core works in a tree
 * that has no HTTP library at all.
 *
 * Why not php-http/discovery: it is a runtime dependency with a composer plugin that picks, at runtime,
 * whichever HTTP client the host application happens to have. That is one more party between this library
 * and the single endpoint it is allowed to contact (X3, C13), it changes behaviour when an unrelated
 * package is installed, and it makes the failure mode of a customer's production depend on their lock
 * file. An injected ClientInterface covers every host that already has one; this covers the rest.
 *
 * Three absolutes, and both paths keep all three:
 *
 *  - **No Origin header, ever.** A server token presented with one is refused with 403 and, per the
 *    published contract, has to be rotated.
 *  - **No redirect is ever followed.** The stream wrapper's default is `follow_location = 1`, and it
 *    re-sends the request headers to the new host: one operator typo in `endpoint`, or one corporate
 *    proxy bouncing http → https, and the `anv_srv_` token is handed to a host the customer never named
 *    — while the redirect degrades POST to GET, so the events are not even in the second request. The
 *    3xx is returned to the Sender, which drops the batch and says the endpoint is misconfigured.
 *  - **The timeout is a TOTAL budget** — connect, write and read together, on the wall clock. The http
 *    wrapper's own `timeout` is a per-read timeout: a server that dribbles one byte every 300 ms held a
 *    400 ms budget open for 12 s and then reported success.
 */
final class DirectTransport implements Transport
{
    private const USER_AGENT = 'anvil-feed-php/0.1.0';
    /** A budget below this is not a request, it is a failure that costs a socket. */
    private const MINIMUM_BUDGET_MS = 1;

    private readonly bool $useCurl;

    /**
     * @param ?bool $useCurl Leave it null: curl where ext-curl is present, the raw socket otherwise.
     *                       It is settable only so that the STREAM path can be proved against a real
     *                       socket on a machine that has ext-curl — which is every machine this is
     *                       developed on, and none of the ones where that path is the only one there is.
     */
    public function __construct(private readonly Config $config, ?bool $useCurl = null)
    {
        $this->useCurl = $useCurl ?? function_exists('curl_init');
    }

    public function post(string $url, string $body, ?int $timeoutMs = null): Response
    {
        $budgetMs = max(self::MINIMUM_BUDGET_MS, min(
            $this->config->requestTimeoutMs,
            $timeoutMs ?? $this->config->requestTimeoutMs
        ));

        try {
            return $this->useCurl
                ? $this->viaCurl($url, $body, $budgetMs)
                : $this->viaStream($url, $body, $budgetMs);
        } catch (\Throwable $anything) {
            // FR-2 b is absolute. A warning promoted to an exception by a host's error handler, a disabled
            // function, an open_basedir refusal — none of them may leave this method.
            return Response::failure($anything->getMessage());
        }
    }

    private function viaCurl(string $url, string $body, int $budgetMs): Response
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
            // Explicit, though false is the default: this is a promise, not an accident of the library.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min($this->config->connectTimeoutMs, $budgetMs),
            CURLOPT_TIMEOUT_MS => $budgetMs,
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
        $headerBlock = substr((string) $raw, 0, $headerSize);

        return new Response(
            $status,
            substr((string) $raw, $headerSize),
            self::retryAfter($headerBlock),
            false,
            '',
            self::header($headerBlock, 'location')
        );
    }

    /**
     * A raw socket and HTTP/1.0. Not `file_get_contents` with an http context: that wrapper follows
     * redirects with the credential attached, and its `timeout` bounds a single read rather than the
     * exchange. HTTP/1.0 with `Connection: close` also means no chunked response body to reassemble.
     */
    private function viaStream(string $url, string $body, int $budgetMs): Response
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return Response::failure('the ingest endpoint is not a usable URL');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return Response::failure('the ingest endpoint must be http(s), not ' . $scheme);
        }
        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $target = ((string) ($parts['path'] ?? '/')) . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $deadline = microtime(true) + ($budgetMs / 1000);
        $connectSeconds = min($this->config->connectTimeoutMs, $budgetMs) / 1000;

        $socket = @stream_socket_client(
            ($scheme === 'https' ? 'ssl://' : 'tcp://') . $host . ':' . $port,
            $errorNumber,
            $errorMessage,
            $connectSeconds,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['peer_name' => $host]])
        );
        if (!is_resource($socket)) {
            return Response::failure(sprintf(
                'could not connect to %s:%d within %dms (%s)',
                $host,
                $port,
                (int) ($connectSeconds * 1000),
                $errorMessage === '' ? 'error ' . $errorNumber : (string) $errorMessage
            ));
        }

        $request = sprintf("POST %s HTTP/1.0\r\nHost: %s\r\n", $target, $host . ($port === 80 || $port === 443 ? '' : ':' . $port))
            . implode("\r\n", $this->headers()) . "\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;

        $written = $this->write($socket, $request, $deadline);
        if ($written !== null) {
            fclose($socket);

            return Response::failure($written);
        }

        $raw = $this->read($socket, $deadline);
        fclose($socket);
        if (is_string($raw) === false) {
            return Response::failure($raw[0]);
        }

        return self::parse($raw);
    }

    /**
     * @param resource $socket
     * @return ?string the failure reason, or null when everything went out
     */
    private function write($socket, string $request, float $deadline): ?string
    {
        stream_set_blocking($socket, false);
        $offset = 0;
        $length = strlen($request);
        while ($offset < $length) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return 'the request timed out while it was still being written';
            }
            $read = [];
            $writable = [$socket];
            $except = [];
            if (@stream_select($read, $writable, $except, 0, (int) min(200000, $remaining * 1_000_000)) < 1) {
                continue;
            }
            $sent = @fwrite($socket, substr($request, $offset));
            if ($sent === false) {
                return 'the connection broke while the request was being written';
            }
            $offset += $sent;
        }

        return null;
    }

    /**
     * @param resource $socket
     * @return string|array{0: string} the raw response, or a one-element array holding the failure reason
     */
    private function read($socket, float $deadline): string|array
    {
        $raw = '';
        while (!feof($socket)) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return [sprintf('the ingest did not answer within the budget (%d bytes read)', strlen($raw))];
            }
            $readable = [$socket];
            $writable = [];
            $except = [];
            if (@stream_select($readable, $writable, $except, 0, (int) min(200000, $remaining * 1_000_000)) < 1) {
                continue;   // nothing yet; the wall clock above is the only thing that ends this loop
            }
            $chunk = @fread($socket, 16384);
            if ($chunk === false) {
                break;
            }
            $raw .= $chunk;
            if (self::isComplete($raw)) {
                break;
            }
        }

        return $raw;
    }

    /** True once the body announced by Content-Length has arrived — a server that keeps the socket open. */
    private static function isComplete(string $raw): bool
    {
        $split = strpos($raw, "\r\n\r\n");
        if ($split === false) {
            return false;
        }
        $headerBlock = substr($raw, 0, $split);
        $announced = self::header($headerBlock, 'content-length');

        return $announced !== null && strlen($raw) - ($split + 4) >= (int) $announced;
    }

    private static function parse(string $raw): Response
    {
        $split = strpos($raw, "\r\n\r\n");
        $headerBlock = $split === false ? $raw : substr($raw, 0, $split);
        $body = $split === false ? '' : substr($raw, $split + 4);
        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $headerBlock, $m) === 1 ? (int) $m[1] : 0;

        return new Response(
            $status,
            $body,
            self::retryAfter($headerBlock),
            false,
            '',
            self::header($headerBlock, 'location')
        );
    }

    /** @return list<string> */
    private function headers(): array
    {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . ($this->config->token ?? ''),
            'User-Agent: ' . self::USER_AGENT,
            'Accept: application/json',
        ];
    }

    /** Delta-seconds only, and length-capped: the rule and the reason are in {@see Response::retryAfterSeconds}. */
    private static function retryAfter(string $headerBlock): ?int
    {
        return Response::retryAfterSeconds(self::header($headerBlock, 'retry-after'));
    }

    private static function header(string $headerBlock, string $name): ?string
    {
        $found = preg_match('/^' . preg_quote($name, '/') . ':\s*(.*)$/im', $headerBlock, $m) === 1
            ? trim($m[1])
            : null;

        return $found === '' ? null : $found;
    }
}
