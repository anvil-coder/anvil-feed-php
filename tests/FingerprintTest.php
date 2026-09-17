<?php

declare(strict_types=1);

namespace AnvilFeed\Tests;

use AnvilFeed\Fingerprint;
use PHPUnit\Framework\TestCase;

final class FingerprintTest extends TestCase
{
    private const VECTORS = __DIR__ . '/../../../conformance/fingerprint/vectors.json';

    private function vectors(): array
    {
        return json_decode(file_get_contents(self::VECTORS), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testBuildsExactlyTheDocumentedPayload(): void
    {
        foreach ($this->vectors()['payload_vectors'] as $vector) {
            self::assertSame(
                $vector['expected_payload'],
                Fingerprint::payload(
                    $vector['service'],
                    $vector['exception_type'],
                    $vector['title'],
                    $vector['frames']
                ),
                $vector['name']
            );
        }
    }

    /**
     * The digest is never hard-coded: it is the SHA-256 of the payload the vector file pins. That way
     * PHP, Java and the ingest are held to the same bytes without anybody inventing a hash by hand.
     */
    public function testTheDigestIsTheDigestOfThatPayload(): void
    {
        foreach ($this->vectors()['payload_vectors'] as $vector) {
            self::assertSame(
                hash('sha256', $vector['expected_payload']),
                Fingerprint::of(
                    $vector['service'],
                    $vector['exception_type'],
                    $vector['title'],
                    $vector['frames']
                ),
                $vector['name']
            );
        }
    }

    public function testNormalizesFilePathsTheWayTheIngestDoes(): void
    {
        foreach ($this->vectors()['normalize_file_vectors'] as $vector) {
            self::assertSame(
                $vector['expected'],
                Fingerprint::normalizeFile($vector['input']),
                json_encode($vector['input'])
            );
        }
    }

    public function testIsStableAcrossLineNumbersAndMessages(): void
    {
        $a = Fingerprint::of('s', 'T', 'title', [['file' => 'A.php', 'function' => 'f', 'in_app' => true]]);
        $b = Fingerprint::of('s', 'T', 'a completely different title',
            [['file' => 'A.php', 'function' => 'f', 'in_app' => true]]);

        self::assertSame($a, $b, 'with frames present the title is not part of the identity');
    }

    /**
     * Every vector in the conformance file is ASCII, where the three ways of counting a length agree, so
     * the length rule itself is untested by them. The server writes Java's String.length() — UTF-16 code
     * units — and the frame "\u{1F4A5}.php#" is nine bytes, six characters and seven code units, so a
     * payload that counted either of the other two would open a second incident for the same fault on
     * whichever half of a fleet ran the other implementation.
     */
    public function testCountsLengthInUtf16CodeUnitsTheWayTheServerDoes(): void
    {
        $payload = Fingerprint::payload('s', 'T', 'title', [
            ['file' => "\u{1F4A5}.php", 'function' => '', 'in_app' => true],
        ]);

        self::assertStringEndsWith("\u{001F}7:\u{1F4A5}.php#", $payload,
            'a surrogate pair counts as two code units, not as one character and not as four bytes');
    }

    /** The shape every caller in the SDK actually has: a built payload, not four loose arguments. */
    public function testReadsTheWholeEventPayloadAndSurvivesOneWithNothingInIt(): void
    {
        $frames = [['file' => 'app/Checkout.php', 'function' => 'charge', 'in_app' => true]];

        self::assertSame(
            Fingerprint::of('shop-api', 'RuntimeException', 'Payment failed', $frames),
            Fingerprint::ofEvent([
                'service' => 'shop-api',
                'title' => 'Payment failed',
                'exception' => ['type' => 'RuntimeException', 'stacktrace' => $frames],
            ])
        );
        self::assertSame(Fingerprint::of('', '', '', []), Fingerprint::ofEvent([]));
    }
}
