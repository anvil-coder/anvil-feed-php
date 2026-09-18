<?php

declare(strict_types=1);

namespace AnvilFeed\Tests\Conformance;

use AnvilFeed\AnvilFeed;
use AnvilFeed\Client;
use AnvilFeed\Config;
use AnvilFeed\Fingerprint;
use AnvilFeed\GitContext;
use AnvilFeed\Logger;
use AnvilFeed\Tests\Support\AnotherConformanceCaller;
use AnvilFeed\Tests\Support\ConformanceCaller;
use AnvilFeed\Tests\Support\RecordingLogger;
use AnvilFeed\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `conformance/fingerprint/vectors.json`, reproduced END TO END: through a whole event payload, and then
 * through the public API a customer calls.
 *
 * {@see \AnvilFeed\Tests\FingerprintTest} (unit suite) holds `Fingerprint::payload()` and
 * `::normalizeFile()` against the same vectors at the level of their arguments. That is not the same
 * claim. What decides whether a customer's production error stream survives a storm is the fingerprint
 * of the event the SDK actually built, computed by the code path `Client::capture` actually takes — and
 * the two can drift apart in a way no argument-level test sees: a factory that stops marking application
 * frames `in_app`, a `stacktrace` key renamed, a `service` that arrives bounded.
 *
 * So this file goes the other way round: the vector's fields are assembled into an EVENT payload, the
 * fingerprint is taken with `ofEvent`, and then the four rules the vectors exist to pin — the shape tag,
 * the absent message, the absent line number, and frame order — are exercised through
 * `AnvilFeed::captureException` against the local rate gate they exist to feed.
 */
final class FingerprintConformanceTest extends TestCase
{
    private const VECTORS = __DIR__ . '/../../../../conformance/fingerprint/vectors.json';
    private const UNIT_SEPARATOR = "\u{001F}";

    protected function tearDown(): void
    {
        AnvilFeed::reset();
    }

    // ------------------------------------------------- the vectors, as events

    /** @return array<string, array{array<string, mixed>}> */
    public static function payloadVectors(): array
    {
        $vectors = self::vectors()['payload_vectors'];
        self::assertGreaterThanOrEqual(6, count($vectors), 'conformance/fingerprint lost vectors');

        $cases = [];
        foreach ($vectors as $vector) {
            $cases[(string) $vector['name']] = [$vector];
        }

        return $cases;
    }

    /**
     * The vector as the SDK sees it: a whole event payload, read with `ofEvent`, which is the only call
     * `Client::capture` makes. The expected digest is derived from the vector's own payload string rather
     * than hard-coded beside it, so a vector and its digest cannot drift apart here.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('payloadVectors')]
    public function testAVectorIsReproducedFromAWholeEventPayload(array $vector): void
    {
        $payload = [
            'schema_version' => '1.0',
            'event_id' => '9b1c7f2e-5a34-4c18-8e0d-1f7a2b3c4d5e',
            'title' => $vector['title'],
            'level' => 'error',
            'environment' => 'production',
            'service' => $vector['service'],
            'language' => 'php',
            'exception' => [
                'type' => $vector['exception_type'],
                'message' => 'a message that must not reach the fingerprint',
                'stacktrace' => $vector['frames'],
            ],
        ];

        self::assertSame(
            hash('sha256', (string) $vector['expected_payload']),
            Fingerprint::ofEvent($payload),
            $vector['name']
        );
    }

    /**
     * The same vectors with the exception MESSAGE changed. It is the one field of an event that carries
     * an order number, a user id or a timestamp, and it is deliberately absent from the payload string —
     * if it were not, one incident per order would be no incident at all, and the local storm cap below
     * would never fire.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('payloadVectors')]
    public function testTheExceptionMessageIsNotPartOfTheFingerprint(array $vector): void
    {
        $first = $this->eventFor($vector, 'order 4711 could not be charged');
        $second = $this->eventFor($vector, 'order 4712 could not be charged');

        self::assertNotSame($first['exception']['message'], $second['exception']['message']);
        self::assertSame(Fingerprint::ofEvent($first), Fingerprint::ofEvent($second), $vector['name']);
    }

    /**
     * And the line numbers. Adding an import at the top of a file moves every line below it; a
     * fingerprint that moved with them would open a fresh incident for every fault in the file on the
     * next deploy, and the cockpit would show a wall of one-occurrence incidents.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('payloadVectors')]
    public function testLineNumbersAreNotPartOfTheFingerprint(array $vector): void
    {
        $before = $this->eventFor($vector, 'boom', 12);
        $after = $this->eventFor($vector, 'boom', 4711);

        self::assertSame(Fingerprint::ofEvent($before), Fingerprint::ofEvent($after), $vector['name']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function normalizeFileVectors(): array
    {
        $cases = [];
        foreach (self::vectors()['normalize_file_vectors'] as $vector) {
            $input = (string) $vector['input'];
            $cases[$input === '' ? 'an empty file name' : $input] = [$vector];
        }
        self::assertGreaterThanOrEqual(9, count($cases), 'conformance/fingerprint lost normalizeFile vectors');

        return $cases;
    }

    /**
     * The path rules, reached through an event's `exception.stacktrace[0].file` rather than through
     * `normalizeFile()` directly — a frame whose `file` key the factory renamed would leave the direct
     * test green and every fingerprint in production computed over an empty path.
     *
     * The expected field is composed here from the format the vectors' own note states (U+001F, the
     * length in UTF-16 code units, ':', the value) so the assertion does not run through the
     * implementation it is checking.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('normalizeFileVectors')]
    public function testANormalizeFileVectorIsReproducedFromAFrameOfAnEvent(array $vector): void
    {
        $payload = [
            'service' => 'shop-api',
            'title' => 'Sync failed',
            'exception' => [
                'type' => 'RuntimeException',
                'stacktrace' => [
                    ['file' => $vector['input'], 'function' => 'handle', 'in_app' => true],
                ],
            ],
        ];
        $expectedField = self::field($vector['expected'] . '#handle');

        $expectedPayload = 'incident-fingerprint/v1'
            . self::field('frames') . self::field('shop-api') . self::field('RuntimeException')
            . $expectedField;

        self::assertSame(hash('sha256', $expectedPayload), Fingerprint::ofEvent($payload), $vector['input']);
    }

    // --------------------------------------------- and now through the API

    /**
     * The whole point of computing the server's rule locally: a loop that raises the same fault forty
     * thousand times a minute is recognised INSIDE the process, before the queue, the socket and the
     * ingest's own limiter. Driven through the facade, which is what a host application calls.
     *
     * The suppressed count is asserted against the cap, and the log line against the first twelve
     * characters of the fingerprint of the FIRST queued event — so the identity the cap keys on is the
     * one this file's vectors define, not a hash of something else that happens to be stable.
     */
    public function testAStormOfOneFaultIsCappedOnTheFingerprintOfTheEventThatWasQueued(): void
    {
        $written = new RecordingLogger();
        $client = $this->initClient($written, ['fingerprint_cap' => 3]);

        $queued = 0;
        for ($i = 0; $i < 40; $i++) {
            if (AnvilFeed::captureException(new \RuntimeException('the same fault, occurrence ' . $i)) !== null) {
                $queued++;
            }
        }

        self::assertSame(3, $queued, 'the cap is the cap; a storm past it must not reach the queue');
        $events = $client->queue()->take(10);
        self::assertCount(3, $events);

        $fingerprint = Fingerprint::ofEvent($events[0]->payload);
        self::assertSame($fingerprint, Fingerprint::ofEvent($events[2]->payload),
            'all three are the same fault, so the local gate saw one identity');
        self::assertNotSame([], $written->matching(substr($fingerprint, 0, 12)),
            'the line that tells an operator a storm is being suppressed must name the identity it capped');
    }

    /**
     * The other half, and the one that decides whether suppression is safe: a DIFFERENT fault raised in
     * the middle of a storm must still be reported. The two differ only in `exception.type`, which is a
     * field of the vectors' payload string — so this is that field, exercised where it matters.
     */
    public function testADifferentFaultIsNotSilencedByAnothersStorm(): void
    {
        $this->initClient(new RecordingLogger(), ['fingerprint_cap' => 2]);

        for ($i = 0; $i < 10; $i++) {
            AnvilFeed::captureException(new \RuntimeException('storm'));
        }

        self::assertNotNull(
            AnvilFeed::captureException(new \LogicException('an entirely different fault')),
            'a storm of one fault must never take the whole error stream down with it'
        );
    }

    /**
     * Two `captureMessage()` calls from the SAME site are one identity. That is the reading under which
     * the local cap is safe — it drops exactly what the cockpit would have shown as one incident — and
     * the message text is deliberately not part of it.
     */
    public function testTwoMessagesFromOneSiteAreOneIdentity(): void
    {
        $client = $this->initClient(new RecordingLogger(), ['fingerprint_cap' => 50]);

        ConformanceCaller::message('Payment reconciliation skipped for 3 accounts');
        ConformanceCaller::message('Payment reconciliation skipped for 9 accounts');

        $events = $client->queue()->take(2);
        self::assertCount(2, $events);
        self::assertSame(
            Fingerprint::ofEvent($events[0]->payload),
            Fingerprint::ofEvent($events[1]->payload),
            'the message is not part of the identity; the site is'
        );
    }

    /**
     * …and the assertion without which the one above is vacuous. Until Task 3's fix, a message event's
     * stack led with `src/Client.php` whatever the caller was, so EVERY `captureMessage()` anywhere in
     * an application shared one fingerprint — "two messages from one site agree" was true of two
     * messages from anywhere at all, and the local cap silenced a whole application's log lines after
     * ten of them.
     *
     * The two calls are in two different FILES (`tests/Support/`), not two methods of this class, so
     * nothing but the frame can tell them apart.
     */
    public function testTwoMessagesFromDifferentFilesAreDifferentIdentities(): void
    {
        $client = $this->initClient(new RecordingLogger(), ['fingerprint_cap' => 50]);

        ConformanceCaller::message('the same words from two different files');
        AnotherConformanceCaller::message('the same words from two different files');

        $events = $client->queue()->take(2);
        self::assertCount(2, $events);
        self::assertNotSame(
            $events[0]->payload['exception']['stacktrace'][0]['file'],
            $events[1]->payload['exception']['stacktrace'][0]['file'],
            'the two calls must really be in two files, or the assertion below proves nothing'
        );
        self::assertNotSame(
            Fingerprint::ofEvent($events[0]->payload),
            Fingerprint::ofEvent($events[1]->payload),
            'two faults in two files are two incidents; one fingerprint for both is an SDK that put its '
            . 'own frames in front of the caller\'s'
        );
    }

    /**
     * The gate's table is bounded (500 fingerprints), and what it does when it is full decides whether
     * the cap means anything: an implementation that evicted the OLDEST entry to make room would evict
     * the storming one — it has been there longest — and the storm would start counting from zero and
     * be reported again, forever.
     *
     * Driven through the facade with more distinct faults than the table holds. Each churn fault is its
     * own exception class, because the fingerprint is (service, type, top frames) and every other lever
     * a test has — the message, the line number — is deliberately not part of it.
     */
    public function testAStormingFingerprintSurvivesAChurnOfMoreDistinctFaultsThanTheGateCanHold(): void
    {
        $client = $this->initClient(new RecordingLogger(), ['fingerprint_cap' => 3]);

        for ($i = 0; $i < 10; $i++) {
            AnvilFeed::captureException(new \RuntimeException('the hot fault'));
        }
        self::assertSame(3, $client->queue()->count(), 'the hot fault is capped before the churn');

        // 520 > RateGate::MAX_TRACKED (500). Every one of these is a new identity the gate has to find
        // room for while the hot one is still live.
        for ($i = 0; $i < 520; $i++) {
            AnvilFeed::captureException($this->aFaultNobodyHasSeenBefore($i));
        }

        $before = $client->queue()->count();
        AnvilFeed::captureException(new \RuntimeException('the hot fault, once more'));

        self::assertSame($before, $client->queue()->count(),
            'the churn made room by evicting the fingerprint that was being capped — so the storm it was '
            . 'holding back is reportable again, which is the cap doing nothing at all');
    }

    /**
     * F3 of the vectors, through the API: `stacktrace[0]` is the crash site. A port that walked the cause
     * chain root-first would produce the same fields in the other order and a different fingerprint for
     * the same fault. Here the event's own frames are reversed and the fingerprint must move.
     */
    public function testFrameOrderIsSignificantForAnEventTheSdkBuilt(): void
    {
        $client = $this->initClient(new RecordingLogger());
        AnvilFeed::captureMessage('Payment reconciliation skipped for 3 accounts', 'warning');
        $payload = $client->queue()->take(1)[0]->payload;

        self::assertGreaterThan(1, count($payload['exception']['stacktrace']),
            'this proof needs at least two frames to reorder');

        $reordered = $payload;
        $reordered['exception']['stacktrace'] = array_reverse($payload['exception']['stacktrace']);

        self::assertNotSame(Fingerprint::ofEvent($payload), Fingerprint::ofEvent($reordered));
    }

    // ------------------------------------------------------------- machinery

    /**
     * @param array<string, mixed> $vector
     * @return array<string, mixed>
     */
    private function eventFor(array $vector, string $message, ?int $line = null): array
    {
        $frames = [];
        foreach ($vector['frames'] as $frame) {
            if ($line !== null) {
                $frame['line'] = $line;
            }
            $frames[] = $frame;
        }

        return [
            'title' => $vector['title'],
            'service' => $vector['service'],
            'exception' => [
                'type' => $vector['exception_type'],
                'message' => $message,
                'stacktrace' => $frames,
            ],
        ];
    }

    /** @param array<string, mixed> $options */
    private function initClient(RecordingLogger $written, array $options = []): Client
    {
        $logger = new Logger($written);
        $client = Client::create(
            Config::fromArray(array_merge([
                'token' => 'anv_srv_0123456789abcdef0123456789abcdef',
                'service' => 'disponio-api',
                'environment' => 'production',
            ], $options), $logger),
            new RecordingTransport(),
            $logger,
            GitContext::none()
        );
        AnvilFeed::init($client);

        return $client;
    }

    /**
     * A throwable of a class that has never existed before. `eval` is the only way to make one at
     * runtime, and a fingerprint churn needs distinct TYPES: the message and the line number are
     * deliberately not part of the identity, and the frames of a loop in one method are identical.
     */
    private function aFaultNobodyHasSeenBefore(int $index): \Throwable
    {
        $class = 'AnvilFeedChurnFault' . $index;
        if (!class_exists($class, false)) {
            eval('class ' . $class . ' extends \RuntimeException {}');
        }
        self::assertTrue(class_exists($class, false), 'the churn needs a distinct exception type');

        return new $class('churn');
    }

    /** The vectors' own note: U+001F, the length in UTF-16 code units, ':', the value. */
    private static function field(string $value): string
    {
        $utf16 = (string) mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');

        return self::UNIT_SEPARATOR . intdiv(strlen($utf16), 2) . ':' . $value;
    }

    /** @return array<string, mixed> */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(self::VECTORS), true, 512, JSON_THROW_ON_ERROR);
    }
}
