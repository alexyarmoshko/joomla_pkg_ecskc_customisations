<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Package;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\ShippedFolderPruner;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

/**
 * An update leaves payload/ and rules/ holding exactly what the new release ships,
 * plus the installer's own guard.
 */
#[CoversClass(ShippedFolderPruner::class)]
final class ShippedFolderPrunerTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-prune-');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    public function testARetiredPayloadAndItsEmptyFoldersGo(): void
    {
        $this->tree->write('release/index.html', 'index');
        $this->tree->write('release/a/kept.php.v1', 'new');
        $this->tree->write('site/index.html', 'index');
        $this->tree->write('site/a/kept.php.v1', 'old');
        $this->tree->write('site/a/b/retired.php.v1', 'retired');
        $this->tree->write('site/a/b/c/retired.php.v2', 'retired');

        $result = (new ShippedFolderPruner())->prune($this->tree->path('site'), $this->tree->path('release'));

        self::assertSame(['a/b/', 'a/b/c/', 'a/b/c/retired.php.v2', 'a/b/retired.php.v1'], $result['removed']);
        self::assertSame([], $result['failed']);
        self::assertSame(['a/kept.php.v1', 'index.html'], $this->files('site'));
        self::assertSame('old', $this->tree->read('site/a/kept.php.v1'), 'Copying is Joomla\'s job; pruning only removes.');
    }

    public function testTheGuardAndItsTemporariesStay(): void
    {
        $this->tree->write('release/index.html', 'index');
        $this->tree->write('site/index.html', 'index');
        $this->tree->write('site/.htaccess', 'deny');
        $this->tree->write('site/.htaccess.0a1b2c.tmp', 'deny');
        $this->tree->write('site/a/.htaccess', 'not the guard');

        $result = (new ShippedFolderPruner())->prune($this->tree->path('site'), $this->tree->path('release'));

        self::assertSame(['a/', 'a/.htaccess'], $result['removed']);
        self::assertSame(['.htaccess', '.htaccess.0a1b2c.tmp', 'index.html'], $this->files('site'));
    }

    public function testAShippedEmptyFolderStays(): void
    {
        \mkdir($this->tree->path('release/empty'), 0755, true);
        \mkdir($this->tree->path('site/empty'), 0755, true);

        $result = (new ShippedFolderPruner())->prune($this->tree->path('site'), $this->tree->path('release'));

        self::assertSame([], $result['removed']);
        self::assertDirectoryExists($this->tree->path('site/empty'));
    }

    public function testALinkIsRemovedAndNotFollowed(): void
    {
        $this->tree->write('release/index.html', 'index');
        $this->tree->write('outside/precious.php', 'precious');
        $this->tree->symlink('site/linked', $this->tree->path('outside'));

        $result = (new ShippedFolderPruner())->prune($this->tree->path('site'), $this->tree->path('release'));

        self::assertSame(['linked'], $result['removed']);
        self::assertFileDoesNotExist($this->tree->path('site/linked'));
        self::assertSame('precious', $this->tree->read('outside/precious.php'));
    }

    public function testAMissingReleaseFolderRemovesNothing(): void
    {
        $this->tree->write('site/a/kept.php.v1', 'old');

        $result = (new ShippedFolderPruner())->prune($this->tree->path('site'), $this->tree->path('absent'));

        self::assertSame(['removed' => [], 'failed' => [], 'unreadable' => []], $result);
        self::assertSame(['a/kept.php.v1'], $this->files('site'));
    }

    public function testAFileThatCannotBeRemovedIsReported(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('root ignores directory permissions');
        }

        $this->tree->write('release/index.html', 'index');
        $this->tree->write('site/a/retired.php.v1', 'retired');
        \chmod($this->tree->path('site/a'), 0555);

        try {
            $result = (new ShippedFolderPruner())->prune($this->tree->path('site'), $this->tree->path('release'));
        } finally {
            \chmod($this->tree->path('site/a'), 0755);
        }

        self::assertSame(['a/retired.php.v1'], $result['failed'], 'A folder still holding it is left, not reported.');
        self::assertSame([], $result['removed']);
    }

    public function testAnUnreadableFolderIsReportedAndTheRestIsStillPruned(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('root ignores directory permissions');
        }

        $this->tree->write('release/index.html', 'index');
        $this->tree->write('site/a/locked/retired.php.v1', 'retired');
        $this->tree->write('site/b/retired.php.v1', 'retired');
        \chmod($this->tree->path('site/a/locked'), 0000);

        try {
            $result = (new ShippedFolderPruner())->prune($this->tree->path('site'), $this->tree->path('release'));
        } finally {
            \chmod($this->tree->path('site/a/locked'), 0755);
        }

        self::assertSame([], $result['failed']);
        self::assertSame(['a/locked/'], $result['unreadable']);
        self::assertSame(['b/', 'b/retired.php.v1'], $result['removed']);
        self::assertFileExists($this->tree->path('site/a/locked/retired.php.v1'));
    }

    public function testAnUnreadableRootIsReportedWithoutThrowing(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('root ignores directory permissions');
        }

        $this->tree->write('release/index.html', 'index');
        $this->tree->write('site/retired.php.v1', 'retired');
        \chmod($this->tree->path('site'), 0000);

        try {
            $result = (new ShippedFolderPruner())->prune($this->tree->path('site'), $this->tree->path('release'));
        } finally {
            \chmod($this->tree->path('site'), 0755);
        }

        self::assertSame(['removed' => [], 'failed' => [], 'unreadable' => ['']], $result);
    }

    /**
     * @return list<string>
     */
    private function files(string $relative): array
    {
        $root  = $this->tree->path($relative);
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = \substr($file->getPathname(), \strlen($root) + 1);
        }

        \sort($files);

        return $files;
    }
}
