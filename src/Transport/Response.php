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
    /**
     * Anything that looks like a credential, in a message that is about to be logged (C15, FR-9 e). The
     * ORDER is the contract, not a detail: `Authorization\s*:?.*` swallows the rest of its line, so a
     * `Basic …` that is NOT on an Authorization line has to be caught before it.
     *
     * The list is `IngestResponse.redact`'s, pattern for pattern (review finding 10). The two that used
     * to be Java's alone:
     *
     *  - a proxy URL's Basic credentials, `http://user:secret@proxy:3128` — the shape C15/X11 name;
     *  - a BARE `Basic dXNlcjpwdw==`. Basic transmits the password merely Base64-encoded, so that string
     *    IS the credential rather than a hint of one, and only `Proxy-Authorization: Basic …` was covered
     *    before — and then only by the accident that it contains the substring `Authorization`.
     */
    private const CREDENTIAL_PATTERNS = [
        '#(//)[^/@\s]+:[^/@\s]*@#' => '$1[Filtered]@',
        '/anv_(srv|pub)_[A-Za-z0-9]+/' => 'anv_$1_[Filtered]',
        '#\bBasic\s+[A-Za-z0-9+/]{8,}={0,2}#i' => 'Basic [Filtered]',
        '/Authorization\s*:?.*/is' => 'Authorization: [Filtered]',
    ];

    /**
     * The `Retry-After` header, delta-seconds only. RFC 9110 also allows an HTTP-date, which this ingest
     * never sends (`Retry-After: 60` and `Retry-After: 30` are literals in AnvilFeedIngestController); an
     * unreadable value becomes null and the sender falls back to its documented default rather than to 0,
     * which would be a hot loop against a rate limiter.
     *
     * The LENGTH CAP is `HttpTransport.retryAfter`'s, verbatim (review finding 8). Without it a proxy
     * answering `Retry-After: 99999999999999999999` saturates the cast to PHP_INT_MAX seconds and
     * `Sender::pause` mutes a long-lived worker for the rest of its life; nine digits is thirty-one
     * years, and anything longer is a defect in whatever wrote the header, not a window.
     *
     * It lives here rather than in a transport because BOTH transports read that header, and a cap that
     * holds on one of them is the drift C9/C17 forbid.
     */
    public static function retryAfterSeconds(?string $raw): ?int
    {
        $value = $raw === null ? '' : trim($raw);

        return $value !== '' && ctype_digit($value) && strlen($value) <= 9 ? (int) $value : null;
    }

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
