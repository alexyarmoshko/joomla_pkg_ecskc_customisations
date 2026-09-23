<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit;

use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the toolchain this phase is built on, not package behaviour.
 */
#[CoversNothing]
final class ToolchainTest extends TestCase
{
    private ?TempTree $tree = null;

    protected function tearDown(): void
    {
        $this->tree?->remove();
        $this->tree = null;
    }

    public function testRunsOnTheRequiredPhpVersion(): void
    {
        self::assertTrue(
            \PHP_VERSION_ID >= 80300,
            \sprintf('The package targets PHP 8.3; this interpreter is %s.', \PHP_VERSION)
        );
    }

    public function testSha256IsAvailableForPayloadHashing(): void
    {
        // Version 1 supports sha256 only; an unsupported declaration is a build
        // error. The algorithm therefore has to exist wherever tests run.
        self::assertContains('sha256', \hash_algos());
    }

    public function testTemporaryTreeRoundTripsCrlfBytesWithoutNormalising(): void
    {
        $this->tree = TempTree::create();

        $bytes = "body {\r\n\tcolor: red;\r\n}";
        $path  = $this->tree->write('templates/et_tennis/css/override.css', $bytes);

        self::assertFileExists($path);
        self::assertSame($bytes, $this->tree->read('templates/et_tennis/css/override.css'));
        self::assertSame(\strlen($bytes), \filesize($path));
        self::assertSame(
            \hash('sha256', $bytes),
            \hash_file('sha256', $path),
            'A payload written to the temporary tree must hash to its own bytes.'
        );
    }

    public function testTemporaryTreePreservesAMissingFinalNewline(): void
    {
        $this->tree = TempTree::create();

        $withNewline    = "x\n";
        $withoutNewline = 'x';

        $this->tree->write('with.txt', $withNewline);
        $this->tree->write('without.txt', $withoutNewline);

        self::assertSame($withNewline, $this->tree->read('with.txt'));
        self::assertSame($withoutNewline, $this->tree->read('without.txt'));
        self::assertNotSame(
            \hash_file('sha256', $this->tree->path('with.txt')),
            \hash_file('sha256', $this->tree->path('without.txt')),
            'A trailing newline must change the hash; brief section 6 records that case for #11.'
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pathsThatMustNotReachTheFilesystem(): array
    {
        return [
            'a traversal escape'      => ['../escaped.txt'],
            'a nested escape'         => ['a/../../escaped.txt'],
            'an absolute path'        => ['/tmp/escaped.txt'],
            'a backslash separator'   => ['..\\escaped.txt'],
            'a dot segment'           => ['./escaped.txt'],
            'a doubled slash'         => ['a//escaped.txt'],
            'an empty path'           => [''],
        ];
    }

    #[DataProvider('pathsThatMustNotReachTheFilesystem')]
    public function testTemporaryTreeRefusesAPathThatWouldEscapeIt(string $relative): void
    {

        $this->tree = TempTree::create();

        $this->expectException(RuntimeException::class);
        $this->tree->write($relative, 'x');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function operationsThatMustNotFollowALink(): array
    {
        return ['write' => ['write'], 'read' => ['read'], 'path' => ['path']];
    }

    /**
     * Every entry point is confined, not just the one a review happened to name.
     *
     * The first fix for this guarded write() alone, so read() still returned bytes
     * from outside the tree and path() still handed the escaping string to its
     * caller. Confinement lives in one place now, and this test proves it by
     * driving all three.
     */
    #[DataProvider('operationsThatMustNotFollowALink')]
    public function testNoOperationFollowsASymbolicLinkOutOfTheTree(string $operation): void
    {
        $this->tree = TempTree::create();

        $outside = \sys_get_temp_dir() . '/ecskc-outside-' . \getmypid();
        @\mkdir($outside);
        \file_put_contents($outside . '/secret.txt', 'outside-bytes');

        try {
            $this->tree->symlink('link', $outside);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/outside the temporary tree/');

            match ($operation) {
                'write' => $this->tree->write('link/secret.txt', 'x'),
                'read'  => $this->tree->read('link/secret.txt'),
                'path'  => $this->tree->path('link/secret.txt'),
            };
        } finally {
            @\unlink($outside . '/secret.txt');
            @\rmdir($outside);
        }
    }

    public function testTheLinkHelperBuildsLinksTicketFourteenWillNeed(): void
    {

        $this->tree = TempTree::create();

        $outside = \sys_get_temp_dir() . '/ecskc-outside-link-' . \getmypid();
        @\mkdir($outside);

        try {
            $link = $this->tree->symlink('nested/link', $outside);

            self::assertTrue(\is_link($link));
            self::assertSame($outside, \readlink($link));
            self::assertStringStartsWith($this->tree->root(), $link);
        } finally {
            @\rmdir($outside);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function operationsThatMustNotReachAnInodeAlias(): array
    {
        return ['write' => ['write'], 'read' => ['read'], 'path' => ['path']];
    }

    /**
     * Every entry point again, for the hard-link case.
     *
     * The first attempt at this guard sat on write() alone, so read() still returned
     * an outside file's bytes through an in-tree hard link. That is the fourth time
     * a fix here was applied to one method instead of the chokepoint, which is why
     * the check now lives in resolve() and why this test drives all three.
     */
    #[DataProvider('operationsThatMustNotReachAnInodeAlias')]
    public function testNoOperationReachesAnOutsideFileThroughAHardLink(string $operation): void
    {
        $this->tree = TempTree::create();

        $outside = \sys_get_temp_dir() . '/ecskc-hard-' . \getmypid();
        @\mkdir($outside);
        \file_put_contents($outside . '/keep.txt', 'KEEP');

        try {
            \link($outside . '/keep.txt', $this->tree->root() . '/hard.txt');

            try {
                match ($operation) {
                    'write' => $this->tree->write('hard.txt', 'OVERWRITTEN'),
                    'read'  => $this->tree->read('hard.txt'),
                    'path'  => $this->tree->path('hard.txt'),
                };
                self::fail(\sprintf('Expected %s() to refuse a hard-linked file.', $operation));
            } catch (RuntimeException $e) {
                self::assertStringContainsString('names', $e->getMessage());
            }

            self::assertSame('KEEP', \file_get_contents($outside . '/keep.txt'));
        } finally {
            @\unlink($outside . '/keep.txt');
            @\rmdir($outside);
        }
    }

    public function testALinkIsUnlinkedRatherThanFollowedDuringCleanup(): void
    {
        $tree = TempTree::create();

        $outside = \sys_get_temp_dir() . '/ecskc-outside-keep-' . \getmypid();
        @\mkdir($outside);
        \file_put_contents($outside . '/keep.txt', 'keep');

        $tree->symlink('link', $outside);
        $tree->remove();

        self::assertFileExists($outside . '/keep.txt', 'Cleanup must not delete through a link.');

        @\unlink($outside . '/keep.txt');
        @\rmdir($outside);
    }

    public function testTemporaryTreeRaisesRatherThanFailingToCleanUpQuietly(): void
    {
        $tree = TempTree::create();
        $tree->write('a/b.txt', 'x');
        $tree->remove();

        $tree->remove();

        self::assertDirectoryDoesNotExist($tree->root());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function prefixesThatMustNotSteerTheRoot(): array
    {
        return [
            'a separator'        => ['sub/tree-'],
            'a traversal'        => ['../tree-'],
            'a backslash'        => ['sub\\tree-'],
            'an absolute path'   => ['/tmp/tree-'],
            'a nested traversal' => ['a/../../tree-'],
        ];
    }

    /**
     * create() is the one route resolve() cannot guard - it builds the root that
     * resolve() measures everything else against. A prefix carrying a separator
     * steered the root through a symbolic link into another directory entirely.
     */
    #[DataProvider('prefixesThatMustNotSteerTheRoot')]
    public function testTemporaryTreePrefixCannotSteerTheRoot(string $prefix): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/bare name/');

        TempTree::create($prefix);
    }

    public function testTemporaryTreeRootStaysUnderTheSystemTemporaryDirectory(): void
    {
        $this->tree = TempTree::create();
        $base = \realpath(\sys_get_temp_dir());

        self::assertIsString($base);
        self::assertStringStartsWith($base . \DIRECTORY_SEPARATOR, $this->tree->root());
    }

    public function testTemporaryTreeIsRemovedCompletely(): void
    {
        $tree = TempTree::create();
        $root = $tree->root();
        $tree->write('nested/deep/file.txt', 'x');

        $tree->remove();

        self::assertDirectoryDoesNotExist($root);
    }
}
