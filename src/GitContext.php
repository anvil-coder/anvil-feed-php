<?php

declare(strict_types=1);

namespace AnvilFeed;

/**
 * release, repo and branch read from the .git directory — by reading files, never by running git.
 *
 * FR-6 b: where the value is determinable at runtime it is filled; where it is not, the field is left out
 * rather than guessed. FR-6 c: no placeholder, no "unknown", no empty string.
 *
 * Spawning a process would be the obvious implementation and is the wrong one: the SDK would need a git
 * binary on the production image, a fork per boot in a container that may have none, and a subprocess in a
 * path that Z4 budgets one millisecond for. Three small files answer the same question.
 */
final class GitContext
{
    private function __construct(
        public readonly ?string $release,
        public readonly ?string $repo,
        public readonly ?string $branch,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public static function discover(string $startDirectory): self
    {
        $gitDir = self::findGitDirectory($startDirectory);
        if ($gitDir === null) {
            return self::none();
        }

        $head = self::readFile($gitDir . '/HEAD');
        if ($head === null) {
            return self::none();
        }

        $branch = null;
        $release = null;
        if (str_starts_with($head, 'ref:')) {
            $ref = trim(substr($head, 4));
            $branch = str_starts_with($ref, 'refs/heads/') ? substr($ref, strlen('refs/heads/')) : $ref;
            $release = self::readFile($gitDir . '/' . $ref) ?? self::packedRef($gitDir, $ref);
        } elseif (preg_match('/^[0-9a-f]{40}$/', $head) === 1) {
            $release = $head;   // a detached HEAD, which is what a CI checkout normally is
        }

        return new self($release, self::originRepo($gitDir), $branch);
    }

    private static function findGitDirectory(string $startDirectory): ?string
    {
        $directory = realpath($startDirectory) ?: $startDirectory;
        for ($depth = 0; $depth < 12; $depth++) {
            $candidate = $directory . '/.git';
            if (is_dir($candidate)) {
                return $candidate;
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                return null;
            }
            $directory = $parent;
        }

        return null;
    }

    /** A packed-refs checkout has no loose ref file for the branch. */
    private static function packedRef(string $gitDir, string $ref): ?string
    {
        $packed = self::readFile($gitDir . '/packed-refs');
        if ($packed === null) {
            return null;
        }
        foreach (explode("\n", $packed) as $line) {
            if (str_ends_with(trim($line), ' ' . $ref)) {
                return substr(trim($line), 0, 40);
            }
        }

        return null;
    }

    /** "owner/name" out of the origin remote, for either the ssh or the https spelling. */
    private static function originRepo(string $gitDir): ?string
    {
        $config = self::readFile($gitDir . '/config');
        if ($config === null
            || preg_match('/\[remote "origin"\][^\[]*url\s*=\s*(\S+)/m', $config, $matches) !== 1) {
            return null;
        }
        $url = preg_replace('/\.git$/', '', $matches[1]) ?? $matches[1];

        return preg_match('#[:/]([^/:]+/[^/]+)$#', $url, $parts) === 1 ? $parts[1] : null;
    }

    private static function readFile(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) > 1_048_576) {
            return null;
        }
        $contents = @file_get_contents($path);

        return is_string($contents) && trim($contents) !== '' ? trim($contents) : null;
    }
}
