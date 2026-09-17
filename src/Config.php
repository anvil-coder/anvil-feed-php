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
        // Keyed 'service' in both branches on purpose: a name that is missing and a name that is the
        // wrong type are the same outcome for the operator — nothing is sent — and they must not produce
        // two lines between them.
        $service = is_string($options['service'] ?? null) ? trim($options['service']) : '';
        if ($service === '') {
            $logger->once('service', array_key_exists('service', $options) && $options['service'] !== null
                && !is_string($options['service'])
                    ? sprintf(
                        'anvil-feed: the service name must be a string but is %s; sending is disabled.',
                        get_debug_type($options['service'])
                    )
                    : 'anvil-feed: no service name configured; sending is disabled.');
            $service = 'unknown-service';
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

        $environment = self::normalizeEnvironment(
            self::stringOption($options, 'environment', 'production', $logger),
            $logger
        );

        $enabled = self::boolOption($options, 'enabled', true);
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
            endpoint: rtrim(self::stringOption($options, 'endpoint', self::DEFAULT_ENDPOINT, $logger), '/'),
            token: $token,
            service: $service,
            environment: $environment ?? 'production',
            release: self::stringOrNull($options['release'] ?? null),
            maxQueueSize: self::atLeastOne(
                self::intOption($options, 'max_queue_size', 1000, $logger),
                'max_queue_size',
                $logger
            ),
            flushIntervalMs: self::intOption($options, 'flush_interval_ms', 2000, $logger),
            batchSize: min(50, max(1, self::intOption($options, 'batch_size', 50, $logger))),
            connectTimeoutMs: self::intOption($options, 'connect_timeout_ms', 1000, $logger),
            requestTimeoutMs: self::intOption($options, 'request_timeout_ms', 2000, $logger),
            maxFrames: min(50, max(1, self::intOption($options, 'max_frames', 50, $logger))),
            contextLines: min(20, max(0, self::intOption($options, 'context_lines', 20, $logger))),
            maxContextLineChars:
                min(400, max(1, self::intOption($options, 'max_context_line_chars', 400, $logger))),
            denylist: self::listOption($options, 'denylist', self::DEFAULT_DENYLIST, $logger),
            enabled: $enabled,
            fingerprintCap: self::intOption($options, 'fingerprint_cap', 10, $logger),
            fingerprintWindowMs: self::intOption($options, 'fingerprint_window_ms', 60000, $logger),
            ignoredRequestPaths:
                self::listOption($options, 'ignored_request_paths', ['/api/feed/**'], $logger),
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

    /**
     * FR-2 b, at the one door a customer actually touches. Everything below is here because
     * config/anvil-feed.php is written by hand and read through env(), which answers a string for a value
     * the author meant as a list and null for one they meant as a number.
     *
     * Two failure modes are ruled out entirely, not softened:
     *   - a TypeError out of the promoted constructor (`'denylist' => env('ANVIL_DENYLIST')` yields a
     *     string, and the SDK took down the host's boot);
     *   - a PHP warning, because Laravel's error handler converts warnings into thrown ErrorExceptions.
     *     That is why there is not one `(string)` or `(int)` cast on an unchecked value left in this file.
     *
     * A value that cannot be read becomes the default and says so once, keyed per option, so a
     * misconfiguration is visible exactly once at boot rather than never or on every capture.
     *
     * @param array<string, mixed> $options
     */
    private static function stringOption(array $options, string $key, string $default, Logger $logger): string
    {
        if (!array_key_exists($key, $options) || $options[$key] === null) {
            return $default;
        }
        if (is_string($options[$key])) {
            return trim($options[$key]);
        }
        self::sayItIsUnreadable($key, $options[$key], 'a string', $default, $logger);

        return $default;
    }

    /** @param array<string, mixed> $options */
    private static function intOption(array $options, string $key, int $default, Logger $logger): int
    {
        if (!array_key_exists($key, $options) || $options[$key] === null) {
            return $default;
        }
        $value = $options[$key];
        if (is_int($value)) {
            return $value;
        }
        // "1000" out of an .env file is a number a human wrote; "abc" is not, and silently reading it as
        // zero would switch a timeout off without telling anybody.
        if (is_float($value) && is_finite($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (int) (float) trim($value);
        }
        self::sayItIsUnreadable($key, $value, 'a whole number', (string) $default, $logger);

        return $default;
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $default
     * @return list<string>
     */
    private static function listOption(array $options, string $key, array $default, Logger $logger): array
    {
        if (!array_key_exists($key, $options) || $options[$key] === null) {
            return $default;
        }
        $value = $options[$key];
        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($entry) => is_string($entry)));
        }
        // The env() case: one value where a list was meant. Wrapped rather than dropped, because a single
        // term is what the author wrote and dropping it would leave them believing it is in force.
        if (is_string($value) && trim($value) !== '') {
            $logger->once('option:' . $key, sprintf(
                'anvil-feed: "%s" is a list of strings; the single value "%s" was wrapped into one. '
                . 'Configure it as an array to avoid the rewrite.',
                $key,
                trim($value)
            ));

            return [trim($value)];
        }
        self::sayItIsUnreadable($key, $value, 'a list of strings', 'the default', $logger);

        return $default;
    }

    /** @param array<string, mixed> $options */
    private static function boolOption(array $options, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $options) || $options[$key] === null) {
            return $default;
        }
        if (is_bool($options[$key])) {
            return $options[$key];
        }
        // "false" out of an .env file is a string, and (bool) "false" is true — which would silently
        // switch the SDK back ON for somebody who had switched it off.
        return filter_var($options[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * A queue bound of zero or less is not a smaller queue, it is a queue that drops every event it is
     * given and writes a warning about each — a log flood on the one code path that exists to prevent
     * one. Clamped, and said once, the way the environment repair is.
     */
    private static function atLeastOne(int $value, string $key, Logger $logger): int
    {
        if ($value >= 1) {
            return $value;
        }
        $logger->once('clamp:' . $key, sprintf(
            'anvil-feed: "%s" is %d; 1 is used instead — a bound below one would drop every event and '
            . 'write a warning about each.',
            $key,
            $value
        ));

        return 1;
    }

    private static function sayItIsUnreadable(
        string $key,
        mixed $value,
        string $expected,
        string $fallback,
        Logger $logger,
    ): void {
        $logger->once('option:' . $key, sprintf(
            'anvil-feed: "%s" must be %s but is %s; %s is used instead.',
            $key,
            $expected,
            get_debug_type($value),
            $fallback === '' ? 'the default' : $fallback
        ));
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
