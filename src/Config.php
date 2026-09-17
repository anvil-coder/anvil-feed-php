<?php

declare(strict_types=1);

namespace AnvilFeed;

/**
 * Every configuration name and every default the Anvil Feed SDKs know. The names and the values are
 * identical to the Java core's AnvilFeedConfig, and a test in each language compares them against the
 * same table (C9, C17): a knob that exists in one language only is a semantic drift, not a convenience.
 */
final class Config
{
    public const DEFAULT_ENDPOINT = 'https://app.anvil-coder.tech/api/feed';

    /** FR-5 a. */
    public const DEFAULT_DENYLIST = [
        'password', 'token', 'secret', 'authorization', 'api_key', 'cookie', 'email', 'credit_card', 'iban',
    ];

    /** The environment the ingest binds on: trimmed, lower-cased, and then exactly this pattern. */
    public const ENVIRONMENT_PATTERN = '/^[a-z][a-z0-9-]{0,63}$/';

    /**
     * schema 1.0 bounds the service name and the ingest bounds it nowhere. An over-long name is therefore
     * accepted by the server and refused by the contract, which a customer only ever sees as an empty inbox.
     */
    public const MAX_SERVICE_CHARS = 128;

    private function __construct(
        public readonly string $endpoint,
        public readonly ?string $token,
        public readonly string $service,
        public readonly string $environment,
        public readonly ?string $release,
        public readonly int $maxQueueSize,
        public readonly int $flushIntervalMs,
        public readonly int $batchSize,
        public readonly int $connectTimeoutMs,
        public readonly int $requestTimeoutMs,
        public readonly int $maxFrames,
        public readonly int $contextLines,
        public readonly int $maxContextLineChars,
        public readonly array $denylist,
        public readonly bool $enabled,
        public readonly int $fingerprintCap,
        public readonly int $fingerprintWindowMs,
        public readonly array $ignoredRequestPaths,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options, ?Logger $logger = null): self
    {
        $logger ??= new Logger();

        $token = self::stringOrNull($options['token'] ?? null);
        $service = trim((string) ($options['service'] ?? ''));
        if ($service === '') {
            $service = 'unknown-service';
            $logger->once('service', 'anvil-feed: no service name configured; sending is disabled.');
        }

        if (mb_strlen($service) > self::MAX_SERVICE_CHARS) {
            $bounded = mb_substr($service, 0, self::MAX_SERVICE_CHARS);
            $logger->once('service-length', sprintf(
                'anvil-feed: the service name is longer than %d characters and was sent as "%s". '
                . 'Configure it that way to avoid the rewrite.',
                self::MAX_SERVICE_CHARS,
                $bounded
            ));
            $service = $bounded;
        }

        $environment = self::normalizeEnvironment((string) ($options['environment'] ?? 'production'), $logger);

        $enabled = (bool) ($options['enabled'] ?? true);
        if ($token === null) {
            // Silent, once, at init. A library that logs on every capture because a token is missing is
            // a library somebody switches off entirely.
            $logger->once('token', 'anvil-feed: no ingest token (ANVIL_INGEST_TOKEN); the SDK is disabled.');
            $enabled = false;
        }
        if ($environment === null || $service === 'unknown-service') {
            $enabled = false;
        }

        return new self(
            endpoint: rtrim((string) ($options['endpoint'] ?? self::DEFAULT_ENDPOINT), '/'),
            token: $token,
            service: $service,
            environment: $environment ?? 'production',
            release: self::stringOrNull($options['release'] ?? null),
            maxQueueSize: (int) ($options['max_queue_size'] ?? 1000),
            flushIntervalMs: (int) ($options['flush_interval_ms'] ?? 2000),
            batchSize: min(50, max(1, (int) ($options['batch_size'] ?? 50))),
            connectTimeoutMs: (int) ($options['connect_timeout_ms'] ?? 1000),
            requestTimeoutMs: (int) ($options['request_timeout_ms'] ?? 2000),
            maxFrames: min(50, max(1, (int) ($options['max_frames'] ?? 50))),
            contextLines: min(20, max(0, (int) ($options['context_lines'] ?? 20))),
            maxContextLineChars: min(400, max(1, (int) ($options['max_context_line_chars'] ?? 400))),
            denylist: $options['denylist'] ?? self::DEFAULT_DENYLIST,
            enabled: $enabled,
            fingerprintCap: (int) ($options['fingerprint_cap'] ?? 10),
            fingerprintWindowMs: (int) ($options['fingerprint_window_ms'] ?? 60000),
            ignoredRequestPaths: $options['ignored_request_paths'] ?? ['/api/feed/**'],
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function fromEnvironment(array $overrides = [], ?Logger $logger = null): self
    {
        $fromEnv = [
            'endpoint' => self::stringOrNull(getenv('ANVIL_INGEST_ENDPOINT') ?: null),
            'token' => self::stringOrNull(getenv('ANVIL_INGEST_TOKEN') ?: null),
            'service' => self::stringOrNull(getenv('ANVIL_SERVICE') ?: null),
            'environment' => self::stringOrNull(getenv('ANVIL_ENVIRONMENT') ?: null),
            'release' => self::stringOrNull(getenv('ANVIL_RELEASE') ?: null),
        ];

        return self::fromArray(array_merge(array_filter($fromEnv, static fn ($v) => $v !== null), $overrides), $logger);
    }

    public function eventsUrl(): string
    {
        return $this->endpoint . '/events';
    }

    public function batchUrl(): string
    {
        return $this->endpoint . '/events/batch';
    }

    /**
     * The ingest trims, lower-cases and then applies ENVIRONMENT_PATTERN. Anything else is a per-event
     * schema_violation, which a customer would only ever discover by finding no incidents at all. So the
     * value is repaired here, once, loudly — and where it cannot be repaired, sending is switched off with
     * a message that names the rule rather than the symptom.
     */
    private static function normalizeEnvironment(string $raw, Logger $logger): ?string
    {
        $normalized = strtolower(trim($raw));
        $repaired = preg_replace('/[^a-z0-9-]+/', '-', $normalized) ?? '';
        $repaired = ltrim($repaired, '-0123456789');
        $repaired = substr($repaired, 0, 64);

        if ($repaired === '' || preg_match(self::ENVIRONMENT_PATTERN, $repaired) !== 1) {
            $logger->once('environment', sprintf(
                'anvil-feed: environment "%s" cannot be made to match %s; the SDK is disabled. '
                . 'An environment must start with a lower-case letter and hold only a-z, 0-9 and "-".',
                $raw,
                self::ENVIRONMENT_PATTERN
            ));

            return null;
        }
        if ($repaired !== $normalized) {
            $logger->once('environment', sprintf(
                'anvil-feed: environment "%s" is not a valid binding key and was sent as "%s". '
                . 'Configure it that way to avoid the rewrite.',
                $raw,
                $repaired
            ));
        }

        return $repaired;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
