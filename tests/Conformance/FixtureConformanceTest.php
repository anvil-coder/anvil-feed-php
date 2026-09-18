<?php

declare(strict_types=1);

namespace AnvilFeed\Tests\Conformance;

use AnvilFeed\AnvilFeed;
use AnvilFeed\Client;
use AnvilFeed\Config;
use AnvilFeed\Event;
use AnvilFeed\GitContext;
use AnvilFeed\Logger;
use AnvilFeed\Scrubber;
use AnvilFeed\Tests\Support\ConformanceCaller;
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

    /** The address `conformance/pii/raw.json` plants, used wherever a real one is needed. */
    private const ADDRESS = '203.0.113.9';

    protected function tearDown(): void
    {
        AnvilFeed::reset();
    }

    // ---------------------------------------------------------------- valid/

    /**
     * The fixture is reproduced FIELD FOR FIELD — `assertSame` on the whole array, so key order is part
     * of the promise, and the `stacktrace` is compared rather than substituted away.
     *
     * That last part is the whole reason this test was rewritten. Comparing everything except the stack
     * is what let an SDK that led every message event with three of its OWN frames pass a file whose
     * subject is "the SDK must produce it". So the capture is made from
     * {@see \AnvilFeed\Tests\Support\ConformanceCaller} — a stand-in for customer code, in its own
     * file — and the fixture names that file, that line and `in_app: true`.
     *
     * Two values still cannot be equal to a fixture's and are substituted: a fresh UUID and the current
     * instant. One is normalised: the frame's absolute path, cut down to a repository-relative one,
     * because a golden document cannot carry this machine's home directory.
     */
    public function testTheMessageEventIsReproducedFieldForFieldIncludingTheCallersStack(): void
    {
        $expected = $this->fixture('valid/message-event.json');
        $client = $this->goldenClient();
        AnvilFeed::init($client);

        ConformanceCaller::message('Payment reconciliation skipped for 3 accounts');

        $actual = $this->asAGoldenDocument($this->onlyQueuedEvent($client), $expected);
        self::assertSame($expected, $actual);
    }

    public function testTheTestEventIsReproducedFieldForFieldIncludingTheCallersStack(): void
    {
        $expected = $this->fixture('valid/test-event.json');
        $client = $this->goldenClient();
        AnvilFeed::init($client);

        ConformanceCaller::testEvent();

        $actual = $this->asAGoldenDocument($this->onlyQueuedEvent($client), $expected);
        self::assertSame($expected, $actual);
    }

    /**
     * The same claim stated the other way round, because the comparison above would also pass if the
     * fixture were wrong in the same direction: no frame of any event this SDK builds may be a file of
     * this SDK. An event whose stack leads with the reporting library is what an operator opens in the
     * cockpit, and it is what made every `captureMessage()` in an application one incident.
     */
    public function testNoEventEverCarriesAFrameOfThisSdk(): void
    {
        $ourSource = realpath(__DIR__ . '/../../src');
        self::assertIsString($ourSource);

        foreach ($this->hostileCorpus() as $what => $payload) {
            foreach ($payload['exception']['stacktrace'] ?? [] as $frame) {
                self::assertStringStartsNotWith($ourSource, (string) ($frame['file'] ?? ''),
                    'the stack of "' . $what . '" leads with a file of the SDK itself');
            }
        }
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
        // The throw site is where the caller raised it, which for an exception is this very line.
        self::assertSame(__FILE__, $full['exception']['stacktrace'][0]['file']);
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

    /**
     * The same pair, through the PUBLIC API. The assertion above is made on a `Scrubber` this test
     * builds; the client builds its own from `$config->denylist`, and a configuration path that stopped
     * reaching it would leave the exact-pair test green while every event in production travelled
     * unscrubbed. So the raw fixture's own context is handed to `captureException` and what comes out is
     * compared to `pii/expected.json`, field by field, for every field the public API can carry.
     *
     * `context_code` and `user.ip` are not among them — the first is read from the file a frame points
     * at, the second cannot be set through this API at all (it has its own corpus entries above) — and
     * the exception MESSAGE is, which is the field the raw fixture plants a bearer token in.
     */
    public function testTheScrubberContractAlsoHoldsThroughThePublicCaptureApi(): void
    {
        $raw = $this->fixture('pii/raw.json');
        $expected = $this->fixture('pii/expected.json');

        $client = $this->client();
        $client->captureException(new \RuntimeException($raw['exception']['message']), [
            'request' => $raw['request'],
            'user_id' => $raw['user']['id'],
            'breadcrumbs' => $raw['breadcrumbs'],
            'tags' => $raw['tags'],
        ]);
        $wire = $this->onlyQueuedEvent($client);

        self::assertSame($expected['exception']['message'], $wire['exception']['message']);
        self::assertSame($expected['request'], $wire['request']);
        self::assertSame($expected['user'], $wire['user']);
        self::assertSame($expected['breadcrumbs'], $wire['breadcrumbs']);
        self::assertSame($expected['tags'], $wire['tags']);

        // And the negative control: every value the fixture pair says had to go is absent from the
        // whole serialised event, not merely from the field it was passed in.
        $serialised = json_encode($wire, JSON_THROW_ON_ERROR);
        foreach (['eyJhbGciOiJIUzI1NiJ9.abc', 'sk-live-99887766', 'ada@example.org',
                  '4111111111111111', 'CH9300762011623852957', 'abc123', 'sid=abc'] as $secret) {
            self::assertStringNotContainsString($secret, $serialised);
        }
    }

    /**
     * FR-5 c, stated as a literal rather than as a key that happens to be absent. The address is offered
     * by every route the public API has — a `user` context block, a `beforeSend` hook, and a tag whose
     * key is the scrubber's business — and must be on the wire by none of them.
     */
    public function testAnAddressNeverReachesTheWireByAnyRouteThePublicApiOffers(): void
    {
        foreach ($this->hostileCorpus() as $what => $payload) {
            self::assertStringNotContainsString(
                self::ADDRESS,
                json_encode($payload, JSON_THROW_ON_ERROR),
                'a caller-supplied address reached the wire, capturing: ' . $what
            );
        }
    }

    /**
     * `event_id` is the delivery key: `Sender::applyVerdicts` matches the ingest's answer on the Event's
     * own id, so a body naming a different one is never found in `accepted`, is sent once more and is
     * then dropped with a line blaming the network for what host code did. It is also the id
     * `captureTestEvent()` hands an operator to search for.
     */
    public function testABeforeSendHookCannotRewriteTheDeliveryKey(): void
    {
        $client = $this->client();
        $client->beforeSend(static function (array $payload): array {
            $payload['event_id'] = '01JBQ8Z0000000000000000000';   // a ULID, rejected/bad-event-id.json

            return $payload;
        });

        $id = $client->captureException(new \RuntimeException('boom'));
        $wire = $this->onlyQueuedEvent($client);

        self::assertSame($id, $wire['event_id'], 'the queued body must name the id the caller was given');
        self::assertSame(1, preg_match(self::CANONICAL_UUID, (string) $wire['event_id']));
    }

    /**
     * FR-5 applies to what the hook ADDS, not only to what the SDK built. A host that enriches an event
     * with a tag out of its own request context is the normal use of `beforeSend`, and the value it
     * reaches for is as likely to be a secret as anything the SDK collected itself.
     */
    public function testWhatABeforeSendHookAddsIsScrubbedBeforeItReachesTheWire(): void
    {
        $client = $this->client();
        $client->beforeSend(static function (array $payload): array {
            $payload['tags'] = ['password' => 'hunter2', 'tier' => 'gold'];
            $payload['breadcrumbs'] = ['GET /callback?api_key=sk-live-99887766'];

            return $payload;
        });

        $client->captureException(new \RuntimeException('boom'));
        $wire = $this->onlyQueuedEvent($client);

        self::assertSame('[Filtered]', $wire['tags']['password']);
        self::assertSame('gold', $wire['tags']['tier'], 'an ordinary tag still travels');
        self::assertStringNotContainsString('hunter2', json_encode($wire, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('sk-live-99887766', json_encode($wire, JSON_THROW_ON_ERROR));
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
        yield 'a caller-supplied copy of the ingest\'s own pseudonym' => $this->captured(
            fn (Client $c) => $c->captureException(new \OverflowException('boom'), [
                'user_id' => 'u-4711',
                'tags' => ['user.ip_hash' => 'deadbeef', 'tier' => 'gold'],
            ])
        );
        // FR-5 c through the public API. EventFactory writes `user` from `user_id` alone today, so a
        // `user` context block is the input a future adapter (Laravel has $request->ip() in hand) would
        // reach for — and the one that must not put an address on the wire. Asserted again, on the
        // literal, by testAnAddressNeverReachesTheWireByAnyRoute below.
        yield 'a caller-supplied address in a user block' => $this->captured(
            fn (Client $c) => $c->captureException(new \RuntimeException('boom'), [
                'user_id' => 'u-4711',
                'user' => ['id' => 'u-4711', 'ip' => self::ADDRESS],
            ])
        );
        // beforeSend is the one public input that can still put arbitrary members into a built event,
        // which makes it the only route by which `user.ip` and `tags['user.ip_hash']` are reachable at
        // all. Its output goes through the factory's scrub → bound → prune pipeline a second time; this
        // corpus entry is what holds that promise to every predicate in the file at once.
        yield 'a beforeSend hook that adds an address and a pseudonym' => $this->captured(
            fn (Client $c) => $c->captureException(new \LengthException('boom')),
            [],
            static fn (Client $c) => $c->beforeSend(static function (array $payload): array {
                $payload['user'] = ['id' => 'u-4711', 'ip' => self::ADDRESS];
                $payload['tags'] = ['user.ip_hash' => 'deadbeef', 'tier' => 'gold'];

                return $payload;
            })
        );
        yield 'a beforeSend hook that adds secrets and rewrites what the SDK owns' => $this->captured(
            fn (Client $c) => $c->captureException(new \OutOfBoundsException('boom')),
            [],
            static fn (Client $c) => $c->beforeSend(static function (array $payload): array {
                $payload['event_id'] = 'not-a-uuid-at-all';
                $payload['service'] = str_repeat('a', 300);
                $payload['schema_version'] = '0.9';
                $payload['tags'] = ['password' => 'hunter2', 'api_key' => 'sk-live-99887766'];
                $payload['breadcrumbs'] = ['GET /reset?token=abc123'];

                return $payload;
            })
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
     * @param null|callable(Client): void $configure runs before the capture — where a beforeSend goes
     * @return array<string, mixed>
     */
    private function captured(callable $capture, array $options = [], ?callable $configure = null): array
    {
        $client = $this->client($options);
        if ($configure !== null) {
            $configure($client);
        }
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
                // context_code is switched OFF throughout this file, and the reason is a trap this
                // project has now paid for three times: with it on, every frame quotes the source of
                // THIS file, so a secret written as a literal in an assertion comes back inside the
                // event as source code and reddens a leak test that is measuring nothing. The bounds
                // and the scrubbing of real context code are proved in SchemaConformanceTest, against
                // a GENERATED probe file, which is where that proof belongs.
                'context_lines' => 0,
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

        return $actual;
    }

    /**
     * A captured event as a golden document: the two unequal values substituted, and every frame's
     * absolute path cut down to a repository-relative one. The prefix is asserted to have been there,
     * so a frame from somewhere else cannot be normalised into agreement by accident.
     *
     * @param array<string, mixed> $actual
     * @param array<string, mixed> $expected
     * @return array<string, mixed>
     */
    private function asAGoldenDocument(array $actual, array $expected): array
    {
        $root = realpath(self::REPOSITORY);
        self::assertIsString($root);

        $frames = $actual['exception']['stacktrace'] ?? [];
        self::assertIsArray($frames);
        self::assertCount(1, $frames, 'the golden client asks for one frame, so the fixture can carry it');

        foreach ($frames as $index => $frame) {
            $file = (string) ($frame['file'] ?? '');
            self::assertStringStartsWith($root . '/', $file,
                'the frame is not in this repository, so normalising it would hide what it really is');
            $frames[$index]['file'] = substr($file, strlen($root) + 1);
        }
        $actual['exception']['stacktrace'] = $frames;

        return $this->substituteTheUnequalFields($actual, $expected);
    }

    /**
     * The client the two golden documents are captured with: one frame and no context code, because a
     * fixture checked into a repository cannot carry a machine's whole stack or the source of the file
     * it happened to be raised in. Everything else is the default.
     */
    private function goldenClient(): Client
    {
        return $this->client(['max_frames' => 1]);
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
