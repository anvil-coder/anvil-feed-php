<?php

declare(strict_types=1);

namespace AnvilFeed;

/**
 * The deployed release and WHERE it came from, resolved once at init (issue #13).
 *
 * The platform's incident observation needs a release on every sighting to tell "the fix is running"
 * from "a process still on the old code"; an event without one can never take its incident to resolved.
 * A git checkout answers through {@see GitContext}; a zero-downtime layout (Forge, Envoyer, Deployer:
 * `current -> releases/<id>`) has no `.git` at all, and asking every host to export `ANVIL_RELEASE` is a
 * deploy-script change per host. So the first of these wins:
 *
 *   1. `ANVIL_RELEASE` (or the `release` setting) — explicit, as before.
 *   2. `.git/HEAD` — the commit sha, read by {@see GitContext}.
 *   3. A `REVISION` or `.release` file in the application root — Deployer and Envoyer write the
 *      deployed sha there.
 *   4. The application root's real path, when it sits directly under a `releases/` directory — the
 *      release id of a zero-downtime deploy. Not a sha, but unique per deploy.
 *   5. None (FR-6 c: no placeholder, no "unknown").
 *
 * The same rules as GitContext: no subprocess, every file read bounded (the first {@see MAX_BYTES}
 * bytes), and nothing here ever throws — a failed read is "none". The source is kept so the self-test can
 * say which of the five answered, and an operator sees WHY a release is missing instead of a bare "(none)".
 */
final class Release
{
    public const SOURCE_CONFIGURED = 'ANVIL_RELEASE';
    public const SOURCE_GIT = '.git/HEAD';
    public const SOURCE_REVISION_FILE = 'REVISION file';
    public const SOURCE_RELEASE_FILE = '.release file';
    public const SOURCE_RELEASES_DIRECTORY = 'releases/ directory name';
    public const SOURCE_NONE = 'none';

    /** A sha is 7–40 hex characters; a release id written by a tool is a short printable token. */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    /** A REVISION file is one sha; anything longer than this is not one and is not read further. */
    public const MAX_BYTES = 128;

    private function __construct(
        public readonly ?string $value,
        public readonly string $source,
    ) {
    }

    public static function none(): self
    {
        return new self(null, self::SOURCE_NONE);
    }

    /**
     * What is already known without touching the filesystem: the configured value, else git's. For
     * callers that build an {@see EventFactory} by hand with a {@see GitContext} of their own.
     */
    public static function known(?string $configured, GitContext $git): self
    {
        if ($configured !== null && trim($configured) !== '') {
            return new self(trim($configured), self::SOURCE_CONFIGURED);
        }
        if ($git->release !== null) {
            return new self($git->release, self::SOURCE_GIT);
        }

        return self::none();
    }

    /** The whole chain, for the application root the host framework names (Laravel: `basePath()`). */
    public static function discover(?string $configured, GitContext $git, string $applicationRoot): self
    {
        $known = self::known($configured, $git);
        if ($known->value !== null) {
            return $known;
        }
        try {
            $fromFile = self::fromFile($applicationRoot . '/REVISION', self::SOURCE_REVISION_FILE)
                ?? self::fromFile($applicationRoot . '/.release', self::SOURCE_RELEASE_FILE);

            return $fromFile ?? self::fromReleasesDirectory($applicationRoot) ?? self::none();
        } catch (\Throwable) {
            // A release is context, never a reason to fail a boot or a capture (FR-6 b).
            return self::none();
        }
    }

    /** The first line of the file's first MAX_BYTES bytes, trimmed, when it is a token. */
    private static function fromFile(string $path, string $source): ?self
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $head = @file_get_contents($path, false, null, 0, self::MAX_BYTES);
        if (!is_string($head)) {
            return null;
        }
        $firstLine = trim(explode("\n", $head, 2)[0]);

        return preg_match(self::TOKEN_PATTERN, $firstLine) === 1 ? new self($firstLine, $source) : null;
    }

    /**
     * `/home/forge/shop/current` resolves to `/home/forge/shop/releases/20261028104400`; the last segment is
     * the release id when, and only when, its parent is named `releases`. A root that is not a symlink is
     * resolved the same way, so a process started inside the release directory answers too.
     */
    private static function fromReleasesDirectory(string $applicationRoot): ?self
    {
        $real = realpath($applicationRoot);
        if ($real === false) {
            return null;
        }
        $real = rtrim($real, '/');
        if (basename(dirname($real)) !== 'releases') {
            return null;
        }
        $id = basename($real);

        return preg_match(self::TOKEN_PATTERN, $id) === 1 ? new self($id, self::SOURCE_RELEASES_DIRECTORY) : null;
    }
}
