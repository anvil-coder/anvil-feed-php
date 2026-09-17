<?php

declare(strict_types=1);

namespace AnvilFeed;

/**
 * The ingest's grouping rule, computed locally.
 *
 * The SDK does not need a fingerprint to send an event — the server groups. It needs one to be able to
 * STOP sending: a loop that throws the same exception forty thousand times a minute must be recognised
 * inside the process, before the events reach a queue, a socket and a rate limiter. So this mirrors
 * IncidentFingerprint v1 exactly, including its quirks, and a conformance vector holds it to that.
 *
 *   sha256("incident-fingerprint/v1" + field(shape) + field(service) + field(type) + fields(top frames))
 *
 * where field(v) = U+001F, the length in UTF-16 code units, ':', v. The length prefix is what makes the
 * payload unforgeable by choosing content — a separator could be typed into a file name.
 *
 * Deliberately absent: the exception MESSAGE (messages carry order numbers, and one incident per order is
 * no incident at all) and the LINE NUMBER (adding an import must not regroup every incident).
 */
final class Fingerprint
{
    private const VERSION = 1;
    private const TOP_FRAMES = 3;
    private const UNIT_SEPARATOR = "\u{001F}";

    /**
     * A content hash before the extension: index-a3f9.js, app.4f2c9b.css. Lowercase hex only, exactly as
     * the server's pattern is written — index-A3F9.js is a file somebody named, not a build artefact, and
     * a case-insensitive port here would diverge from the ingest silently.
     */
    private const CONTENT_HASH = '/[-.][0-9a-f]{4,}(?=\.[A-Za-z0-9]+$)/';

    /** @param list<array{file?: ?string, function?: ?string, in_app?: ?bool}> $frames */
    public static function of(string $service, string $exceptionType, string $title, array $frames): string
    {
        return hash('sha256', self::payload($service, $exceptionType, $title, $frames));
    }

    /**
     * Takes the whole event payload, for the call sites that have one rather than its parts.
     *
     * @param array<string, mixed> $payload
     */
    public static function ofEvent(array $payload): string
    {
        $frames = $payload['exception']['stacktrace'] ?? [];

        return self::of(
            self::text($payload['service'] ?? ''),
            self::text($payload['exception']['type'] ?? ''),
            self::text($payload['title'] ?? ''),
            is_array($frames) ? array_values($frames) : []
        );
    }

    /** @param list<array{file?: ?string, function?: ?string, in_app?: ?bool}> $frames */
    public static function payload(string $service, string $exceptionType, string $title, array $frames): string
    {
        $top = self::topFrames($frames);
        $payload = 'incident-fingerprint/v' . self::VERSION;
        $payload .= self::field($top === [] ? 'title' : 'frames');
        $payload .= self::field(trim($service));
        $payload .= self::field(trim($exceptionType));
        if ($top === []) {
            $payload .= self::field(trim($title));
        } else {
            foreach ($top as $frame) {
                $payload .= self::field($frame);
            }
        }

        return $payload;
    }

    /**
     * Strips everything about a path that changes without the code changing. A version-shaped directory is
     * removed ONLY from a path that is demonstrably a deploy artefact — one that carried a scheme and host,
     * or one that carries a content hash — and only when there is exactly one of them. A service that
     * versions its API by directory ("api/1.0/Handler.php") has two files, and merging them would add two
     * unrelated faults into one counter with nothing in the cockpit saying so.
     */
    public static function normalizeFile(?string $file): string
    {
        if ($file === null || trim($file) === '') {
            return '';
        }
        $path = str_replace('\\', '/', trim($file));

        $withoutHost = preg_replace('#^[A-Za-z][A-Za-z0-9+.-]*://[^/]*#', '', $path) ?? $path;
        $deployArtefact = $withoutHost !== $path;

        $path = preg_replace('/^[A-Za-z]:/', '', $withoutHost) ?? $withoutHost;
        $path = self::cutAt(self::cutAt($path, '?'), '#');

        if ($deployArtefact || preg_match(self::CONTENT_HASH, $path) === 1) {
            $path = self::withoutVersionSegment($path);
        }
        $path = preg_replace(self::CONTENT_HASH, '', $path, 1) ?? $path;

        return ltrim($path, '/');
    }

    /**
     * The frames the SDK marked as the customer's own move to the front, keeping their relative order, and
     * then the first three are taken. Without that, the top three frames are the framework's dispatcher on
     * every error the application ever raises, and every fault collapses into one incident.
     *
     * @param list<array{file?: ?string, function?: ?string, in_app?: ?bool}> $frames
     * @return list<string>
     */
    private static function topFrames(array $frames): array
    {
        $inApp = [];
        $rest = [];
        foreach ($frames as $frame) {
            if (!is_array($frame)) {
                continue;
            }
            if (($frame['in_app'] ?? null) === true) {
                $inApp[] = $frame;
            } else {
                $rest[] = $frame;
            }
        }

        return array_map(
            static fn (array $frame): string => self::normalizeFile(
                is_string($frame['file'] ?? null) ? $frame['file'] : null
            ) . '#' . self::text($frame['function'] ?? ''),
            array_slice(array_merge($inApp, $rest), 0, self::TOP_FRAMES)
        );
    }

    private static function withoutVersionSegment(string $path): string
    {
        $segments = explode('/', $path);
        $found = -1;
        for ($i = 0; $i < count($segments) - 1; $i++) {   // never the file name itself
            if (preg_match('/^v?\d+\.\d+(\.\d+)?$/', $segments[$i]) === 1) {
                if ($found >= 0) {
                    return $path;   // two of them are ambiguous, and a guess is a silent merge
                }
                $found = $i;
            }
        }
        if ($found < 0) {
            return $path;
        }
        array_splice($segments, $found, 1);

        return implode('/', $segments);
    }

    private static function field(string $value): string
    {
        return self::UNIT_SEPARATOR . self::utf16Length($value) . ':' . $value;
    }

    /**
     * The length in UTF-16 code units, because the reference implementation counts Java String.length().
     * For ASCII file and function names the three ways of counting agree; for anything above the basic
     * plane they do not, and a fingerprint that disagreed there would open a second incident for the same
     * error on one half of a fleet.
     */
    private static function utf16Length(string $value): int
    {
        $utf16 = @mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');

        return is_string($utf16) ? intdiv(strlen($utf16), 2) : mb_strlen($value);
    }

    /** The server's text(): null is the empty string, never the word "null", and never a raised error. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function cutAt(string $value, string $marker): string
    {
        $at = strpos($value, $marker);

        return $at === false ? $value : substr($value, 0, $at);
    }
}
