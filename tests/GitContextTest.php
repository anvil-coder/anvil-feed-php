<?php

declare(strict_types=1);

namespace AnvilFeed\Tests;

use AnvilFeed\GitContext;
use PHPUnit\Framework\TestCase;

/**
 * FR-6 b/c: filled where determinable, left out where not — no placeholder and no "unknown".
 *
 * Built by hand, never by running git: the class under test reads files precisely so that no subprocess
 * and no git binary is needed, and a test that shells out would prove a different thing on a machine
 * with a different git.
 */
final class GitContextTest extends TestCase
{
    private const SHA = 'a3f9c2e1b4d5e6f708192a3b4c5d6e7f80910111';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/anvil-feed-git-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            self::removeTree($path . '/' . (string) $entry);
        }
        @rmdir($path);
    }

    private static function write(string $path, string $contents): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);
    }

    /** An ordinary checkout: `.git` is a directory. */
    public function testItReadsTheBranchTheShaAndTheOriginRepo(): void
    {
        self::write($this->root . '/.git/HEAD', "ref: refs/heads/main\n");
        self::write($this->root . '/.git/refs/heads/main', self::SHA . "\n");
        self::write($this->root . '/.git/config',
            "[remote \"origin\"]\n\turl = https://forge.example/shop/api.git\n");

        $context = GitContext::discover($this->root);

        self::assertSame('main', $context->branch);
        self::assertSame(self::SHA, $context->release);
        self::assertSame('shop/api', $context->repo);
    }

    // ---------------------------------------------------------------- a .git FILE (finding 11)

    /**
     * The layout `git worktree add --detach` leaves behind. The checkout's `.git` is a FILE holding
     * `gitdir: …`, and the directory it points at is a per-worktree one under the main repository that
     * carries that worktree's own HEAD. Before this, every deployment made this way reported no release
     * and no branch at all — FR-6 b's "filled where determinable" silently reading "where the layout is
     * the common one".
     */
    public function testAGitFilePointingAtAWorktreeGitDirectoryIsFollowed(): void
    {
        $perWorktree = $this->root . '/main/.git/worktrees/release';
        self::write($perWorktree . '/HEAD', self::SHA . "\n");
        self::write($perWorktree . '/config',
            "[remote \"origin\"]\n\turl = https://forge.example/shop/api.git\n");
        self::write($this->root . '/release/.git', 'gitdir: ' . $perWorktree . "\n");
        mkdir($this->root . '/release/src', 0o777, true);

        $context = GitContext::discover($this->root . '/release/src');

        self::assertSame(self::SHA, $context->release);
        self::assertSame('shop/api', $context->repo);
    }

    /** A submodule's `.git` file is written relative to the checkout, so the pointer must be too. */
    public function testARelativeGitdirPointerIsResolvedAgainstTheCheckout(): void
    {
        $perWorktree = $this->root . '/main/.git/worktrees/release';
        self::write($perWorktree . '/HEAD', "ref: refs/heads/main\n");
        self::write($this->root . '/release/.git', "gitdir: ../main/.git/worktrees/release\n");

        self::assertSame('main', GitContext::discover($this->root . '/release')->branch);
    }

    /**
     * Followed ONCE: a gitdir that names another `.git` FILE is not a shape git produces, and a chain is
     * how a loop gets into a path Z4 budgets a millisecond for.
     *
     * Asserted against a SENTINEL rather than against null: where nothing is found the walk continues
     * upwards, and on a machine whose temp directory lives inside a checkout that finds the real one.
     * What must hold either way is that the second hop's sha is never the answer.
     */
    public function testAGitdirPointerIsNotChased(): void
    {
        $secondHop = str_repeat('c', 40);
        self::write($this->root . '/far/HEAD', $secondHop . "\n");
        self::write($this->root . '/hop/.git', 'gitdir: ' . $this->root . "/far\n");
        self::write($this->root . '/.git', 'gitdir: ' . $this->root . "/hop/.git\n");

        self::assertNotSame($secondHop, GitContext::discover($this->root)->release,
            'one hop, not a chain');
    }
}
