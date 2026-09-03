<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Build\LinkSafeCopy;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

/**
 * The copy the build uses for every shipped file. A symbolic link is the
 * authoring accident security review v1 named: `cp -a` would follow it and
 * ship bytes from outside data/payload/.
 */
#[CoversClass(LinkSafeCopy::class)]
final class LinkSafeCopyTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-copy-');
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

        LinkSafeCopy::copy($source, $dest);

        self::assertSame($bytes, $this->tree->read('dest/override.css'));
        self::assertSame(\hash('sha256', $bytes), \hash_file('sha256', $dest));
    }

    public function testASymbolicLinkIsRefusedRatherThanFollowed(): void
    {
        $this->tree->write('outside/secret.css', 'bytes that must not ship');
        $link = $this->tree->symlink('payload/override.css', $this->tree->path('outside/secret.css'));
        $dest = $this->tree->root() . '/staged/override.css';

        try {
            LinkSafeCopy::copy($link, $dest);
            self::fail('A symbolic link must not be copied');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('symbolic link', $e->getMessage());
        }

        self::assertFileDoesNotExist($dest);
    }
}
