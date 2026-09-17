<?php

declare(strict_types=1);

namespace AnvilFeed\Transport;

/** One POST to the configured ingest and nothing else. It never throws (FR-2 b). */
interface Transport
{
    public function post(string $url, string $body): Response;
}
