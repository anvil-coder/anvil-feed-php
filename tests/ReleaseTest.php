<?php

declare(strict_types=1);

namespace AnvilFeed\Tests;

use AnvilFeed\GitContext;
use AnvilFeed\Release;
use PHPUnit\Framework\TestCase;

/**
 * Issue #13: the release of a deployment that is not a git checkout. One test per source, one per
 * precedence edge, and the three rules the chain shares with GitContext — no subprocess, bounded reads,
 * never a throw. Built by hand on a temporary tree, exactly like GitContextTest, for the same reason.
 */
final class ReleaseTest extends TestCase
{
    private const SHA = 'a3f9c2e1b4d5e6f708192a3b4c5d6e7f80910111';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/anvil-feed-release-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    // ---------------------------------------------------------------- one test per source

    public function testAConfiguredReleaseWinsAndNamesItsSource(): void
    {
        self::write($this->root . '/REVISION', self::SHA . "\n");

        $release = Release::discover(' 1.4.2 ', self::gitWith(self::SHA), $this->root);

        self::assertSame('1.4.2', $release->value, 'trimmed, as Config trims it');
        self::assertSame(Release::SOURCE_CONFIGURED, $release->source);
    }

    public function testTheGitShaIsTheSecondSource(): void
    {
        self::write($this->root . '/REVISION', "deadbeef\n");

        $release = Release::discover(null, self::gitWith(self::SHA), $this->root);

        self::assertSame(self::SHA, $release->value);
        self::assertSame(Release::SOURCE_GIT, $release->source);
    }

    /** Deployer and Envoyer write the deployed sha into REVISION; a zero-downtime release has no .git. */
    public function testARevisionFileInTheApplicationRootIsTheThirdSource(): void
    {
        self::write($this->root . '/REVISION', self::SHA . "\n");

        $release = Release::discover(null, GitContext::none(), $this->root);

        self::assertSame(self::SHA, $release->value);
        self::assertSame(Release::SOURCE_REVISION_FILE, $release->source);
    }

    public function testADotReleaseFileAnswersWhenThereIsNoRevisionFile(): void
    {
        self::write($this->root . '/.release', "2026.10.28-3\n");

        $release = Release::discover(null, GitContext::none(), $this->root);

        self::assertSame('2026.10.28-3', $release->value);
        self::assertSame(Release::SOURCE_RELEASE_FILE, $release->source);
    }

    public function testRevisionIsReadBeforeDotRelease(): void
    {
        self::write($this->root . '/REVISION', "from-revision\n");
        self::write($this->root . '/.release', "from-dot-release\n");

        self::assertSame('from-revision', Release::discover(null, GitContext::none(), $this->root)->value);
    }

    /**
     * The Forge / Envoyer layout: `current` is a symlink to `releases/<id>`, and the application root a
     * host framework names is the symlink. The id is not a sha, but it is unique per deploy, which is all
     * the platform's observation needs to tell the fixed code from the old one.
     */
    public function testTheReleasesDirectoryNameIsTheFourthSourceThroughTheCurrentSymlink(): void
    {
        mkdir($this->root . '/releases/20261028104400', 0o777, true);
        symlink($this->root . '/releases/20261028104400', $this->root . '/current');

        $release = Release::discover(null, GitContext::none(), $this->root . '/current');

        self::assertSame('20261028104400', $release->value);
        self::assertSame(Release::SOURCE_RELEASES_DIRECTORY, $release->source);
    }

    public function testAProcessStartedInsideTheReleaseDirectoryAnswersTheSameId(): void
    {
        mkdir($this->root . '/releases/20261028104400', 0o777, true);

        $release = Release::discover(null, GitContext::none(), $this->root . '/releases/20261028104400/');

        self::assertSame('20261028104400', $release->value);
    }

    public function testADirectoryThatIsNotUnderReleasesIsNotARelease(): void
    {
        mkdir($this->root . '/deploys/20261028104400', 0o777, true);

        $release = Release::discover(null, GitContext::none(), $this->root . '/deploys/20261028104400');

        self::assertNull($release->value, 'a last path segment alone is not a release id');
        self::assertSame(Release::SOURCE_NONE, $release->source);
    }

    public function testWithoutAnySourceThereIsNoReleaseAndNoPlaceholder(): void
    {
        $release = Release::discover(null, GitContext::none(), $this->root);

        self::assertNull($release->value);
        self::assertSame(Release::SOURCE_NONE, $release->source);
    }

    // ---------------------------------------------------------------- precedence

    public function testARevisionFileBeatsTheReleasesDirectory(): void
    {
        mkdir($this->root . '/releases/20261028104400', 0o777, true);
        self::write($this->root . '/releases/20261028104400/REVISION', self::SHA . "\n");

        $release = Release::discover(null, GitContext::none(), $this->root . '/releases/20261028104400');

        self::assertSame(self::SHA, $release->value);
        self::assertSame(Release::SOURCE_REVISION_FILE, $release->source);
    }

    // ---------------------------------------------------------------- the shared rules

    public function testOnlyTheFirstLineOfTheFirst128BytesIsReadAndOnlyATokenIsAccepted(): void
    {
        self::write($this->root . '/REVISION', self::SHA . "\nbranch: main\n");
        self::assertSame(self::SHA, Release::discover(null, GitContext::none(), $this->root)->value,
            'the first line is the sha; a second line is ignored, not glued on');

        self::write($this->root . '/REVISION', str_repeat('a', Release::MAX_BYTES + 1) . "\n");
        self::assertNull(Release::discover(null, GitContext::none(), $this->root)->value,
            'a line that runs past the bound is cut at the bound and is then no token: refused, never a prefix');

        self::write($this->root . '/REVISION', "Deployed by Ada <ada@example.test> on Monday\n");
        self::assertNull(Release::discover(null, GitContext::none(), $this->root)->value,
            'prose is not a release id; the next source is asked instead');

        self::write($this->root . '/REVISION', "\n\n");
        self::assertNull(Release::discover(null, GitContext::none(), $this->root)->value);

        self::write($this->root . '/REVISION', str_repeat(' ', Release::MAX_BYTES + 72) . "deadbeef\n");
        self::assertNull(Release::discover(null, GitContext::none(), $this->root)->value,
            'a token that begins past the bound is never seen: the read stops at MAX_BYTES, whatever follows');
    }

    public function testAnUnreadableFileFallsThroughToTheNextSource(): void
    {
        mkdir($this->root . '/releases/20261028104400', 0o777, true);
        $file = $this->root . '/releases/20261028104400/REVISION';
        self::write($file, self::SHA);
        chmod($file, 0o000);
        if (is_readable($file)) {
            self::markTestSkipped('this user reads files whatever their mode (root)');
        }

        $release = Release::discover(null, GitContext::none(), $this->root . '/releases/20261028104400');

        self::assertSame('20261028104400', $release->value, 'the directory name answers when the file cannot be read');
        self::assertSame(Release::SOURCE_RELEASES_DIRECTORY, $release->source);
    }

    public function testAnApplicationRootThatDoesNotExistIsNoneRatherThanAThrow(): void
    {
        $release = Release::discover(null, GitContext::none(), $this->root . '/gone/releases/20261028104400');

        self::assertNull($release->value);
        self::assertSame(Release::SOURCE_NONE, $release->source);
    }

    /** What a hand-built EventFactory gets: the two sources that need no filesystem, nothing probed. */
    public function testKnownAnswersConfiguredThenGitAndNeverProbesTheFilesystem(): void
    {
        self::assertSame(Release::SOURCE_CONFIGURED, Release::known('1.0', self::gitWith(self::SHA))->source);
        self::assertSame(Release::SOURCE_GIT, Release::known(null, self::gitWith(self::SHA))->source);
        self::assertSame(Release::SOURCE_NONE, Release::known('  ', GitContext::none())->source);
    }

    // ---------------------------------------------------------------- the tree

    private static function gitWith(string $sha): GitContext
    {
        $root = sys_get_temp_dir() . '/anvil-feed-release-git-' . bin2hex(random_bytes(6));
        self::write($root . '/.git/HEAD', $sha . "\n");
        try {
            return GitContext::discover($root);
        } finally {
            self::removeTree($root);
        }
    }

    private static function write(string $path, string $contents): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            self::removeTree($path . '/' . (string) $entry);
        }
        @chmod($path, 0o777);
        @rmdir($path);
    }
}
