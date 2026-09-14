<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Package;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\ExclusiveCopy;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

/**
 * The exclusive byte-copy BackupStore and TargetWriter share.
 */
#[CoversClass(ExclusiveCopy::class)]
final class ExclusiveCopyTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-exclusive-copy-');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    public function testARegularFileIsCopiedByteForByteIncludingCrlfWithoutAFinalNewline(): void
    {
        $bytes = "line\r\nunterminated";
        $source = $this->tree->write('src/override.css', $bytes);
        $dest   = $this->tree->path('dest/override.css');
        \mkdir($this->tree->path('dest'));

        $leftover = 'unset';
        $kind     = ExclusiveCopy::copy($source, $dest, $leftover);

        self::assertNull($kind);
        self::assertNull($leftover);
        self::assertSame($bytes, $this->tree->read('dest/override.css'));
        self::assertSame(\hash('sha256', $bytes), \hash_file('sha256', $dest));
    }

    public function testASymbolicLinkIsRefusedAndCreatesNothing(): void
    {
        $this->tree->write('outside/secret.css', 'bytes that must not be copied');
        $link = $this->tree->symlink('src/override.css', $this->tree->path('outside/secret.css'));
        $dest = $this->tree->path('dest/override.css');
        \mkdir($this->tree->path('dest'));

        $kind = ExclusiveCopy::copy($link, $dest);

        self::assertSame(ExclusiveCopy::NOT_A_FILE, $kind);
        self::assertFileDoesNotExist($dest);
    }

    public function testAnExistingDestinationIsLeftAlone(): void
    {
        $source = $this->tree->write('src/override.css', 'new bytes');
        $dest   = $this->tree->write('dest/override.css', 'leave me');

        $kind = ExclusiveCopy::copy($source, $dest);

        self::assertSame(ExclusiveCopy::UNCREATABLE, $kind);
        self::assertSame('leave me', $this->tree->read('dest/override.css'));
    }

    public function testDiscardRemovesAFileThisCallCreated(): void
    {
        $path = $this->tree->write('dest/override.css.tmp', 'partial');

        self::assertNull(ExclusiveCopy::discard($path));
        self::assertFileDoesNotExist($path);
    }

    public function testDiscardReportsALeftoverWhenTheDirectoryRefusesUnlink(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('root ignores directory permissions, so unlink cannot be made to fail');
        }

        $directory = $this->tree->path('dest');
        \mkdir($directory);
        $path = $this->tree->write('dest/override.css.tmp', 'partial');
        \chmod($directory, 0555);

        try {
            $why = ExclusiveCopy::discard($path);

            self::assertSame($path . ' could not be removed', $why);
            self::assertFileExists($path);
        } finally {
            \chmod($directory, 0755);
        }
    }
}
