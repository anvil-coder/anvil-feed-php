<?php

declare(strict_types=1);

namespace AnvilFeed\Transport;

/**
 * One POST to the configured ingest and nothing else. It never throws (FR-2 b), it never follows a
 * redirect (the token is presented to the configured endpoint and to nothing else, FR-9), and it never
 * runs longer than the budget it is given.
 */
interface Transport
{
    /**
     * @param ?int $timeoutMs The total wall-clock budget for this one call — connect, write and read
     *                        together. Null means the configured `request_timeout_ms`. The Sender passes
     *                        what is left of its flush deadline, so that FR-2 (c)'s hard 2 s bound holds
     *                        DURING a request and not only between two of them.
     */
    public function post(string $url, string $body, ?int $timeoutMs = null): Response;
}
