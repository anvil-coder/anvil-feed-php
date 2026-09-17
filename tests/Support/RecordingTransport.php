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
 * The answers are consumed in order; once they run out, every further call is answered with a bare 202.
 * That default is deliberate: a test that specifies three answers and provokes a fourth request should see
 * the fourth as "sent and accepted", not as a mysterious null.
 */
final class RecordingTransport implements Transport
{
    /** @var list<array{url: string, body: string}> */
    public array $calls = [];

    /** @var list<Response> */
    private array $answers;

    public function __construct(Response ...$answers)
    {
        $this->answers = array_values($answers);
    }

    public function post(string $url, string $body): Response
    {
        $this->calls[] = ['url' => $url, 'body' => $body];

        return array_shift($this->answers) ?? new Response(202, '{"accepted":[],"rejected":[]}');
    }
}
