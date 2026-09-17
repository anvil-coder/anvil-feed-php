<?php

declare(strict_types=1);

namespace AnvilFeed\Tests\Conformance;

use AnvilFeed\Client;
use AnvilFeed\Config;
use AnvilFeed\Event;
use AnvilFeed\GitContext;
use AnvilFeed\Logger;
use AnvilFeed\Scrubber;
use AnvilFeed\Tests\Support\RecordingLogger;
use AnvilFeed\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `conformance/README.md`'s rule, from the producing side: **the SDK must produce it, the server must
 * accept it.**
 *
 * {@see SchemaConformanceTest} already holds every fixture against the schema — that is the *server must
 * accept* half, and it is not repeated here. This file is the other half, which no schema can state:
 *
 *   `valid/`         — the SDK's own output compared to the golden file FIELD BY FIELD, key order
 *                      included. A schema-valid event that has drifted from the fixture is exactly the
 *                      drift Z1 forbids, and the schema says nothing about it.
 *   `rejected/`      — the shapes the running ingest refuses. Asserted as shapes the SDK never produces,
 *                      against inputs chosen to *tempt* it into each one.
 *   `sdk-forbidden/` — the shapes the running ingest would ACCEPT and the contract refuses. The same
 *                      assertion, and the more important one: nothing on the wire would ever complain.
 *   `pii/`           — raw.json in, expected.json out, EXACTLY, key order included (FR-5 d).
 *
 * The rejected/forbidden half is driven by `conformance/cases.json` rather than by a list typed in here:
 * a fixture added to the index without a predicate in this file FAILS, instead of being quietly ignored
 * by the one runner that is supposed to prove the SDK cannot emit it.
 */
final class FixtureConformanceTest extends TestCase
{
    private const REPOSITORY = __DIR__ . '/../../../..';

    /** The canonical UUID `event_id` must be; `rejected/bad-event-id.json` is a ULID. */
    private const CANONICAL_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    // ---------------------------------------------------------------- valid/

    /**
     * The fixture is reproduced field for field, `assertSame` on the whole array — so the KEY ORDER is
     * part of the promise as well. Only the three values that cannot be equal are substituted: a fresh
     * UUID, the current instant, and the stack, which belongs to whichever file did the capturing.
     */
    public function testTheMessageEventIsReproducedFieldForField(): void
    {
        $expected = $this->fixture('valid/message-event.json');
        $client = $this->client();
        $client->captureMessage('Payment reconciliation skipped for 3 accounts', 'warning');
        $actual = $this->onlyQueuedEvent($client);

        $this->assertTheStackIsRealBeforeItIsSubstituted($actual);
        $actual = $this->substituteTheUnequalFields($actual, $expected);

        self::assertSame($expected, $actual);
    }

    public function testTheTestEventIsReproducedFieldForField(): void
    {
        $expected = $this->fixture('valid/test-event.json');
        $client = $this->client();
        $client->captureTestEvent();
        $actual = $this->onlyQueuedEvent($client);

        $this->assertTheStackIsRealBeforeItIsSubstituted($actual);
        $actual = $this->substituteTheUnequalFields($actual, $expected);

        self::assertSame($expected, $actual);
    }

    /**
     * `valid/minimal.json` is the SMALLEST event the ingest accepts, not a shape this SDK emits: it
     * carries no `exception.message` and no stacktrace, and the SDK always sends both. What it pins is
     * every mandatory field and its value, so the comparison is against exactly the keys the fixture
     * names — with `exception` narrowed to the one member it has.
     *
     * The throwable is a real `\TypeError` with the fixture's own message, so `title` is compared as the
     * SDK composes it (`type: message`) and not as a string somebody copied across.
     */
    public function testTheMinimalEventsEveryMandatoryFieldIsReproduced(): void
    {
        $expected = $this->fixture('valid/minimal.json');
        $client = $this->client();
        $client->captureException(new \TypeError("Cannot read property 'id' of undefined"));
        $full = $this->onlyQueuedEvent($client);

        $actual = [];
        foreach (array_keys($expected) as $key) {
            self::assertArrayHasKey($key, $full, 'valid/minimal.json names ' . $key . ' and the SDK omits it');
            $actual[$key] = $full[$key];
        }
        $actual['exception'] = ['type' => $actual['exception']['type'] ?? null];
        $actual = $this->substituteTheUnequalFields($actual, $expected);

        self::assertSame($expected, $actual);
        // …and the two fields minimal.json leaves out are the ones this SDK always adds.
        self::assertSame("Cannot read property 'id' of undefined", $full['exception']['message']);
        self::assertNotSame([], $full['exception']['stacktrace']);
    }

    /**
     * The server's own golden examples. Neither is a shape the SDK builds — `enrichment.suspect_commit`
     * and `agent_context` are filled by the ingest — but both must survive this SDK's canonical ordering
     * byte for byte, because that ordering is what every event of ours is serialised through. A key the
     * two documents disagree about would put every PHP event in a different order from the server's own
     * example, which is the drift Z1's field-by-field promise is about.
     *
     * @return array<string, array{string}>
     */
    public static function goldenExamples(): array
    {
        return [
            'section-3-example' => ['valid/section-3-example.json'],
            'enriched' => ['valid/enriched.json'],
            'the scrubber output' => ['pii/expected.json'],
        ];
    }

    #[DataProvider('goldenExamples')]
    public function testAGoldenExampleSurvivesTheSdksCanonicalOrdering(string $name): void
    {
        $example = $this->fixture($name);

        self::assertSame($example, Event::inCanonicalOrder($example));
        // The negative control: a document whose keys the SDK reorders must be visible as such, so the
        // assertion above has to be able to fail. Reversed, it does.
        $reversed = array_reverse($example, true);
        self::assertNotSame($reversed, Event::inCanonicalOrder($reversed));
    }

    // ------------------------------------------------------------------ pii/

    /**
     * FR-5 d, as `conformance/README.md` states it: `raw.json` in, `expected.json` out of the scrubber.
     * `assertSame` on the whole array, so a scrubber that produced the right values in a different key
     * order would still be caught — the scrubbed payload is the one that goes on the wire.
     *
     * The direction that matters is asserted in SchemaConformanceTest (raw is NOT schema-valid, the
     * scrubbed one is). This is the exactness: not "the address is gone" but "this exact document".
     */
    public function testTheScrubberTurnsTheRawFixtureIntoExactlyTheExpectedOne(): void
    {
        $raw = $this->fixture('pii/raw.json');
        $expected = $this->fixture('pii/expected.json');

        $scrubbed = (new Scrubber(Config::DEFAULT_DENYLIST))->scrub($raw);

        self::assertSame($expected, $scrubbed);
        // The negative control: the fixture pair must actually differ, or this test passes on a copy.
        self::assertNotSame($expected, $raw, 'pii/raw.json and pii/expected.json are the same document');
    }

    // ------------------------------------------ rejected/ and sdk-forbidden/

    /**
     * Every shape the ingest refuses (`rejected/`) and every shape the contract refuses while the ingest
     * would accept it (`sdk-forbidden/`), as a predicate. Keyed by the fixture path so that
     * {@see testEveryRefusedFixtureHasAPredicate} can hold this map against `conformance/cases.json`.
     *
     * @return array<string, callable(array<string, mixed>): bool>
     */
    private static function refusedShapes(): array
    {
        return [
            'rejected/missing-event-id.json' =>
                static fn (array $p): bool => !isset($p['event_id']),
            'rejected/bad-event-id.json' =>
                static fn (array $p): bool => preg_match(
                    self::CANONICAL_UUID,
                    is_string($p['event_id'] ?? null) ? $p['event_id'] : ''
                ) !== 1,
            'rejected/bad-environment.json' =>
                static fn (array $p): bool => preg_match(
                    Config::ENVIRONMENT_PATTERN,
                    is_string($p['environment'] ?? null) ? $p['environment'] : ''
                ) !== 1,
            'rejected/missing-exception-type.json' =>
                static fn (array $p): bool => !isset($p['exception']),
            // The captureMessage trap: an exception object present, with a message and no type. This is
            // the shape a naive message-only implementation produces, and the ingest answers
            // "the required field 'exception.type' is missing".
            'rejected/exception-without-type.json' =>
                static fn (array $p): bool => isset($p['exception'])
                    && trim((string) ($p['exception']['type'] ?? '')) === '',
            'rejected/unsupported-schema-version.json' =>
                static fn (array $p): bool => ($p['schema_version'] ?? null) !== '1.0',
            'rejected/bad-timestamp-impossible-date.json' =>
                static fn (array $p): bool => self::isNotARealCalendarDate((string) ($p['timestamp'] ?? '')),
            'rejected/bad-timestamp-too-precise.json' =>
                static fn (array $p): bool => self::fractionalDigits((string) ($p['timestamp'] ?? '')) > 9,
            'rejected/whitespace-only-title.json' =>
                static fn (array $p): bool => trim((string) ($p['title'] ?? '')) === '',

            'sdk-forbidden/user-ip.json' =>
                static fn (array $p): bool => isset($p['user']['ip']),
            'sdk-forbidden/tag-user-ip-hash.json' =>
                static fn (array $p): bool => is_array($p['tags'] ?? null)
                    && array_key_exists('user.ip_hash', $p['tags']),
            'sdk-forbidden/level-info.json' =>
                static fn (array $p): bool => !in_array(
                    $p['level'] ?? null,
                    ['error', 'warning', 'fatal'],
                    true
                ),
            'sdk-forbidden/request-url-file-scheme.json' =>
                static fn (array $p): bool => isset($p['request']['url'])
                    && preg_match('#^https?://#i', (string) $p['request']['url']) !== 1,
            'sdk-forbidden/missing-language.json' =>
                static fn (array $p): bool => !isset($p['language']),
            'sdk-forbidden/service-too-long.json' =>
                static fn (array $p): bool => mb_strlen((string) ($p['service'] ?? '')) > 128,

            // cases.json marks pii/raw.json invalid for the same reason as sdk-forbidden/user-ip.json:
            // it is the scrubber's INPUT and must never be a wire shape.
            'pii/raw.json' =>
                static fn (array $p): bool => isset($p['user']['ip']),
        ];
    }

    /**
     * The index is the contract, not this file. A fixture added to `conformance/cases.json` with
     * `valid: false` and no predicate here would otherwise leave the PHP SDK with no proof at all that
     * it cannot emit the new shape — and the run would still print OK.
     */
    public function testEveryRefusedFixtureHasAPredicate(): void
    {
        $refused = [];
        foreach ($this->cases() as $case) {
            if ($case['valid'] === false) {
                $refused[] = $case['file'];
            }
        }
        sort($refused);
        $covered = array_keys(self::refusedShapes());
        sort($covered);

        self::assertSame($refused, $covered,
            'conformance/cases.json and this file disagree about which shapes the SDK must never produce');
    }

    /** @return array<string, array{string}> */
    public static function refusedFixtures(): array
    {
        $cases = [];
        foreach (array_keys(self::refusedShapes()) as $file) {
            $cases[$file] = [$file];
        }

        return $cases;
    }

    /**
     * Two assertions, and the first is what keeps the second honest: the predicate must describe the
     * fixture it is named after (a fixture rewritten under a predicate that no longer matches it would
     * make the second assertion vacuous), and then no event this SDK produces may satisfy it.
     *
     * The corpus is not three vanilla captures. Every entry is an input chosen to TEMPT the SDK into one
     * of these shapes — an `info` level, a `file://` URL, a caller-supplied `user.ip_hash` tag, a 300
     * character service name, a blank message, an environment the binding pattern refuses. A corpus of
     * well-behaved calls would pass every predicate below while proving nothing.
     */
    #[DataProvider('refusedFixtures')]
    public function testTheSdkNeverProducesARefusedShape(string $file): void
    {
        $isTheRefusedShape = self::refusedShapes()[$file];

        self::assertTrue(
            $isTheRefusedShape($this->fixture($file)),
            $file . ' no longer has the shape this predicate is named after — the guard below is vacuous'
        );

        foreach ($this->hostileCorpus() as $what => $payload) {
            self::assertFalse(
                $isTheRefusedShape($payload),
                sprintf('the SDK produced the shape %s describes, capturing: %s', $file, $what)
            );
        }
    }

    /**
     * Everything the SDK emits under the inputs above must still be a valid event — the point is not
     * merely that the forbidden shape is avoided but that what replaces it is sendable.
     */
    public function testEveryEventOfTheHostileCorpusIsItselfSchemaValid(): void
    {
        $validator = JsonSchemaValidator::forSchemaFile(self::REPOSITORY . '/schema/v1.0/event.schema.json');

        foreach ($this->hostileCorpus() as $what => $payload) {
            $wire = json_decode(
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                false, 512, JSON_THROW_ON_ERROR
            );
            self::assertSame([], $validator->errors($wire), $what);
        }
    }

    // ------------------------------------------------------------- machinery

    /**
     * Captures made through the PUBLIC API — `Client::captureException` / `captureMessage` /
     * `captureTestEvent` — because a shape the SDK "never produces" is a claim about the API a customer
     * calls, not about a factory method behind it.
     *
     * One client per entry: the per-fingerprint rate gate would otherwise drop the later captures of a
     * repeated fault, and a corpus whose entries silently became empty is a corpus that proves nothing.
     *
     * @return iterable<string, array<string, mixed>>
     */
    private function hostileCorpus(): iterable
    {
        yield 'a plain exception' => $this->captured(
            fn (Client $c) => $c->captureException(new \RuntimeException('Cannot read property id of undefined'))
        );
        yield 'a message (the exception-without-type trap)' => $this->captured(
            fn (Client $c) => $c->captureMessage('Payment reconciliation skipped for 3 accounts', 'warning')
        );
        yield 'the test event' => $this->captured(fn (Client $c) => $c->captureTestEvent());
        yield 'a blank message (the whitespace-only-title trap)' => $this->captured(
            fn (Client $c) => $c->captureMessage('   ', 'warning')
        );
        yield 'an exception with no message at all' => $this->captured(
            fn (Client $c) => $c->captureException(new \RuntimeException(''))
        );
        yield 'a caller asking for level info' => $this->captured(
            fn (Client $c) => $c->captureException(new \LogicException('boom'), ['level' => 'info'])
        );
        yield 'a file:// request URL' => $this->captured(
            fn (Client $c) => $c->captureException(new \DomainException('boom'), [
                'request' => ['method' => 'GET', 'url' => 'file:///etc/passwd'],
            ])
        );
        yield 'a caller-supplied address and pseudonym' => $this->captured(
            fn (Client $c) => $c->captureException(new \OverflowException('boom'), [
                'user_id' => 'u-4711',
                'tags' => ['ip' => '203.0.113.9', 'user.ip_hash' => 'deadbeef', 'tier' => 'gold'],
            ])
        );
        yield 'a 300-character service name' => $this->captured(
            fn (Client $c) => $c->captureException(new \RangeException('boom')),
            ['service' => str_repeat('a', 300)]
        );
        yield 'an environment the binding pattern refuses' => $this->captured(
            fn (Client $c) => $c->captureException(new \UnderflowException('boom')),
            ['environment' => 'Prod_EU']
        );
        yield 'an event full of PII' => $this->captured(
            fn (Client $c) => $c->captureException(
                new \RuntimeException('rejected for Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.abc'),
                [
                    'request' => ['method' => 'POST', 'url' => 'https://app.example/reset?token=abc123',
                        'status_code' => 401],
                    'user_id' => 'u-4711',
                    'tags' => ['email' => 'ada@example.org', 'tenant' => 'kunde-x'],
                    'breadcrumbs' => ['login attempt for email=ada@example.org'],
                ]
            )
        );
    }

    /**
     * @param callable(Client): ?string $capture
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function captured(callable $capture, array $options = []): array
    {
        $client = $this->client($options);
        $id = $capture($client);

        self::assertNotNull($id, 'the capture was dropped; this corpus entry would prove nothing');

        return $this->onlyQueuedEvent($client);
    }

    /** @param array<string, mixed> $options */
    private function client(array $options = []): Client
    {
        // A recording sink rather than the default error_log: two of the corpus entries are *supposed* to
        // produce an init line (the over-long service, the repaired environment), and a suite that printed
        // them would teach the next reader to ignore the run's output.
        $logger = new Logger(new RecordingLogger());

        return Client::create(
            Config::fromArray(array_merge([
                'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
                'service' => 'disponio-api',
                'environment' => 'production',
            ], $options), $logger),
            new RecordingTransport(),
            $logger,
            GitContext::none()
        );
    }

    /** @return array<string, mixed> */
    private function onlyQueuedEvent(Client $client): array
    {
        $queued = $client->queue()->take(2);
        self::assertCount(1, $queued, 'exactly one event should have been queued');

        return $queued[0]->payload;
    }

    /** @param array<string, mixed> $payload */
    private function assertTheStackIsRealBeforeItIsSubstituted(array $payload): void
    {
        $frames = $payload['exception']['stacktrace'] ?? null;
        self::assertIsArray($frames);
        self::assertNotSame([], $frames, 'the stack is substituted below, so it must exist first');
        self::assertArrayHasKey('file', $frames[0]);
        self::assertArrayHasKey('line', $frames[0]);
        self::assertArrayHasKey('function', $frames[0]);
        self::assertArrayHasKey('in_app', $frames[0]);
    }

    /**
     * The three values that cannot be equal to a fixture's, and nothing else. The stack is this test's
     * own — the fixture pins the SHAPE of a frame, not the file it happened to be raised in.
     *
     * @param array<string, mixed> $actual
     * @param array<string, mixed> $expected
     * @return array<string, mixed>
     */
    private function substituteTheUnequalFields(array $actual, array $expected): array
    {
        self::assertSame(1, preg_match(self::CANONICAL_UUID, (string) $actual['event_id']));
        self::assertSame(1, preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            (string) $actual['timestamp']
        ), 'the timestamp must be a second-precision UTC instant');

        $actual['event_id'] = $expected['event_id'];
        $actual['timestamp'] = $expected['timestamp'];
        if (isset($expected['exception']['stacktrace'])) {
            $actual['exception']['stacktrace'] = $expected['exception']['stacktrace'];
        }

        return $actual;
    }

    /** @return list<array<string, mixed>> */
    private function cases(): array
    {
        return json_decode(
            (string) file_get_contents(self::REPOSITORY . '/conformance/cases.json'),
            true, 512, JSON_THROW_ON_ERROR
        );
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        return json_decode(
            (string) file_get_contents(self::REPOSITORY . '/conformance/' . $name),
            true, 512, JSON_THROW_ON_ERROR
        );
    }

    /** 2026-02-30 is digit-shaped and is not a day. */
    private static function isNotARealCalendarDate(string $timestamp): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T/', $timestamp, $m) !== 1) {
            return true;
        }

        return !checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** java.time.Instant.parse accepts at most nine, which is what the schema's pattern encodes. */
    private static function fractionalDigits(string $timestamp): int
    {
        return preg_match('/\.(\d+)/', $timestamp, $m) === 1 ? strlen($m[1]) : 0;
    }
}
