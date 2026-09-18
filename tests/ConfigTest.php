<?php

declare(strict_types=1);

namespace AnvilFeed\Tests;

use AnvilFeed\Config;
use AnvilFeed\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * schema 1.0 stops at 128 and the ingest stops nowhere, so an over-long name is the quiet kind of
     * mistake: accepted by the server, refused by the contract, visible only as an empty inbox.
     */
    public function testAnOverlongServiceNameIsBoundedAndSaidOnce(): void
    {
        [$logger, $lines] = $this->recordingLogger();
        $config = Config::fromArray(
            ['token' => 'anv_srv_x', 'service' => str_repeat('a', 300)],
            $logger
        );

        self::assertSame(128, mb_strlen($config->service));
        self::assertSame(Config::MAX_SERVICE_CHARS, mb_strlen($config->service));
        self::assertTrue($config->enabled);
        self::assertCount(1, $lines);
        self::assertStringContainsString('longer than 128 characters', $lines[0]);
    }

    public function testAServiceNameExactlyAtTheBoundIsLeftAlone(): void
    {
        [$logger, $lines] = $this->recordingLogger();
        $config = Config::fromArray(
            ['token' => 'anv_srv_x', 'service' => str_repeat('a', 128)],
            $logger
        );

        self::assertSame(128, mb_strlen($config->service));
        self::assertSame([], $lines->getArrayCopy());
    }

    public function testEnabledFalseIsHonouredEvenWhenEverythingElseIsFine(): void
    {
        [$config] = $this->valid(['enabled' => false]);

        self::assertFalse($config->enabled);
    }

    // ------------------------------------------------- FR-2 b: fromArray() is total

    /**
     * `'denylist' => env('ANVIL_DENYLIST')` answers a STRING, and the promoted constructor parameter is
     * typed array — so this exact line used to raise a TypeError out of the SDK and take the host's boot
     * down with it. FR-2 b says no error inside this SDK ever reaches the caller; a config file a customer
     * wrote by hand is precisely where that promise is tested.
     */
    public function testASingleValueWhereAListWasMeantIsWrappedRatherThanThrown(): void
    {
        [$logger, $lines] = $this->recordingLogger();
        $config = Config::fromArray(
            ['token' => 'anv_srv_x', 'service' => 'svc', 'denylist' => 'passphrase',
                'ignored_request_paths' => '/health'],
            $logger
        );

        self::assertSame(['passphrase'], $config->denylist);
        self::assertSame(['/health'], $config->ignoredRequestPaths);
        self::assertCount(2, $lines);
        self::assertStringContainsString('"denylist" is a list of strings', $lines[0]);
        self::assertStringContainsString('"ignored_request_paths" is a list of strings', $lines[1]);
    }

    public function testAListOptionThatCannotBeReadAtAllFallsBackToItsDefault(): void
    {
        [$logger, $lines] = $this->recordingLogger();
        $config = Config::fromArray(
            ['token' => 'anv_srv_x', 'service' => 'svc', 'denylist' => 42],
            $logger
        );

        self::assertSame(Config::DEFAULT_DENYLIST, $config->denylist);
        self::assertCount(1, $lines);
        self::assertStringContainsString('must be a list of strings but is int', $lines[0]);
    }

    /** A list with rubbish in it keeps the usable entries; Scrubber must never be handed a non-string. */
    public function testNonStringEntriesAreDroppedFromAListOption(): void
    {
        [$logger] = $this->recordingLogger();
        $config = Config::fromArray(
            ['token' => 'anv_srv_x', 'service' => 'svc', 'denylist' => ['token', ['nested'], 7, 'iban']],
            $logger
        );

        self::assertSame(['token', 'iban'], $config->denylist);
    }

    /**
     * `trim((string) $value)` on an array is a PHP warning, and Laravel installs an error handler that
     * turns a warning into a thrown ErrorException — so on the one host this SDK is built for, the warning
     * IS a throw into the caller. Nothing here may raise one.
     */
    public function testANonStringServiceDisablesTheClientWithOneLineAndNoWarning(): void
    {
        [$logger, $lines] = $this->recordingLogger();
        $config = Config::fromArray(['token' => 'anv_srv_x', 'service' => ['svc']], $logger);

        self::assertSame('unknown-service', $config->service);
        self::assertFalse($config->enabled);
        self::assertCount(1, $lines);
        self::assertStringContainsString('must be a string but is array', $lines[0]);
    }

    public function testANonStringEndpointOrEnvironmentFallsBackAndSaysSo(): void
    {
        [$logger, $lines] = $this->recordingLogger();
        $config = Config::fromArray(
            ['token' => 'anv_srv_x', 'service' => 'svc', 'endpoint' => ['https://x'], 'environment' => 7],
            $logger
        );

        self::assertSame(Config::DEFAULT_ENDPOINT, $config->endpoint);
        self::assertSame('production', $config->environment);
        self::assertCount(2, $lines);
    }

    /** "abc" read as 0 would switch a timeout off without telling anybody. */
    public function testANumberThatIsNotANumberFallsBackToItsDefault(): void
    {
        [$logger, $lines] = $this->recordingLogger();
        $config = Config::fromArray(
            ['token' => 'anv_srv_x', 'service' => 'svc', 'request_timeout_ms' => 'abc',
                'max_queue_size' => []],
            $logger
        );

        self::assertSame(2000, $config->requestTimeoutMs);
        self::assertSame(1000, $config->maxQueueSize);
        self::assertCount(2, $lines);
        $said = implode(' | ', $lines->getArrayCopy());
        self::assertStringContainsString('"request_timeout_ms" must be a whole number but is string', $said);
        self::assertStringContainsString('"max_queue_size" must be a whole number but is array', $said);
    }

    /** A number out of an .env file is a string, and that one IS readable. */
    public function testANumericStringIsReadAsTheNumberItIs(): void
    {
        [$logger, $lines] = $this->recordingLogger();
        $config = Config::fromArray(
            ['token' => 'anv_srv_x', 'service' => 'svc', 'request_timeout_ms' => '250',
                'max_queue_size' => ' 40 ', 'batch_size' => 10.0],
            $logger
        );

        self::assertSame(250, $config->requestTimeoutMs);
        self::assertSame(40, $config->maxQueueSize);
        self::assertSame(10, $config->batchSize);
        self::assertSame([], $lines->getArrayCopy());
    }

    /** (bool) "false" is true, which would switch the SDK back on for somebody who switched it off. */
    public function testTheStringFalseSwitchesTheClientOff(): void
    {
        foreach (['false', '0', 'off', 'no'] as $spelling) {
            [$logger] = $this->recordingLogger();
            $config = Config::fromArray(
                ['token' => 'anv_srv_x', 'service' => 'svc', 'enabled' => $spelling],
                $logger
            );

            self::assertFalse($config->enabled, $spelling);
        }

        [$logger] = $this->recordingLogger();
        self::assertTrue(
            Config::fromArray(['token' => 'anv_srv_x', 'service' => 'svc', 'enabled' => 'true'], $logger)
                ->enabled
        );
    }

    /**
     * The whole door at once: every option the wrong type, nothing raised, and a Config that is still a
     * Config. If this ever throws, a customer's application does not boot.
     */
    public function testAConfigurationOfPureRubbishStillProducesAConfig(): void
    {
        [$logger] = $this->recordingLogger();
        $config = Config::fromArray([
            'endpoint' => ['x'], 'token' => ['x'], 'service' => 1.5, 'environment' => new \stdClass(),
            'release' => [], 'max_queue_size' => 'x', 'flush_interval_ms' => [], 'batch_size' => null,
            'connect_timeout_ms' => new \stdClass(), 'request_timeout_ms' => 'x', 'max_frames' => [],
            'context_lines' => 'x', 'max_context_line_chars' => [], 'denylist' => new \stdClass(),
            'enabled' => [], 'fingerprint_cap' => 'x', 'fingerprint_window_ms' => [],
            'ignored_request_paths' => 3.5,
        ], $logger);

        self::assertSame(Config::DEFAULT_ENDPOINT, $config->endpoint);
        self::assertNull($config->token);
        self::assertSame('unknown-service', $config->service);
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
        self::assertSame(Config::DEFAULT_DENYLIST, $config->denylist);
        self::assertSame(10, $config->fingerprintCap);
        self::assertSame(60000, $config->fingerprintWindowMs);
        self::assertSame(['/api/feed/**'], $config->ignoredRequestPaths);
        self::assertFalse($config->enabled);   // no token and no service: nothing would be sent anyway
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

    public function testAQueueBoundBelowOneIsClampedAndSaidOnce(): void
    {
        $written = new \AnvilFeed\Tests\Support\RecordingLogger();
        $config = Config::fromArray([
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
            'max_queue_size' => 0,
        ], new Logger($written));

        self::assertSame(1, $config->maxQueueSize,
            'a bound of zero is not a smaller queue, it is a queue that drops every event it is given '
            . 'and writes a warning about each — a log flood on the code path that exists to prevent one');
        self::assertCount(1, array_values(array_filter(
            $written->lines,
            static fn (string $line): bool => str_contains($line, 'max_queue_size')
        )));
    }

    /**
     * The three knobs Task 7 would choke on. A flush interval, a connect timeout or a request timeout of
     * zero is not "no wait", it is a busy loop and a transport that refuses the value on the first send —
     * the Java core clamps all three through the same `atLeastOne` as the queue bound (task-6 review
     * finding 11), and C9/C17 forbid a bound that exists in one language only.
     *
     * @param int|string $given
     */
    #[DataProvider('everyKnobThatHasAFloorOfOne')]
    public function testAKnobWithAFloorOfOneIsClampedAndSaidOnce(string $key, int $given): void
    {
        $written = new \AnvilFeed\Tests\Support\RecordingLogger();
        $config = Config::fromArray([
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
            $key => $given,
        ], new Logger($written));

        self::assertSame(1, $config->{self::PROPERTY_OF[$key]}, $key . ' was not clamped to its floor of 1');
        $said = $written->matching('"' . $key . '"');
        self::assertCount(1, $said, 'the clamp of ' . $key . ' was said ' . count($said) . ' times, not once');
        self::assertStringContainsString(
            'anvil-feed: "' . $key . '" is ' . $given . '; 1 is used instead',
            $said[0]
        );
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function everyKnobThatHasAFloorOfOne(): array
    {
        $rows = [];
        foreach (['max_queue_size', 'flush_interval_ms', 'connect_timeout_ms', 'request_timeout_ms'] as $key) {
            $rows[$key . ' at zero'] = [$key, 0];
            $rows[$key . ' negative'] = [$key, -5];
        }

        return $rows;
    }

    private const PROPERTY_OF = [
        'max_queue_size' => 'maxQueueSize',
        'flush_interval_ms' => 'flushIntervalMs',
        'connect_timeout_ms' => 'connectTimeoutMs',
        'request_timeout_ms' => 'requestTimeoutMs',
        'fingerprint_cap' => 'fingerprintCap',
        'fingerprint_window_ms' => 'fingerprintWindowMs',
    ];

    /**
     * The other two have a floor of ZERO, not one: a cap of zero is a cap a reader can mean, a NEGATIVE
     * cap or window is not a smaller one, it is an undefined one. Java's `atLeastZero`, same message.
     */
    #[DataProvider('everyKnobThatHasAFloorOfZero')]
    public function testAKnobWithAFloorOfZeroIsClampedAndSaidOnce(string $key, int $given): void
    {
        $written = new \AnvilFeed\Tests\Support\RecordingLogger();
        $config = Config::fromArray([
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
            $key => $given,
        ], new Logger($written));

        self::assertSame(0, $config->{self::PROPERTY_OF[$key]}, $key . ' was not clamped to its floor of 0');
        $said = $written->matching('"' . $key . '"');
        self::assertCount(1, $said, 'the clamp of ' . $key . ' was said ' . count($said) . ' times, not once');
        self::assertStringContainsString(
            'anvil-feed: "' . $key . '" is ' . $given . '; 0 is used instead',
            $said[0]
        );
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function everyKnobThatHasAFloorOfZero(): array
    {
        return [
            'fingerprint_cap negative' => ['fingerprint_cap', -1],
            'fingerprint_window_ms negative' => ['fingerprint_window_ms', -60000],
        ];
    }

    /**
     * And the floor is a floor, not a minimum of one: zero is a value an operator can mean for both — no
     * repeat suppression at all — so it passes through untouched and says nothing. A clamp that swallowed
     * it would switch a feature back ON for somebody who had switched it off.
     */
    public function testAFingerprintCapOrWindowOfZeroIsLeftAloneAndSaysNothing(): void
    {
        $written = new \AnvilFeed\Tests\Support\RecordingLogger();
        $config = Config::fromArray([
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
            'fingerprint_cap' => 0,
            'fingerprint_window_ms' => 0,
        ], new Logger($written));

        self::assertSame(0, $config->fingerprintCap);
        self::assertSame(0, $config->fingerprintWindowMs);
        self::assertSame([], $written->matching('is used instead'));
    }
}
