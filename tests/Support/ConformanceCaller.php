<?php

declare(strict_types=1);

namespace AnvilFeed\Tests\Support;

use AnvilFeed\AnvilFeed;

/**
 * A stand-in for the customer's own code, in its own file, so that "the stack the SDK emits is the
 * CALLER's" is a claim a test can make about a file that is not the test file and is not the SDK.
 *
 * The line numbers below are pinned by `conformance/valid/message-event.json` and
 * `conformance/valid/test-event.json`: those fixtures are compared to the SDK's output field for field,
 * stacktrace included, so moving a line here means updating the fixture. That is deliberate — the frame
 * IS the contract, and Task 3's review was about an SDK that put three of its own frames in front of it.
 */
final class ConformanceCaller
{
    public const FILE = __FILE__;

    public static function message(string $text): ?string
    {
        return AnvilFeed::captureMessage($text, 'warning');
    }

    public static function testEvent(): ?string
    {
        return AnvilFeed::captureTestEvent();
    }
}
