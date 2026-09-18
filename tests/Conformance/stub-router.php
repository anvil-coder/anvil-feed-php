<?php

declare(strict_types=1);

/**
 * The published ingest contract, over a real socket — both halves of it.
 *
 * Started by MockServerTest with `php -S`; it is not a test file and PHPUnit's `Test.php` suffix rule
 * leaves it alone. It exists because the thing under test is the whole client — `AnvilFeed::init` →
 * `captureException` → `flush` — and the transport in that chain is `DirectTransport`, the bundled
 * fallback, which has no seam a double could take. A hand-written `Transport` returning a `Response`
 * object proves the Sender's decision table and nothing about the status line, the `Retry-After` header,
 * the redirect, or the timeout: those only exist on a socket.
 *
 * **The request-side rules are enforced first, whatever the scenario says.** That is what turns every
 * test in the file into a standing check that the SDK never *earns* a 400, a 401, a 403 or a 413 —
 * rather than only proving it reacts to one it was handed. `docs/anvil-feed-ingest.md` is the source of
 * each rule; they are listed in `$refuse` below with the answer the ingest gives.
 *
 * The scenario travels in the URL, because `DirectTransport` sends the headers the contract says it
 * sends and a test may not add to them:
 *
 *     http://127.0.0.1:<port>/<run>/<scenario>/api/feed/events[/batch]
 *
 * Any entry of `conformance/responses/index.json` is a scenario, named by its file without `.json` —
 * status, `Retry-After` and body all come from the published fixture, so a response the server learns to
 * give is added in one place and both runners start walking it. The handful of scenarios that are not
 * response fixtures (`accepted`, `unauthorized`, `forbidden`, `timeout`, `redirect`, `leaked`,
 * `no-retry-after-*`, `unexpected-500`) are explained where they are handled.
 *
 * `<run>` is a per-test token; every request is appended as one JSON line to
 * `<tmp>/anvil-feed-stub-<run>.jsonl`.
 */

$responses = __DIR__ . '/../../../../conformance/responses';

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
$run = (string) preg_replace('/[^a-z0-9]/i', '', $segments[0] ?? 'unknown');
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
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

file_put_contents(
    sys_get_temp_dir() . '/anvil-feed-stub-' . $run . '.jsonl',
    json_encode([
        'scenario' => $scenario,
        'path' => $path,
        'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
        'shape' => $decoded === null ? 'unreadable' : ($isArrayShape ? 'array' : 'object'),
        'events' => $isArrayShape ? count($decoded) : 1,
        'ids' => $ids,
        'bytes' => strlen($body),
        'authorization' => $authorization,
        'content_type' => (string) ($_SERVER['HTTP_CONTENT_TYPE'] ?? ''),
        'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'origin' => $origin,
    ], JSON_UNESCAPED_SLASHES) . "\n",
    FILE_APPEND | LOCK_EX
);

header('Content-Type: application/json');

$fixture = static fn (string $name): string => (string) file_get_contents($responses . '/' . $name);

/** @return array<string, mixed>|null the published entry for a scenario named after a response fixture */
$indexEntry = static function (string $wanted) use ($responses): ?array {
    foreach (json_decode((string) file_get_contents($responses . '/index.json'), true) as $entry) {
        if (basename((string) $entry['file'], '.json') === $wanted) {
            return $entry;
        }
    }

    return null;
};

/**
 * The ingest's own refusals, applied before the scenario and regardless of it.
 *
 * - no bearer, or one that is not an `anv_` key → 401, with NO body: a finer answer would tell an
 *   attacker which half was wrong. This comes FIRST — the running ingest (AnvilFeedIngestController)
 *   resolves the credential before it looks at `Origin`; the published contract's "refused with 403,
 *   whatever the token says" is not what the code does (dark-factory issue #338).
 * - `Origin` present with a resolved server credential → 403: a server credential presented from a
 *   browser context is treated as leaked.
 * - body over 262144 bytes → 413 with `payload_too_large`.
 * - a body that is not JSON, or a batch of more than 50 → 400 with `schema_violation`.
 */
$refuse = static function () use ($origin, $authorization, $body, $decoded, $isArrayShape): ?int {
    if (preg_match('/^Bearer anv_(srv|pub)_[0-9a-f]{32}$/', $authorization) !== 1) {
        return 401;
    }
    if ($origin !== '') {
        return 403;
    }
    if (strlen($body) > 262144) {
        return 413;
    }
    if (!is_array($decoded)) {
        return 400;
    }
    if ($isArrayShape && count($decoded) > 50) {
        return 400;
    }

    return null;
};

$refused = $refuse();
if ($refused !== null) {
    http_response_code($refused);
    echo match ($refused) {
        400 => $fixture('400-schema-violation.json'),
        413 => $fixture('413-payload-too-large.json'),
        default => '',
    };

    return true;
}

/**
 * A 202 body names a verdict per event, spelled exactly as the sender posted it. The fixture supplies
 * the reason codes and the messages; only the ids are this request's, because a fixture id would leave
 * every event of the batch un-named and turn every 202 scenario into the retry-once path instead.
 *
 * @param array<string, mixed> $published
 * @param list<string> $ours
 */
$withOurIds = static function (array $published, array $ours): string {
    $accepted = [];
    $rejected = [];
    foreach ($published['accepted'] ?? [] as $ignored) {
        if ($ours !== []) {
            $accepted[] = array_shift($ours);
        }
    }
    foreach ($published['rejected'] ?? [] as $rejection) {
        if ($ours === []) {
            break;
        }
        $rejection['event_id'] = array_shift($ours);
        $rejected[] = $rejection;
    }
    // Anything the fixture had no slot for is accepted: the contract promises a verdict per event.
    $accepted = array_merge($accepted, $ours);

    return (string) json_encode(['accepted' => $accepted, 'rejected' => $rejected]);
};

switch ($scenario) {
    // Not a response fixture: the healthy answer to whatever this request happened to carry.
    case 'accepted':
        http_response_code(202);
        echo json_encode(['accepted' => $ids, 'rejected' => []]);
        break;

    case 'unauthorized':
        http_response_code(401);
        break;

    case 'forbidden':
        http_response_code(403);
        break;

    // Both branches of `$response->retryAfterSeconds ?? DEFAULT_…_PAUSE_SECONDS`: a 429 and a 503 with
    // no Retry-After at all, so the SDK's documented fallback is pinned separately from the header.
    case 'no-retry-after-429':
        http_response_code(429);
        echo '{}';
        break;

    case 'no-retry-after-503':
        http_response_code(503);
        echo '{"accepted":[],"rejected":[]}';
        break;

    // A 5xx that is not 503: exponential backoff with jitter, not a fixed pause.
    case 'unexpected-500':
        http_response_code(500);
        echo '{}';
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
            sys_get_temp_dir() . '/anvil-feed-leaked-' . $run,
            $authorization === '' ? 'no-authorization-header' : $authorization
        );
        http_response_code(202);
        echo json_encode(['accepted' => $ids, 'rejected' => []]);
        break;

    default:
        $entry = $indexEntry($scenario);
        if ($entry === null) {
            http_response_code(500);
            echo json_encode(['stub' => 'unknown scenario ' . $scenario]);
            break;
        }
        http_response_code((int) $entry['status']);
        if ($entry['retry_after'] !== null) {
            header('Retry-After: ' . (int) $entry['retry_after']);
        }
        $published = json_decode($fixture((string) $entry['file']), true);
        echo (int) $entry['status'] === 202 && is_array($published)
            ? $withOurIds($published, $ids)
            : $fixture((string) $entry['file']);
}

return true;
