<?php

declare(strict_types=1);

namespace AnvilFeed;

/**
 * Three ways the same process can report its own reporting, and all three have to be shut.
 *
 * dark-factory will run this SDK's Java sibling AND host the ingest it posts to. On that machine a fault
 * in the ingest is an exception inside a request to /api/feed/events, which the SDK would capture, queue
 * and post to /api/feed/events — where it fails again. The load is bounded only by how fast the machine
 * can fail.
 *
 *   1. Reentrancy: nothing raised while the sender is running is ever captured.
 *   2. Path: an exception raised while serving a request whose path matches an ignored pattern is not
 *      reported. Default: /api/feed/**.
 *   3. Volume: a per-fingerprint cap (RateGate), because the first two do nothing about an ordinary
 *      application loop.
 */
final class LoopGuard
{
    private bool $sending = false;
    /** @var list<string> */
    private readonly array $patterns;

    /** @param list<string> $ignoredPaths */
    public function __construct(array $ignoredPaths)
    {
        $patterns = [];
        foreach (array_values($ignoredPaths) as $glob) {
            if (is_string($glob) && trim($glob) !== '') {
                $patterns[] = self::toRegex(strtolower(trim($glob)));
            }
        }
        $this->patterns = $patterns;
    }

    public function enterSend(): bool
    {
        if ($this->sending) {
            return false;
        }

        return $this->sending = true;
    }

    public function leaveSend(): void
    {
        $this->sending = false;
    }

    public function isSending(): bool
    {
        return $this->sending;
    }

    public function ignoresPath(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }
        $normalized = self::canonical(self::percentDecoded(self::cutQuery(self::withoutOrigin($path))));
        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * "**" crosses segment boundaries, "*" does not — the usual reading, spelled out because it matters.
     *
     * One addition to the usual reading: a pattern ending in "/**" also matches the directory ITSELF.
     * `/api/feed/**` has to cover `/api/feed`, because that is the route the ingest's own collection
     * endpoint sits on and the plain spelling an operator writes in config is `/api/feed`. Without it the
     * two spellings of the same intention protect different sets of paths, and the one that misses is the
     * default. `/api/feedback` is still not matched: the boundary is the slash, not the prefix.
     */
    private static function toRegex(string $glob): string
    {
        $path = '/' . ltrim($glob, '/');
        $optionalTail = '';
        if (str_ends_with($path, '/**')) {
            $path = substr($path, 0, -3);
            $optionalTail = '(/.*)?';
        }
        $quoted = preg_quote($path, '#');
        $regex = str_replace(['\*\*', '\*'], ['.*', '[^/]*'], $quoted);

        return '#^' . $regex . $optionalTail . '$#';
    }

    /**
     * A host that hands over a full URL rather than a path must get the same answer as one that hands over
     * the path: the SDK's own `request.url` is an absolute URL by the time the contract has seen it, and a
     * guard that only understood paths would report every one of the ingest's own failures.
     */
    private static function withoutOrigin(string $path): string
    {
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://[^/]*#', $path) !== 1) {
            return $path;
        }

        return (string) preg_replace('#^[A-Za-z][A-Za-z0-9+.-]*://[^/]*#', '', $path);
    }

    /**
     * The path as the pattern will be compared against it: lower-cased, `.` and `..` segments resolved,
     * repeated slashes collapsed, a trailing slash dropped.
     *
     * This guard's whole job is to stop a feedback loop, and every one of these shapes can reach a front
     * controller through a rewrite rule or a permissive router: `/API/FEED/events`,
     * `/app/../api/feed/events`, `//api/feed/events`, `/api/feed/`. A guard that answered "not ignored"
     * to any of them would report the ingest's own failure to the ingest.
     */
    private static function canonical(string $path): string
    {
        $resolved = [];
        foreach (explode('/', strtolower($path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($resolved);
                continue;
            }
            $resolved[] = $segment;
        }

        return '/' . implode('/', $resolved);
    }

    /**
     * Percent-decoding, ONCE. A host is expected to hand over the path it actually routed on, and this is
     * the backstop for the ones that do not: `/api/%66eed/events` and `/app/%2e%2e/api/feed/events` both
     * reach a permissive router or a rewrite rule as written, and a guard that matched them raw would
     * answer "not ignored" and report the ingest's own failure to the ingest.
     *
     * Once and not to a fixed point: decoding repeatedly would turn `%2525` into `%` and make a literal
     * path segment somebody really serves match a pattern it does not. `rawurldecode` is exactly one
     * pass, it leaves a sequence that is not valid percent-encoding (`%zz`, a truncated `%`) exactly as
     * it is — a path is not required to be encoded at all — and unlike `urldecode` it does not read `+`
     * as a space, which is a query-string rule and not a path rule. It decodes BEFORE `canonical()`
     * lower-cases, so that `%46` becomes the `F` that `FEED` then lower-cases.
     *
     * The Java twin decodes the same single pass by hand and reads the bytes back as UTF-8, replacing
     * what will not decode; PHP hands the raw bytes on. Either way an undecodable byte matches no ASCII
     * pattern, and neither raises — this runs on the capture path (Java's task-8 review finding 9).
     */
    private static function percentDecoded(string $path): string
    {
        return str_contains($path, '%') ? rawurldecode($path) : $path;
    }

    private static function cutQuery(string $path): string
    {
        $at = strcspn($path, '?#');

        return substr($path, 0, $at);
    }
}
