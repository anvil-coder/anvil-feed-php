<?php

declare(strict_types=1);

namespace AnvilFeed\Tests;

use AnvilFeed\Config;
use AnvilFeed\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The configuration is the one part of this SDK a customer edits, and every mistake it can make is silent:
 * a missing token means no incidents, an environment the ingest cannot bind on means no incidents, and both
 * look exactly like "nothing ever broke". So each of those paths is pinned here, together with every
 * default — a default that drifts between the PHP and the Java core is a semantic drift (C9, C17), not a
 * detail.
 */
final class ConfigTest extends TestCase
{
    private const ENV_KEYS = [
        'ANVIL_INGEST_ENDPOINT', 'ANVIL_INGEST_TOKEN', 'ANVIL_SERVICE', 'ANVIL_ENVIRONMENT', 'ANVIL_RELEASE',
    ];

    /** @var array<string, string|false> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->savedEnvironment[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
    }

    /** @return array{0: Logger, 1: list<string>} a logger and the list its delegate fills */
    private function recordingLogger(): array
    {
        $lines = new \ArrayObject();
        $delegate = new class ($lines) extends AbstractLogger {
            public function __construct(private readonly \ArrayObject $lines)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines->append($level . ': ' . $message);
            }
        };

        return [new Logger($delegate), $lines];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function valid(array $options = []): array
    {
        [$logger, $lines] = $this->recordingLogger();

        return [
            Config::fromArray(array_merge([
                'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
                'service' => 'disponio-api',
            ], $options), $logger),
            $lines,
        ];
    }

    // ---------------------------------------------------------------- defaults

    /** Every key, every default. This is the table the Java core is compared against. */
    public function testEveryDefault(): void
    {
        [$config] = $this->valid();

        self::assertSame('https://app.anvil-coder.tech/api/feed', $config->endpoint);
        self::assertSame('https://app.anvil-coder.tech/api/feed', Config::DEFAULT_ENDPOINT);
        self::assertSame('anv_srv_0123456789abcdef0123456789abcdef', $config->token);
        self::assertSame('disponio-api', $config->service);
        self::assertSame('production', $config->environment);
        self::assertNull($config->release);
        self::assertSame(1000, $config->maxQueueSize);
        self::assertSame(2000, $config->flushIntervalMs);
        self::assertSame(50, $config->batchSize);
        self::assertSame(1000, $config->connectTimeoutMs);
        self::assertSame(2000, $config->requestTimeoutMs);
        self::assertSame(50, $config->maxFrames);
        self::assertSame(20, $config->contextLines);
        self::assertSame(400, $config->maxContextLineChars);
        self::assertSame(
            ['password', 'token', 'secret', 'authorization', 'api_key', 'cookie', 'email', 'credit_card', 'iban'],
            $config->denylist
        );
        self::assertSame(Config::DEFAULT_DENYLIST, $config->denylist);
        self::assertTrue($config->enabled);
        self::assertSame(10, $config->fingerprintCap);
        self::assertSame(60000, $config->fingerprintWindowMs);
        self::assertSame(['/api/feed/**'], $config->ignoredRequestPaths);
    }

    public function testEveryValueCanBeOverridden(): void
    {
        [$config] = $this->valid([
            'endpoint' => 'https://feed.internal/api/feed/',
            'environment' => 'staging',
            'release' => '2026.9.3',
            'max_queue_size' => 250,
            'flush_interval_ms' => 500,
            'batch_size' => 10,
            'connect_timeout_ms' => 300,
            'request_timeout_ms' => 900,
            'max_frames' => 25,
            'context_lines' => 6,
            'max_context_line_chars' => 120,
            'denylist' => ['pin'],
            'fingerprint_cap' => 3,
            'fingerprint_window_ms' => 5000,
            'ignored_request_paths' => ['/health'],
        ]);

        self::assertSame('https://feed.internal/api/feed', $config->endpoint);   // the trailing slash goes
        self::assertSame('staging', $config->environment);
        self::assertSame('2026.9.3', $config->release);
        self::assertSame(250, $config->maxQueueSize);
        self::assertSame(500, $config->flushIntervalMs);
        self::assertSame(10, $config->batchSize);
        self::assertSame(300, $config->connectTimeoutMs);
        self::assertSame(900, $config->requestTimeoutMs);
        self::assertSame(25, $config->maxFrames);
        self::assertSame(6, $config->contextLines);
        self::assertSame(120, $config->maxContextLineChars);
        self::assertSame(['pin'], $config->denylist);
        self::assertSame(3, $config->fingerprintCap);
        self::assertSame(5000, $config->fingerprintWindowMs);
        self::assertSame(['/health'], $config->ignoredRequestPaths);
    }

    /** The four knobs the ingest also bounds. A value above the ceiling is clamped, never sent. */
    public function testTheBoundedKnobsAreClampedToTheIngestsOwnLimits(): void
    {
        [$high] = $this->valid([
            'batch_size' => 5000, 'max_frames' => 5000, 'context_lines' => 5000, 'max_context_line_chars' => 5000,
        ]);
        [$low] = $this->valid([
            'batch_size' => 0, 'max_frames' => 0, 'context_lines' => -1, 'max_context_line_chars' => 0,
        ]);

        self::assertSame([50, 50, 20, 400], [$high->batchSize, $high->maxFrames, $high->contextLines,
            $high->maxContextLineChars]);
        self::assertSame([1, 1, 0, 1], [$low->batchSize, $low->maxFrames, $low->contextLines,
            $low->maxContextLineChars]);
    }

    public function testTheTwoIngestUrls(): void
    {
        [$config] = $this->valid(['endpoint' => 'https://feed.internal/api/feed/']);

        self::assertSame('https://feed.internal/api/feed/events', $config->eventsUrl());
        self::assertSame('https://feed.internal/api/feed/events/batch', $config->batchUrl());
    }

    // ------------------------------------------------------- the environment repair

    /** "Prod_EU" is what a .env file really holds; the ingest would answer schema_violation to it. */
    public function testAnEnvironmentIsRepairedToTheIngestsBindingPatternAndSaidOnce(): void
    {
        [$config, $lines] = $this->valid(['environment' => 'Prod_EU']);

        self::assertSame('prod-eu', $config->environment);
        self::assertMatchesRegularExpression(Config::ENVIRONMENT_PATTERN, $config->environment);
        self::assertTrue($config->enabled);
        self::assertCount(1, $lines);
        self::assertStringContainsString('was sent as "prod-eu"', $lines[0]);
    }

    public function testTheRepairIsNotAnnouncedWhenThereWasNothingToRepair(): void
    {
        [$config, $lines] = $this->valid(['environment' => 'production']);

        self::assertSame('production', $config->environment);
        self::assertSame([], $lines->getArrayCopy());
    }

    /**
     * Nothing survives the repair here: an environment of digits alone cannot start with a letter. The SDK
     * goes quiet rather than posting events no binding will ever match.
     */
    public function testAnUnrepairableEnvironmentDisablesTheClientAndNamesTheRule(): void
    {
        [$config, $lines] = $this->valid(['environment' => '2026']);

        self::assertFalse($config->enabled);
        self::assertCount(1, $lines);
        self::assertStringContainsString('cannot be made to match', $lines[0]);
        self::assertStringContainsString('start with a lower-case letter', $lines[0]);
    }

    public function testAnEmptyEnvironmentDisablesTheClient(): void
    {
        [$config, $lines] = $this->valid(['environment' => '   ']);

        self::assertFalse($config->enabled);
        self::assertCount(1, $lines);
    }

    // ------------------------------------------------------------ token and service

    /** One line, at init, and never again. FR-2 b: not one per capture. */
    public function testAMissingTokenDisablesTheClientWithASingleLine(): void
    {
        [$logger, $lines] = $this->recordingLogger();
        $config = Config::fromArray(['service' => 'disponio-api'], $logger);

        self::assertNull($config->token);
        self::assertFalse($config->enabled);
        self::assertCount(1, $lines);
        self::assertStringContainsString('ANVIL_INGEST_TOKEN', $lines[0]);

        // A second Config built on the same logger says nothing more.
        Config::fromArray(['service' => 'disponio-api'], $logger);
        self::assertCount(1, $lines);
    }

    public function testABlankTokenIsTheSameAsNoTokenAtAll(): void
    {
        [$logger, $lines] = $this->recordingLogger();
        $config = Config::fromArray(['token' => '   ', 'service' => 'disponio-api'], $logger);

        self::assertNull($config->token);
        self::assertFalse($config->enabled);
        self::assertCount(1, $lines);
    }

    public function testAMissingServiceDisablesTheClient(): void
    {
        [$logger, $lines] = $this->recordingLogger();
        $config = Config::fromArray(['token' => 'anv_srv_x'], $logger);

        self::assertSame('unknown-service', $config->service);
        self::assertFalse($config->enabled);
        self::assertStringContainsString('no service name configured', $lines[0]);
    }

    public function testEnabledFalseIsHonouredEvenWhenEverythingElseIsFine(): void
    {
        [$config] = $this->valid(['enabled' => false]);

        self::assertFalse($config->enabled);
    }

    // ------------------------------------------------------------- the environment

    public function testTheFiveEnvironmentVariablesAreRead(): void
    {
        putenv('ANVIL_INGEST_ENDPOINT=https://feed.internal/api/feed');
        putenv('ANVIL_INGEST_TOKEN=anv_srv_fromenv');
        putenv('ANVIL_SERVICE=svc-from-env');
        putenv('ANVIL_ENVIRONMENT=staging');
        putenv('ANVIL_RELEASE=2026.9.3');

        [$logger] = $this->recordingLogger();
        $config = Config::fromEnvironment([], $logger);

        self::assertSame('https://feed.internal/api/feed', $config->endpoint);
        self::assertSame('anv_srv_fromenv', $config->token);
        self::assertSame('svc-from-env', $config->service);
        self::assertSame('staging', $config->environment);
        self::assertSame('2026.9.3', $config->release);
        self::assertTrue($config->enabled);
    }

    /** An explicit option is the operator's last word; the environment only fills what was left out. */
    public function testAnExplicitOverrideBeatsTheEnvironmentVariable(): void
    {
        putenv('ANVIL_INGEST_TOKEN=anv_srv_fromenv');
        putenv('ANVIL_SERVICE=svc-from-env');
        putenv('ANVIL_ENVIRONMENT=staging');

        [$logger] = $this->recordingLogger();
        $config = Config::fromEnvironment(['service' => 'svc-explicit'], $logger);

        self::assertSame('svc-explicit', $config->service);
        self::assertSame('anv_srv_fromenv', $config->token);   // untouched, so still read from the environment
        self::assertSame('staging', $config->environment);
    }

    /** An exported-but-empty variable is how a .env file spells "not set"; it must not win over a default. */
    public function testAnEmptyEnvironmentVariableCountsAsUnset(): void
    {
        putenv('ANVIL_INGEST_TOKEN=anv_srv_fromenv');
        putenv('ANVIL_SERVICE=svc-from-env');
        putenv('ANVIL_INGEST_ENDPOINT=');
        putenv('ANVIL_RELEASE=');

        [$logger] = $this->recordingLogger();
        $config = Config::fromEnvironment([], $logger);

        self::assertSame(Config::DEFAULT_ENDPOINT, $config->endpoint);
        self::assertNull($config->release);
    }

    public function testWithNoEnvironmentAtAllTheClientIsDisabled(): void
    {
        [$logger, $lines] = $this->recordingLogger();
        $config = Config::fromEnvironment([], $logger);

        self::assertNull($config->token);
        self::assertSame('unknown-service', $config->service);
        self::assertFalse($config->enabled);
        self::assertCount(2, $lines);   // one for the service, one for the token; neither repeats
    }
}
