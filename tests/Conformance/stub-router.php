<?php

declare(strict_types=1);

/**
 * The published ingest contract's STATUS PATHS, over a real socket. Started by MockServerTest with
 * `php -S`; it is not a test file and PHPUnit's `Test.php` suffix rule leaves it alone.
 *
 * It exists because the thing under test is the whole client — `AnvilFeed::init` → `captureException` →
 * `flush` — and the transport in that chain is `DirectTransport`, the bundled fallback, which has no seam
 * a double could take. A hand-written `Transport` returning a `Response` object proves the Sender's
 * decision table and nothing about the status line, the `Retry-After` header, the redirect, or the
 * timeout: those only exist on a socket.
 *
 * The URL carries the scenario, because `DirectTransport` sends the headers the contract says it sends
 * and nothing a test could add:
 *
 *     http://127.0.0.1:<port>/<run>/<scenario>/api/feed/events[/batch]
 *
 * `<run>` is a per-test token; every request is appended as one JSON line to
 * `<tmp>/anvil-feed-stub-<run>.jsonl`, so a test can assert how many requests were made, which URL each
 * went to, whether the body was an object or an array, and whether the credential arrived.
 *
 * The bodies are the published fixtures in `conformance/responses/` — not prose retyped here — so that a
 * response the server learns to give is changed in one place.
 */

$responses = __DIR__ . '/../../../../conformance/responses';

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
$run = $segments[0] ?? 'unknown';
$scenario = $segments[1] ?? 'accepted';

$body = (string) file_get_contents('php://input');
$decoded = json_decode($body, true);
$isArrayShape = is_array($decoded) && array_is_list($decoded);
$events = $isArrayShape ? $decoded : [$decoded];

$ids = [];
foreach ($events as $event) {
    if (is_array($event) && is_string($event['event_id'] ?? null)) {
        $ids[] = $event['event_id'];
    }
}

$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
file_put_contents(
    sys_get_temp_dir() . '/anvil-feed-stub-' . preg_replace('/[^a-z0-9]/i', '', $run) . '.jsonl',
    json_encode([
        'scenario' => $scenario,
        'path' => $path,
        'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
        'shape' => $decoded === null ? 'unreadable' : ($isArrayShape ? 'array' : 'object'),
        'ids' => $ids,
        'authorization' => $authorization,
        'content_type' => (string) ($_SERVER['HTTP_CONTENT_TYPE'] ?? ''),
        'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'origin' => (string) ($_SERVER['HTTP_ORIGIN'] ?? ''),
    ], JSON_UNESCAPED_SLASHES) . "\n",
    FILE_APPEND | LOCK_EX
);

/** The published body, with this request's own ids substituted where the fixture names one. */
$fixture = static function (string $name) use ($responses): string {
    return (string) file_get_contents($responses . '/' . $name);
};

header('Content-Type: application/json');

switch ($scenario) {
    case 'accepted':
        http_response_code(202);
        echo json_encode(['accepted' => $ids, 'rejected' => []]);
        break;

    // responses/202-partial.json: one accepted, one rejected — BOTH final. The reason code and the
    // message are the fixture's; only the ids are this request's.
    case 'partial':
        $partial = json_decode($fixture('202-partial.json'), true);
        $rejection = $partial['rejected'][0];
        http_response_code(202);
        echo json_encode([
            'accepted' => array_slice($ids, 0, 1),
            'rejected' => array_map(
                static fn (string $id): array => [
                    'event_id' => $id,
                    'reason_code' => $rejection['reason_code'],
                    'message' => $rejection['message'],
                ],
                array_slice($ids, 1)
            ),
        ]);
        break;

    case 'bad-request':
        http_response_code(400);
        echo $fixture('400-schema-violation.json');
        break;

    // The ingest answers 401 with no body at all; an SDK that needs one to decide is broken.
    case 'unauthorized':
        http_response_code(401);
        break;

    case 'forbidden':
        http_response_code(403);
        break;

    case 'too-large':
        http_response_code(413);
        echo $fixture('413-payload-too-large.json');
        break;

    case 'rate-limited':
        http_response_code(429);
        header('Retry-After: 60');
        echo $fixture('429-rate-limited.json');
        break;

    case 'transient':
        http_response_code(503);
        header('Retry-After: 30');
        echo $fixture('503-transient.json');
        break;

    // Longer than the caller's request_timeout_ms, so the client is the one that gives up.
    case 'timeout':
        sleep(3);
        http_response_code(202);
        echo json_encode(['accepted' => $ids, 'rejected' => []]);
        break;

    // One operator typo in `endpoint` and a 30x is what the SDK meets. Following it would present the
    // anv_srv_ token to a host the customer never named — and would degrade POST to GET, so the events
    // would not even be in the second request while its 202 read as a delivery.
    case 'redirect':
        header('Location: /' . $run . '/leaked/api/feed/events', true, 302);
        echo '{}';
        break;

    // Reached only if the redirect WAS followed. It writes down the credential it was handed, so the
    // assertion is the absence of that file rather than the absence of a second call in a mock.
    case 'leaked':
        file_put_contents(
            sys_get_temp_dir() . '/anvil-feed-leaked-' . preg_replace('/[^a-z0-9]/i', '', $run),
            $authorization === '' ? 'no-authorization-header' : $authorization
        );
        http_response_code(202);
        echo json_encode(['accepted' => $ids, 'rejected' => []]);
        break;

    default:
        http_response_code(500);
        echo '{}';
}

return true;
