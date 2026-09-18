<?php

declare(strict_types=1);

namespace AnvilFeed\Tests\Conformance;

use AnvilFeed\Config;
use AnvilFeed\EventFactory;
use AnvilFeed\GitContext;
use AnvilFeed\Logger;
use AnvilFeed\LoopGuard;
use AnvilFeed\RateGate;
use AnvilFeed\Scrubber;
use AnvilFeed\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C9 and C17: identical parameter names, identical defaults and identical BEHAVIOUR in both languages.
 * A drift here fails the build.
 *
 * This is the PHP twin of the Java core's {@see tech.anvilcoder.feed.ConfigParityTest}, section for
 * section, so that Task 11's CI can point at the two files and get the same answer out of both. The first
 * two tests are the shared list (`conformance/config-defaults.json`) held against this SDK's effective
 * defaults in BOTH directions: a key in the file that nothing here reads is a parameter nobody is holding
 * either language to, and a knob this SDK has that the file does not name is a parameter the other
 * language was never asked for.
 *
 * Everything after them is a RULE rather than a value. Each one is pinned here so that a later change in
 * either language has something to be red against, and each is deliberately a second home for a rule the
 * unit suite also covers — the unit suite proves PHP behaves; this file proves PHP behaves like Java.
 *
 * `token` and `service` are absent from the shared list because they have NO default: a config without
 * them disables the SDK rather than guessing. `release` is absent for the same reason, and `enabled` is
 * derived state rather than a knob.
 */
final class ConfigParityTest extends TestCase
{
    private const REPOSITORY = __DIR__ . '/../../../..';

    /**
     * The parameters of `Config` that deliberately have no entry in the shared list, each with the reason
     * it has none. Adding a name here is how a knob leaves the parity contract, and it has to be a
     * decision somebody wrote down rather than a silent omission.
     */
    private const NOT_IN_THE_SHARED_LIST = [
        'token' => 'no default: without it the SDK is switched off rather than guessing one',
        'service' => 'no default: without it the SDK is switched off rather than guessing one',
        'release' => 'no default: unset means "read it from git or send none"',
        'enabled' => 'derived from the three above, not a value an operator sets on its own',
    ];

    /** @return array<string, mixed> */
    private function sharedList(): array
    {
        $raw = file_get_contents(self::REPOSITORY . '/conformance/config-defaults.json');
        self::assertIsString($raw, 'conformance/config-defaults.json is the contract; it must be readable');
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        unset($decoded['note']);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: Config, 1: RecordingLogger}
     */
    private function config(array $options = []): array
    {
        $written = new RecordingLogger();
        $config = Config::fromArray(array_merge([
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'shop-api',
        ], $options), new Logger($written));

        return [$config, $written];
    }

    private function factory(Config $config): EventFactory
    {
        return new EventFactory($config, new Scrubber($config->denylist), GitContext::none());
    }

    // ================================================================= the shared list

    /**
     * Every key of `config-defaults.json`, compared to what this SDK produces with nothing configured but
     * the two parameters that have no default. Both directions: the key SETS have to be equal, so a key
     * added to the file that this test does not read fails just as loudly as a value that drifted.
     */
    public function testEveryDefaultMatchesTheSharedList(): void
    {
        $expected = $this->sharedList();
        [$config] = $this->config();

        $actual = [
            'endpoint' => $config->endpoint,
            'environment' => $config->environment,
            'max_queue_size' => $config->maxQueueSize,
            'flush_interval_ms' => $config->flushIntervalMs,
            'batch_size' => $config->batchSize,
            'connect_timeout_ms' => $config->connectTimeoutMs,
            'request_timeout_ms' => $config->requestTimeoutMs,
            'max_frames' => $config->maxFrames,
            'context_lines' => $config->contextLines,
            'max_context_line_chars' => $config->maxContextLineChars,
            'denylist' => $config->denylist,
            'fingerprint_cap' => $config->fingerprintCap,
            'fingerprint_window_ms' => $config->fingerprintWindowMs,
            'ignored_request_paths' => $config->ignoredRequestPaths,
        ];

        $listed = array_keys($expected);
        sort($listed);
        $read = array_keys($actual);
        sort($read);
        self::assertSame($listed, $read, 'a parameter in the shared list that this test does not read is '
            . 'a parameter nobody is holding either language to');

        foreach ($actual as $key => $value) {
            self::assertSame($expected[$key], $value, $key);
        }
    }

    /**
     * The other direction, and the one a reflection is needed for: every parameter `Config` carries has to
     * appear in the shared list or in NOT_IN_THE_SHARED_LIST with its reason. A knob added to PHP alone —
     * the exact shape of a semantic drift — fails here on the day it is added rather than on the day
     * somebody notices Java cannot be configured the same way.
     */
    public function testEveryKnobOfThisSdkIsNamedInTheSharedList(): void
    {
        $constructor = (new \ReflectionClass(Config::class))->getConstructor();
        self::assertNotNull($constructor);

        $knobs = [];
        foreach ($constructor->getParameters() as $parameter) {
            $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $parameter->getName()));
            if (isset(self::NOT_IN_THE_SHARED_LIST[$parameter->getName()])) {
                continue;
            }
            $knobs[] = $snake;
        }
        sort($knobs);
        $listed = array_keys($this->sharedList());
        sort($listed);

        self::assertSame($listed, $knobs, 'a knob this SDK has and conformance/config-defaults.json does '
            . 'not name is a parameter the other language was never asked for — add it to the file, or to '
            . 'NOT_IN_THE_SHARED_LIST with the reason it has no default');
    }

    /** The default denylist is FR-5's nine terms, and the file is where the nine are written down. */
    public function testTheDefaultDenylistIsTheOneBothLanguagesShip(): void
    {
        self::assertSame($this->sharedList()['denylist'], Config::DEFAULT_DENYLIST);
    }

    /**
     * The five names both languages read out of the environment, spelled identically. `ANVIL_INGEST_TOKEN`
     * is the one the "the SDK is disabled" message names to an operator, so a rename here would send
     * somebody to look for a variable that no longer exists.
     */
    #[DataProvider('everyEnvironmentVariableBothLanguagesRead')]
    public function testTheEnvironmentVariableNamesAreTheOnesJavaReads(string $name, string $property, string $value): void
    {
        $saved = getenv($name);
        putenv($name . '=' . $value);
        try {
            $config = Config::fromEnvironment(
                ['token' => 'anv_srv_0123456789abcdef0123456789abcdef', 'service' => 'shop-api'],
                new Logger(new RecordingLogger())
            );
            self::assertSame($value, $config->{$property}, $name . ' is not read into ' . $property);
        } finally {
            if ($saved === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $saved);
            }
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function everyEnvironmentVariableBothLanguagesRead(): array
    {
        return [
            'ANVIL_INGEST_ENDPOINT' => ['ANVIL_INGEST_ENDPOINT', 'endpoint', 'https://ingest.example/api/feed'],
            'ANVIL_RELEASE' => ['ANVIL_RELEASE', 'release', 'v1.2.3'],
        ];
    }

    /**
     * `ANVIL_INGEST_TOKEN` and `ANVIL_SERVICE` get their own test because an override would hide them:
     * they are the two parameters the test above has to pass explicitly for the SDK to stay enabled.
     */
    public function testTheTokenAndServiceEnvironmentNamesAreTheOnesJavaReads(): void
    {
        $saved = ['ANVIL_INGEST_TOKEN' => getenv('ANVIL_INGEST_TOKEN'), 'ANVIL_SERVICE' => getenv('ANVIL_SERVICE'),
            'ANVIL_ENVIRONMENT' => getenv('ANVIL_ENVIRONMENT')];
        putenv('ANVIL_INGEST_TOKEN=anv_srv_0123456789abcdef0123456789abcdef');
        putenv('ANVIL_SERVICE=shop-api');
        putenv('ANVIL_ENVIRONMENT=staging');
        try {
            $config = Config::fromEnvironment([], new Logger(new RecordingLogger()));

            self::assertSame('anv_srv_0123456789abcdef0123456789abcdef', $config->token);
            self::assertSame('shop-api', $config->service);
            self::assertSame('staging', $config->environment);
            self::assertTrue($config->enabled, 'the three environment variables alone must enable the SDK');
        } finally {
            foreach ($saved as $name => $value) {
                $value === false ? putenv($name) : putenv($name . '=' . $value);
            }
        }
    }

    // ================================================================= the floors

    /**
     * The floors of ONE. A floor test that only asserted "some clamp exists" would have passed the defect
     * the PHP parity round found, so every row pins the exact value AND the line, which names the value
     * the operator wrote.
     */
    #[DataProvider('everyKnobWithAFloorOfOne')]
    public function testAKnobWithAFloorOfOneIsClampedAndSaidOnce(string $key, string $property, int $given): void
    {
        [$config, $written] = $this->config([$key => $given]);

        self::assertSame(1, $config->{$property}, $key . ' was not clamped to its floor of 1');
        self::assertCount(1, $written->matching('"' . $key . '" is ' . $given),
            'the clamp is announced, once, naming the value the operator wrote');
    }

    /** @return array<string, array{0: string, 1: string, 2: int}> */
    public static function everyKnobWithAFloorOfOne(): array
    {
        $rows = [];
        foreach ([
            'max_queue_size' => 'maxQueueSize',
            'flush_interval_ms' => 'flushIntervalMs',
            'connect_timeout_ms' => 'connectTimeoutMs',
            'request_timeout_ms' => 'requestTimeoutMs',
        ] as $key => $property) {
            foreach ([0, -5] as $given) {
                $rows[$key . ' = ' . $given] = [$key, $property, $given];
            }
        }

        return $rows;
    }

    /**
     * `fingerprint_cap` and `fingerprint_window_ms` floor at ZERO, not at one, in both languages: zero
     * means "no repeat suppression", which is a setting an operator may mean, and clamping it to one would
     * switch a feature back on for somebody who switched it off. A NEGATIVE one is not a smaller window,
     * it is an undefined one.
     */
    #[DataProvider('everyKnobWithAFloorOfZero')]
    public function testAKnobWithAFloorOfZeroIsClampedAndSaidOnce(string $key, string $property, int $given): void
    {
        [$config, $written] = $this->config([$key => $given]);

        self::assertSame(0, $config->{$property});
        self::assertCount(1, $written->matching('"' . $key . '" is ' . $given));
    }

    /** @return array<string, array{0: string, 1: string, 2: int}> */
    public static function everyKnobWithAFloorOfZero(): array
    {
        return [
            'fingerprint_cap = -1' => ['fingerprint_cap', 'fingerprintCap', -1],
            'fingerprint_window_ms = -60000' => ['fingerprint_window_ms', 'fingerprintWindowMs', -60000],
        ];
    }

    public function testAFingerprintCapOrWindowOfZeroIsLeftAloneAndSaysNothing(): void
    {
        [$config, $written] = $this->config(['fingerprint_cap' => 0, 'fingerprint_window_ms' => 0]);

        self::assertSame(0, $config->fingerprintCap);
        self::assertSame(0, $config->fingerprintWindowMs);
        self::assertSame([], $written->matching('is used instead'));
        self::assertSame([], $written->matching('fingerprint'));
    }

    /** And the second stage: the gate itself never divides by a zero window or admits an unbounded cap. */
    public function testTheGateFloorsAConfiguredZeroRatherThanObeyingIt(): void
    {
        $gate = new RateGate(0, 0, static fn (): float => 1.0);

        self::assertTrue($gate->allow('f'), 'the first occurrence of a window is always allowed');
        self::assertFalse($gate->allow('f'),
            'a cap of 0 is one per window, never zero and never unlimited — the same two-stage rule Java has');
    }

    // ================================================================= request.status_code

    /**
     * An integer, a whole finite float, or a string that is entirely an optional minus followed by digits.
     * Anything else is DROPPED rather than guessed at, because `(int) "12abc"` is 12, `(int) "abc"` is 0
     * and `(int) NAN` is 0 — a status nobody ever sent, shown in a cockpit as fact.
     *
     * This table is the Java core's `statusCodes()` with its Java-only rows (Long, BigInteger, short,
     * BigDecimal) left out: PHP has one integer type and one float type, and the rows that survive the
     * translation are the ones both languages can actually be handed.
     */
    #[DataProvider('everyShapeAStatusCodeArrivesIn')]
    public function testAStatusCodeIsAnIntegerOrIsDropped(mixed $given, ?int $expected): void
    {
        [$config] = $this->config();
        $request = ['method' => 'GET', 'url' => 'https://shop.example/checkout'];
        if ($given !== null) {
            $request['status_code'] = $given;
        }

        $sent = $this->factory($config)
            ->fromThrowable(new \RuntimeException('boom'), 'error', ['request' => $request])
            ->payload['request'];

        if ($expected === null) {
            self::assertArrayNotHasKey('status_code', $sent,
                'a status code that is not an integer was guessed at rather than dropped');

            return;
        }
        self::assertSame($expected, $sent['status_code']);
    }

    /** @return array<string, array{0: mixed, 1: ?int}> */
    public static function everyShapeAStatusCodeArrivesIn(): array
    {
        return [
            'an integer' => [404, 404],
            'a numeric string' => ['404', 404],
            'a numeric string, padded' => ['  404  ', 404],
            'a negative integer string' => ['-1', -1],
            'an integral float' => [200.0, 200],
            'a non-integral float' => [200.5, null],
            'a fraction below zero' => [-0.5, null],
            'the float NAN' => [NAN, null],
            'positive infinity' => [INF, null],
            'the largest int32' => [2147483647, 2147483647],
            'one past the largest int32' => [2147483648, null],
            'the smallest int32' => [-2147483648, -2147483648],
            'one below the smallest int32' => [-2147483649, null],
            'the largest int32, as a string' => ['2147483647', 2147483647],
            'a digit-string past int32' => ['3000000000', null],
            'more digits than a long' => ['99999999999999999999999999', null],
            'a whole float past int32' => [3.0e9, null],
            'digits with a tail' => ['12abc', null],
            'not a number at all' => ['abc', null],
            'a decimal string' => ['200.5', null],
            'the empty string' => ['', null],
            'a boolean' => [true, null],
            'an array' => [[500], null],
            'nothing at all' => [null, null],
        ];
    }

    // ================================================================= the denylist

    /**
     * An empty denylist means NO key rule — not a rule that matches everywhere, which is what an empty
     * alternation compiles to. The absolutes keep running, and both languages SAY it, once, at
     * configuration time, with the same sentence.
     */
    public function testAnEmptyDenylistIsSaidOnceAndSwitchesOffTheKeyRuleAndNothingElse(): void
    {
        $written = new RecordingLogger();
        $logger = new Logger($written);
        $config = Config::fromArray(
            ['token' => 'anv_srv_0123456789abcdef0123456789abcdef', 'service' => 'shop-api', 'denylist' => []],
            $logger
        );
        Config::fromArray(
            ['token' => 'anv_srv_0123456789abcdef0123456789abcdef', 'service' => 'shop-api', 'denylist' => []],
            $logger
        );   // a second read must not say it twice

        self::assertSame([], $config->denylist);
        self::assertCount(1, $written->matching('the denylist is empty'),
            'said once per logger, which is once per process');

        $scrubber = new Scrubber($config->denylist);
        self::assertSame('user=alice&mode=fast', $scrubber->scrubText('user=alice&mode=fast'),
            'the defect an empty alternation produces: every readable key=value destroyed');
        self::assertSame('Authorization: Bearer ' . Scrubber::FILTERED,
            $scrubber->scrubText('Authorization: Bearer abc.def'),
            'the absolutes are not configurable and keep running');

        $user = $scrubber->scrub(['user' => ['id' => 'u-1', 'ip' => '203.0.113.9']])['user'];
        self::assertSame(['id' => 'u-1'], $user);
    }

    /** `null` is "not configured" and keeps the nine terms; only an explicit empty list turns it off. */
    public function testANullDenylistIsNotAnEmptyOne(): void
    {
        [$config, $written] = $this->config(['denylist' => null]);

        self::assertSame(Config::DEFAULT_DENYLIST, $config->denylist);
        self::assertSame([], $written->matching('the denylist is empty'));
    }

    // ================================================================= the ignored-path glob

    /**
     * `*` stops at a slash, `**` crosses one, a trailing `/**` also matches the directory itself, and the
     * path is canonicalised before it is matched. Rule for rule the Java core's `LoopGuard`.
     */
    #[DataProvider('everyGlobRuleBothLanguagesApply')]
    public function testTheIgnoredPathGlobMeansWhatItMeansInJava(string $glob, string $path, bool $ignored): void
    {
        self::assertSame($ignored, (new LoopGuard([$glob]))->ignoresPath($path));
    }

    /** @return list<array{0: string, 1: string, 2: bool}> */
    public static function everyGlobRuleBothLanguagesApply(): array
    {
        return [
            ['/api/feed/**', '/api/feed/events', true],
            ['/api/feed/**', '/api/feed', true],
            ['/api/feed/**', '/api/feed/', true],
            ['/api/feed/**', '/API/FEED/events', true],
            ['/api/feed/**', '//api/feed//events', true],
            ['/api/feed/**', '/app/../api/feed/events', true],
            ['/api/feed/**', '/api/feed/events?x=1', true],
            ['/api/feed/**', '/api/feed/events#frag', true],
            ['/api/feed/**', 'https://host/api/feed/events', true],
            ['/api/feed/**', '/api/feedback', false],
            ['/api/feed/**', '/api/feeds', false],
            ['/api/*', '/api/feed', true],
            ['/api/*', '/api/feed/events', false],
            ['/api/**', '/api/feed/events', true],
            ['api/feed/**', '/api/feed/events', true],
        ];
    }

    public function testAnEmptyIgnoredPathListIsHonouredAndANullEntryIsNotARegex(): void
    {
        self::assertFalse((new LoopGuard([]))->ignoresPath('/api/feed/events'));
        [$config] = $this->config(['ignored_request_paths' => []]);
        self::assertSame([], $config->ignoredRequestPaths);

        $withHoles = [null, '   ', '/api/feed/**'];
        self::assertTrue((new LoopGuard($withHoles))->ignoresPath('/api/feed'));
        self::assertFalse((new LoopGuard($withHoles))->ignoresPath('/anything'));
    }

    // ================================================================= the level enum

    /** `error|warning|fatal`; everything else is `error`, because an unknown severity is one. */
    #[DataProvider('everyLevelBothLanguagesCoerce')]
    public function testTheLevelEnumCoercionIsTheOneBothLanguagesApply(mixed $given, string $expected): void
    {
        self::assertSame($expected, EventFactory::coerceLevel($given));

        [$config] = $this->config();
        $payload = $this->factory($config)
            ->fromThrowable(new \RuntimeException('boom'), is_string($given) ? $given : 'error', [])
            ->payload;
        self::assertSame(is_string($given) ? $expected : 'error', $payload['level']);

        // And again on the second pass, which is the one a beforeSend hook's output takes.
        $hooked = $payload;
        $hooked['level'] = $given;
        self::assertSame($expected, $this->factory($config)->finalise($hooked)['level'],
            'finalise() re-applies the enum, which is what keeps a hook\'s "info" off the wire');
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function everyLevelBothLanguagesCoerce(): array
    {
        return [
            'error' => ['error', 'error'],
            'warning' => ['warning', 'warning'],
            'fatal' => ['fatal', 'fatal'],
            'info' => ['info', 'error'],
            'debug' => ['debug', 'error'],
            'critical' => ['critical', 'error'],
            'ERROR' => ['ERROR', 'error'],
            'the empty string' => ['', 'error'],
            'null' => [null, 'error'],
        ];
    }

    // ================================================================= the count caps

    /**
     * Tags: the first 29 by insertion order. Breadcrumbs: the LAST 30. Frames: the first `max_frames`.
     * Three different rules, and each one is chosen rather than accidental — the tail of a breadcrumb
     * trail is what leads to the fault, the head of a stack is where the fault is.
     */
    public function testTheCountCapsAreTagsTwentyNineBreadcrumbsThirtyFramesMaxFrames(): void
    {
        [$config] = $this->config(['max_frames' => 3]);
        $tags = [];
        $breadcrumbs = [];
        for ($i = 1; $i <= 40; $i++) {
            $tags['tag-' . $i] = 'v' . $i;
            $breadcrumbs[] = 'step ' . $i;
        }

        $payload = $this->factory($config)->fromThrowable(
            new \RuntimeException('boom'),
            'error',
            ['tags' => $tags, 'breadcrumbs' => $breadcrumbs]
        )->payload;

        self::assertCount(29, $payload['tags']);
        self::assertArrayHasKey('tag-1', $payload['tags']);
        self::assertArrayNotHasKey('tag-30', $payload['tags']);

        self::assertCount(30, $payload['breadcrumbs']);
        self::assertSame('step 11', $payload['breadcrumbs'][0],
            'the LAST thirty: the tail is what leads to the fault');
        self::assertSame('step 40', $payload['breadcrumbs'][29]);
        self::assertCount(3, $payload['exception']['stacktrace']);
    }

    // ================================================================= idempotency

    /**
     * `finalise()` is public so a `beforeSend` hook's output takes the SAME pipeline, which means every
     * rule in it runs at least twice over a hooked event. Over the shared PII corpus, the second pass must
     * change nothing at all — in both languages.
     */
    public function testTheWholePipelineIsIdempotentOverTheSharedPiiCorpus(): void
    {
        [$config] = $this->config();
        $factory = $this->factory($config);
        $raw = json_decode(
            (string) file_get_contents(self::REPOSITORY . '/conformance/pii/raw.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $once = $factory->finalise($raw);
        $twice = $factory->finalise($once);

        self::assertSame($once, $twice);
        self::assertSame(json_encode($once), json_encode($twice));
        self::assertArrayNotHasKey('ip', $twice['user'],
            'the absolutes are applied on every pass, not only the first');
    }
}
