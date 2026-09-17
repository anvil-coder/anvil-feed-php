<?php

declare(strict_types=1);

namespace AnvilFeed\Transport;

/**
 * What came back. A transport failure — DNS, connection refused, a timeout — is deliberately NOT modelled
 * as a status code: it is the case the sender must treat like a 5xx, and giving it a fake 0 or 599 would
 * put it one typo away from being treated like a 4xx and dropping the customer's errors on the floor.
 */
final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $transportFailure = false,
        public readonly string $failureReason = '',
    ) {
    }

    public static function failure(string $reason): self
    {
        return new self(0, '', null, true, $reason);
    }
}
