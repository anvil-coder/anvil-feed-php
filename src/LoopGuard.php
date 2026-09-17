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
                $patterns[] = self::toRegex(trim($glob));
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
        $normalized = '/' . ltrim(self::cutQuery(self::withoutOrigin($path)), '/');
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

    private static function cutQuery(string $path): string
    {
        $at = strcspn($path, '?#');

        return substr($path, 0, $at);
    }
}
