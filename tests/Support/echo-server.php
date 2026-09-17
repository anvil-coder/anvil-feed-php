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
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

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
