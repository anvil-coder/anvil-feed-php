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
    /** `Bearer anv_srv_…` in any message that is about to be logged. */
    private const CREDENTIAL_PATTERNS = [
        '/Authorization\s*:?.*/is' => 'Authorization: [Filtered]',
        '/anv_(srv|pub)_[A-Za-z0-9]+/' => 'anv_$1_[Filtered]',
    ];

    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $transportFailure = false,
        public readonly string $failureReason = '',
        public readonly ?string $location = null,
    ) {
    }

    /**
     * The reason is REDACTED here rather than at the log call, so that the tainted string never exists on
     * the object at all. The message comes from the host application's own HTTP client: Guzzle and
     * Symfony do not put request headers in theirs, but a host's debugging decorator may dump the whole
     * request, and that would put `Authorization: Bearer anv_srv_…` into the customer's log against
     * FR-9 (e). The SDK's promise has to be the SDK's own, not every host's.
     */
    public static function failure(string $reason): self
    {
        return new self(0, '', null, true, self::redact($reason));
    }

    public static function redact(string $text): string
    {
        foreach (self::CREDENTIAL_PATTERNS as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }
}
