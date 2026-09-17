<?php

declare(strict_types=1);

/**
 * The router of a `php -S` instance that stands in for the ingest in DirectTransportTest. It exists so
 * that the bundled fallback transport is proved against a real socket, a real status line and a real
 * Retry-After header rather than against a mock of itself: the class has no seam a unit test could use,
 * and it is the one every customer without a PSR-18 client will run in production.
 *
 * /api/feed/events        202 and a JSON echo of what arrived (headers and body)
 * /api/feed/events/batch  the same
 * /rate-limited           429 with `Retry-After: 60` and no body, exactly as the ingest answers
 * /no-retry-after         429 with no Retry-After at all
 * /redirect?marker=…      302 to /elsewhere with the same marker — the credential-leak case
 * /elsewhere?marker=…     writes a marker file, so a test can prove the redirect was NOT followed
 * /dribble                headers, then one byte every 100 ms for three seconds — the hung-server case
 */

function anvil_marker_file(string $marker): string
{
    return sys_get_temp_dir() . '/anvil-feed-redirect-target-' . preg_replace('/[^a-z0-9]/i', '', $marker);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY), $query);

if ($path === '/redirect') {
    header('Location: /elsewhere?marker=' . (string) ($query['marker'] ?? ''), true, 302);

    return true;
}
if ($path === '/elsewhere') {
    // Reached only if the transport followed the redirect — which is the defect, and which would have
    // carried the Authorization header here.
    file_put_contents(anvil_marker_file((string) ($query['marker'] ?? '')), (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? 'no-auth'));
    http_response_code(202);
    echo '{"accepted":[],"rejected":[]}';

    return true;
}
if ($path === '/dribble') {
    // Headers, then a body that never finishes inside any sane budget. Content-Length is deliberately
    // larger than what is sent, so a client that waits for the announced body waits for ever.
    header('Content-Type: application/json');
    header('Content-Length: 100000');
    http_response_code(202);
    for ($i = 0; $i < 30; $i++) {
        echo '.';
        flush();
        if (connection_aborted() === 1) {
            break;   // the client gave up at its budget, which is the point of this endpoint
        }
        usleep(100_000);
    }

    return true;
}

if ($path === '/rate-limited') {
    header('Retry-After: 60', true, 429);

    return true;
}
if ($path === '/no-retry-after') {
    http_response_code(429);

    return true;
}

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string) $key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}

http_response_code(202);
header('Content-Type: application/json');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => $path,
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
], JSON_UNESCAPED_SLASHES);

return true;
