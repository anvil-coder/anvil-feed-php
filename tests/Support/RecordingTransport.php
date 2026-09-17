<?php

declare(strict_types=1);

namespace AnvilFeed\Tests\Support;

use AnvilFeed\Transport\Response;
use AnvilFeed\Transport\Transport;

/**
 * The test double every sender test drives. It lives here rather than beside a test class (ruling P6) so
 * that the Laravel adapter's tests can drive the same sender without copying it — a second copy is how the
 * two suites start disagreeing about what the transport contract is.
 *
 * The scripted answers are consumed in order. Once they run out, every further call is answered the way
 * the ingest answers a healthy batch: `202` naming **every id that was posted** in `accepted`. That
 * default is not a convenience — a filler `{"accepted":[],"rejected":[]}` would be a response the real
 * server never sends, and the sender is required to notice exactly that (an event with no verdict is
 * retried once and then dropped).
 */
final class RecordingTransport implements Transport
{
    /** @var list<array{url: string, body: string, timeout_ms: ?int}> */
    public array $calls = [];

    /** @var list<Response> */
    private array $answers;

    public function __construct(Response ...$answers)
    {
        $this->answers = array_values($answers);
    }

    public function post(string $url, string $body, ?int $timeoutMs = null): Response
    {
        $this->calls[] = ['url' => $url, 'body' => $body, 'timeout_ms' => $timeoutMs];

        return array_shift($this->answers) ?? new Response(202, self::acceptEverything($body));
    }

    /** @return list<?int> */
    public function budgets(): array
    {
        return array_map(static fn (array $call): ?int => $call['timeout_ms'], $this->calls);
    }

    /** The answer a healthy ingest gives to this exact body. */
    public static function acceptEverything(string $body): string
    {
        $decoded = json_decode($body, true);
        $events = is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];
        $accepted = [];
        foreach ($events as $event) {
            if (is_array($event) && is_string($event['event_id'] ?? null)) {
                $accepted[] = $event['event_id'];
            }
        }

        return (string) json_encode(['accepted' => $accepted, 'rejected' => []]);
    }
}
