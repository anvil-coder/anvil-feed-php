<?php

declare(strict_types=1);

namespace AnvilFeed\Tests\Support;

use AnvilFeed\AnvilFeed;

/**
 * A second customer file. Its only reason to exist is that two `captureMessage()` calls from two
 * DIFFERENT files must produce two DIFFERENT fingerprints — the assertion that tells the fixed SDK from
 * the one that led every message event with its own frames, under which every message in an application
 * collapsed into a single incident.
 */
final class AnotherConformanceCaller
{
    public const FILE = __FILE__;

    public static function message(string $text): ?string
    {
        return AnvilFeed::captureMessage($text, 'warning');
    }
}
