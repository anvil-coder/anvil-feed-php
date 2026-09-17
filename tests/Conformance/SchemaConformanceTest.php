<?php

declare(strict_types=1);

namespace AnvilFeed\Tests\Conformance;

use AnvilFeed\Config;
use AnvilFeed\Event;
use AnvilFeed\EventFactory;
use AnvilFeed\GitContext;
use AnvilFeed\Scrubber;
use PHPUnit\Framework\TestCase;

/**
 * The gate the conformance README names: "the SDK must produce it, the server must accept it."
 *
 * What is validated is the bytes, not the PHP array — every event goes through canonicalJson() and back
 * through json_decode() before the schema sees it, because the shape mistakes worth catching (an empty map
 * serialised as `[]`, an integer arriving as a string) only exist on the wire.
 */
final class SchemaConformanceTest extends TestCase
{
    private const REPOSITORY = __DIR__ . '/../../../..';

    private function validator(): JsonSchemaValidator
    {
        return JsonSchemaValidator::forSchemaFile(self::REPOSITORY . '/schema/v1.0/event.schema.json');
    }

    private function factory(array $options = []): EventFactory
    {
        $config = Config::fromArray(array_merge([
            'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
            'service' => 'disponio-api',
            'environment' => 'production',
        ], $options));

        return new EventFactory($config, new Scrubber(Config::DEFAULT_DENYLIST), GitContext::none());
    }

    /** @return mixed the event as the receiver would see it */
    private function overTheWire(Event $event): mixed
    {
        return json_decode($event->canonicalJson(), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Calibration, and it comes first on purpose. A validator that says yes to everything would let every
     * other test in this file pass while proving nothing, so it is held against the same nine fixtures ajv
     * is held against in conformance/tools/validate.mjs — four that must validate and five that must not,
     * each for its own reason.
     */
    public function testTheValidatorAgreesWithEveryConformanceCase(): void
    {
        $validator = $this->validator();
        $cases = json_decode(
            file_get_contents(self::REPOSITORY . '/conformance/cases.json'),
            true, 512, JSON_THROW_ON_ERROR
        );

        // At least the nine Task 0 built. The point of the number is that a truncated or empty index would
        // make this calibration vacuous; cases added later are picked up by the loop below on their own.
        self::assertGreaterThanOrEqual(9, count($cases), 'conformance/cases.json lost cases');

        foreach ($cases as $case) {
            $fixture = json_decode(
                file_get_contents(self::REPOSITORY . '/conformance/' . $case['file']),
                false, 512, JSON_THROW_ON_ERROR
            );
            $errors = $validator->errors($fixture);

            if ($case['valid']) {
                self::assertSame([], $errors, $case['file'] . ' should validate');
            } else {
                self::assertNotSame(
                    [],
                    $errors,
                    $case['file'] . ' should NOT validate — it is ' . $case['reason_code'] . ' at the ingest'
                );
            }
        }
    }

    /**
     * The scrubber is a schema gate as well, and this is the direction that matters: pii/raw.json carries
     * an address and is therefore NOT a sendable event, pii/expected.json is. Scrubbing has to be what
     * turns the first into the second — if it ever stopped removing user.ip the event would not merely leak,
     * it would be refused, and an SDK that silently posts refused events reads as "nothing ever broke".
     */
    public function testScrubbingTurnsTheUnsendableRawEventIntoASendableOne(): void
    {
        $validator = $this->validator();
        $raw = json_decode(
            file_get_contents(self::REPOSITORY . '/conformance/pii/raw.json'),
            true, 512, JSON_THROW_ON_ERROR
        );

        self::assertNotSame(
            [],
            $validator->errors(json_decode(json_encode($raw), false, 512, JSON_THROW_ON_ERROR)),
            'pii/raw.json carries user.ip; it must not be schema-valid'
        );

        $scrubbed = (new Scrubber(Config::DEFAULT_DENYLIST))->scrub($raw);

        self::assertSame(
            [],
            $validator->errors(json_decode(json_encode($scrubbed), false, 512, JSON_THROW_ON_ERROR))
        );
        self::assertSame(
            [],
            $validator->errors(json_decode(
                file_get_contents(self::REPOSITORY . '/conformance/pii/expected.json'),
                false, 512, JSON_THROW_ON_ERROR
            )),
            'pii/expected.json'
        );
    }

    public function testAThrowableSerialisesToAValidEvent(): void
    {
        $event = $this->factory()->fromThrowable(new \RuntimeException('Cannot invoke total() on null'));

        self::assertSame([], $this->validator()->errors($this->overTheWire($event)));
    }

    public function testAMessageSerialisesToAValidEvent(): void
    {
        $event = $this->factory()->fromMessage('Payment reconciliation skipped for 3 accounts', 'warning');

        self::assertSame([], $this->validator()->errors($this->overTheWire($event)));
    }

    public function testTheTestEventSerialisesToAValidEvent(): void
    {
        $event = $this->factory()->testEvent();

        self::assertSame([], $this->validator()->errors($this->overTheWire($event)));
    }

    /** Every optional block filled at once — the shape a busy request handler really produces. */
    public function testAFullyPopulatedEventSerialisesToAValidEvent(): void
    {
        $event = $this->factory(['release' => '2026.9.3'])->fromThrowable(
            new \RuntimeException('boom'),
            'fatal',
            [
                'request' => ['method' => 'POST', 'url' => 'https://shop.example/api/checkout',
                    'status_code' => 500],
                'user_id' => 'u-4711',
                'breadcrumbs' => ['cart.add sku=A1', 'checkout.start'],
                'tags' => ['tier' => 'gold', 'attempt' => 2],
            ]
        );

        self::assertSame([], $this->validator()->errors($this->overTheWire($event)));
    }

    /**
     * The bounds are the reason this gate is worth running: context_lines * max_context_line_chars is 8000
     * at the defaults while schema 1.0 stops a short text at 1000, and title and exception.message are cut
     * at 512 and 2000 against a message that is 5400 characters long.
     *
     * The throw site is a GENERATED file of 400-character lines, not this one. Quoting this test file would
     * produce a context window of a few hundred characters, the assertion would pass with the whole bound
     * removed, and the test would be a decoration rather than a guard — which is exactly what the first run
     * of it was.
     */
    public function testAnEventWithRealContextCodeStaysInsideEveryBound(): void
    {
        $probe = $this->generateAProbeFileOfLongLines();

        try {
            require $probe;
            $error = null;
            try {
                anvil_feed_context_probe();
            } catch (\Throwable $thrown) {
                $error = $thrown;
            }
            self::assertNotNull($error);

            // The throw site is the probe's own line, so frame 0 quotes the generated file.
            $wire = $this->overTheWire($this->factory()->fromThrowable($error));

            self::assertSame([], $this->validator()->errors($wire));
            self::assertSame($probe, $wire->exception->stacktrace[0]->file);
            self::assertGreaterThan(
                900,
                mb_strlen($wire->exception->stacktrace[0]->context_code),
                'the probe file must fill the context window, or the 1000-character bound is never reached'
            );

            // And the two text bounds, on a message far beyond both.
            $long = $this->overTheWire(
                $this->factory()->fromThrowable(new \RuntimeException(str_repeat('very long message ', 300)))
            );
            self::assertSame([], $this->validator()->errors($long));
            self::assertSame(512, mb_strlen($long->title));
            self::assertSame(2000, mb_strlen($long->exception->message));
        } finally {
            @unlink($probe);
        }
    }

    /**
     * Twenty-five lines of 400 characters around one throw: the window the SDK captures is then 8000-odd
     * characters before it is bounded, which is the case schema 1.0 refuses.
     */
    private function generateAProbeFileOfLongLines(): string
    {
        $padding = '// ' . str_repeat('x', 397);
        $lines = ['<?php', ''];
        for ($i = 0; $i < 12; $i++) {
            $lines[] = $padding;
        }
        $lines[] = 'function anvil_feed_context_probe(): void { throw new \RuntimeException("probe"); }';
        for ($i = 0; $i < 12; $i++) {
            $lines[] = $padding;
        }

        $path = tempnam(sys_get_temp_dir(), 'anvil-feed-probe-') . '.php';
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    /**
     * MAX_TAGS is 30 at the ingest, but AnvilFeedEventMapper.tags() puts the server's own user.ip_hash
     * pseudonym into the FIRST slot before any sender tag, precisely so a sender cannot push it out. Thirty
     * tags from the SDK therefore means thirty-one on arrival and one of them is dropped in silence — in
     * the cockpit, weeks later, with no way to tell which.
     */
    public function testATagFloodStaysInsideTheSlotTheIngestLeavesForTheSender(): void
    {
        $tags = [];
        for ($i = 1; $i <= 40; $i++) {
            $tags['tag-' . $i] = 'value-' . $i;
        }

        $wire = $this->overTheWire(
            $this->factory()->fromThrowable(new \RuntimeException('boom'), 'error', ['tags' => $tags])
        );

        self::assertSame([], $this->validator()->errors($wire));
        self::assertLessThanOrEqual(29, count(get_object_vars($wire->tags)));
        self::assertSame('value-1', $wire->tags->{'tag-1'});   // the FIRST are kept, as the ingest keeps them
    }

    /**
     * A blank message is a real call — a caught exception with no message, a log line that turned out
     * empty. title is required and must not be blank, so it cannot simply be the message.
     */
    public function testABlankMessageStillProducesASendableEvent(): void
    {
        $validator = $this->validator();

        self::assertSame([], $validator->errors($this->overTheWire($this->factory()->fromMessage('   '))));
        self::assertSame([], $validator->errors($this->overTheWire($this->factory()->fromMessage(''))));
        self::assertSame(
            [],
            $validator->errors($this->overTheWire($this->factory()->fromThrowable(new \RuntimeException(''))))
        );
    }

    /**
     * schema 1.0 bounds service at 128 characters and the ingest bounds it nowhere — so an over-long name
     * is accepted by the server, refused by the contract, and the only way a customer finds out is an empty
     * inbox. Bounded here, once, with a line that says what was sent.
     */
    public function testAnOverlongServiceNameIsBoundedRatherThanRefused(): void
    {
        $wire = $this->overTheWire(
            $this->factory(['service' => str_repeat('a', 300)])->fromThrowable(new \RuntimeException('boom'))
        );

        self::assertSame([], $this->validator()->errors($wire));
        self::assertSame(128, mb_strlen($wire->service));
    }

    /**
     * schema 1.0 requires request.url to be http(s). The published contract says a URL that is not gets
     * dropped and the event kept; the server does not actually do that, it keeps the value — so an SDK that
     * passes a file:// path straight through sends a body the contract refuses, and hands the ingest a
     * local path it never asked for. Dropped here, which is both the documented intent and the safe half.
     */
    public function testARequestUrlTheSchemaWouldRefuseIsDroppedRatherThanSent(): void
    {
        $wire = $this->overTheWire($this->factory()->fromThrowable(new \RuntimeException('boom'), 'error', [
            'request' => ['method' => 'GET', 'url' => 'file:///etc/passwd', 'status_code' => 500],
        ]));

        self::assertSame([], $this->validator()->errors($wire));
        self::assertFalse(isset($wire->request->url), 'a non-http(s) URL must not be sent at all');
        self::assertSame('GET', $wire->request->method);   // the rest of the block still travels
    }

    /** An environment that would be refused must never reach the wire in the first place. */
    public function testARepairedEnvironmentIsStillSchemaValid(): void
    {
        $event = $this->factory(['environment' => 'Prod_EU'])->fromThrowable(new \RuntimeException('x'));

        self::assertSame([], $this->validator()->errors($this->overTheWire($event)));
    }
}
