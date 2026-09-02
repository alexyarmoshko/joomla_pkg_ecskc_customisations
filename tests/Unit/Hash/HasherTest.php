<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Hash;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\HashAlgorithm;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\Hasher;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\HashResult;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

#[CoversClass(Hasher::class)]
#[CoversClass(HashResult::class)]
#[CoversClass(HashAlgorithm::class)]
final class HasherTest extends TestCase
{
    /**
     * The shipped payload, and the hash ticket 08 recorded for it after the offline
     * background rule was appended.
     */
    private const OVERRIDE_CSS         = 'data/payload/templates/et_tennis/css/override.css';
    private const OVERRIDE_CSS_SHA256  = '8d786a53203ee7a3d6e76bec1edc1c5754dc0869c0db829a35e63c674cbe7876';

    private TempTree $tree;
    private Hasher $hasher;

    protected function setUp(): void
    {
        $this->tree   = TempTree::create('ecskc-hash-');
        $this->hasher = new Hasher();
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    private function repositoryPath(string $relative): string
    {
        return \dirname(__DIR__, 3) . '/' . $relative;
    }

    // -----------------------------------------------------------------------
    // The algorithm comes from the rule, and an unsupported one is refused
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function algorithmsVersionOneDoesNotSupport(): array
    {
        return [
            // Available in PHP, and still refused: the constraint is what the package
            // supports, not what the interpreter can compute.
            'sha512'          => ['sha512'],
            'md5'             => ['md5'],
            'crc32b'          => ['crc32b'],
            'unknown to PHP'  => ['not-an-algorithm'],
            'the empty string' => [''],
            // hash() is case-sensitive about its algorithms and so is the declaration.
            'the wrong case'  => ['SHA256'],
        ];
    }

    #[DataProvider('algorithmsVersionOneDoesNotSupport')]
    public function testUnsupportedAlgorithmYieldsAReasonRatherThanADigestForBytes(string $algorithm): void
    {
        $result = $this->hasher->hashBytes($algorithm, 'bytes');

        self::assertFalse($result->isAvailable());
        self::assertNull($result->digest);
        self::assertSame(
            \sprintf(
                'the rule declares hashType %s; version 1 supports sha256 only',
                \var_export($algorithm, true)
            ),
            $result->reason
        );
    }

    #[DataProvider('algorithmsVersionOneDoesNotSupport')]
    public function testUnsupportedAlgorithmYieldsAReasonRatherThanADigestForAFile(string $algorithm): void
    {
        $this->tree->write('a.css', 'bytes');

        $result = $this->hasher->hashFile($algorithm, $this->tree->path('a.css'));

        self::assertFalse($result->isAvailable());
        self::assertNull($result->digest);
        self::assertStringContainsString('version 1 supports sha256 only', (string) $result->reason);
    }

    /**
     * The refusal above is the whole point of reading the algorithm from the
     * declaration: a hasher that fell back to sha256 would answer a question nobody
     * asked, and the digest would look right.
     */
    public function testAnUnsupportedAlgorithmDoesNotFallBackToTheSupportedOne(): void
    {
        $bytes = 'bytes';

        self::assertNotSame(
            \hash('sha256', $bytes),
            $this->hasher->hashBytes('sha512', $bytes)->digest
        );
    }

    public function testTheDeclaredAlgorithmIsTheOneComputed(): void
    {
        // Nothing here names an algorithm; the caller passes what the rule declared.
        $declared = HashAlgorithm::supported()[0];

        self::assertSame(
            \hash($declared, 'bytes'),
            $this->hasher->hashBytes($declared, 'bytes')->digest
        );
    }

    public function testAnUnsupportedAlgorithmHasNoDigestWidthToMeasureAgainst(): void
    {
        self::assertSame(64, HashAlgorithm::digestLength('sha256'));
        self::assertNull(HashAlgorithm::digestLength('sha512'));
        self::assertTrue(HashAlgorithm::isSupported('sha256'));
        self::assertFalse(HashAlgorithm::isSupported('sha512'));
    }

    // -----------------------------------------------------------------------
    // No normalisation: line endings and the final newline are identity
    // -----------------------------------------------------------------------

    /**
     * Decision 14: NTS KBee ships CRLF, Kunena and Helix ship LF, and the same text
     * in the other form is a different file.
     *
     * @return array<string, array{string, string}>
     */
    public static function payloadsAndTheirRecordedDigests(): array
    {
        return [
            'CRLF, no final newline' => [
                "body {\r\n\tcolor: red;\r\n}",
                '8badb39a6e9847813e51f9557b7a72f9d399ce7bc5e071b6f110fc1f46336f9e',
            ],
            'LF, no final newline' => [
                "body {\n\tcolor: red;\n}",
                'bbe7266064ceac13926e9be0adbdd16f3add57b1969befdc0007b5d11cf29e24',
            ],
        ];
    }

    #[DataProvider('payloadsAndTheirRecordedDigests')]
    public function testALineEndingFormHashesToItsOwnRecordedValue(string $bytes, string $recorded): void
    {
        self::assertSame($recorded, $this->hasher->hashBytes('sha256', $bytes)->digest);

        $path = $this->tree->write('style.css', $bytes);

        self::assertSame(
            $recorded,
            $this->hasher->hashFile('sha256', $path)->digest,
            'A file on disk must hash to the same value as the bytes written into it.'
        );
    }

    public function testCrlfAndLfDigestsDifferFromEachOther(): void
    {
        $crlf = $this->hasher->hashBytes('sha256', "a\r\nb");
        $lf   = $this->hasher->hashBytes('sha256', "a\nb");

        self::assertNotSame($crlf->digest, $lf->digest);
    }

    public function testAFinalNewlineChangesTheDigest(): void
    {
        $with    = $this->tree->write('with.css', "body {}\n");
        $without = $this->tree->write('without.css', 'body {}');

        self::assertNotSame(
            $this->hasher->hashFile('sha256', $with)->digest,
            $this->hasher->hashFile('sha256', $without)->digest,
            'Brief section 6 records the trailing-newline case for customisation #11.'
        );
    }

    public function testLeadingAndTrailingWhitespaceIsNotTrimmed(): void
    {
        self::assertNotSame(
            $this->hasher->hashBytes('sha256', ' body {} ')->digest,
            $this->hasher->hashBytes('sha256', 'body {}')->digest
        );
    }

    public function testAByteOrderMarkIsPartOfTheFile(): void
    {
        self::assertNotSame(
            $this->hasher->hashBytes('sha256', "\xEF\xBB\xBFbody {}")->digest,
            $this->hasher->hashBytes('sha256', 'body {}')->digest
        );
    }

    /**
     * The one payload the package ships today, hashed through this class and
     * compared with the value ticket 08 recorded on the DDEV site.
     *
     * This is ticket 15's acceptance clause. It also pins the payload's shape: the
     * recorded digest means nothing unless the bytes it was taken from are still
     * CRLF and still end without a newline.
     */
    public function testTheShippedOverrideCssHashesToTheValueTicketZeroEightRecorded(): void
    {
        $path = $this->repositoryPath(self::OVERRIDE_CSS);

        self::assertFileExists($path);

        $bytes = \file_get_contents($path);

        self::assertIsString($bytes);
        self::assertStringContainsString("\r\n", $bytes, 'The payload is CRLF; decision 14 makes that its identity.');
        self::assertStringEndsWith('}', $bytes, 'The payload ends without a final newline.');

        self::assertSame(self::OVERRIDE_CSS_SHA256, $this->hasher->hashFile('sha256', $path)->digest);
        self::assertSame(self::OVERRIDE_CSS_SHA256, $this->hasher->hashBytes('sha256', $bytes)->digest);
    }

    public function testHashingAFileLeavesItUntouched(): void
    {
        $bytes = "body {\r\n\tcolor: red;\r\n}";
        $path  = $this->tree->write('style.css', $bytes);
        $before = \stat($path);

        $this->hasher->hashFile('sha256', $path);

        $after = \stat($path);

        self::assertIsArray($before);
        self::assertIsArray($after);
        self::assertSame($bytes, $this->tree->read('style.css'));
        self::assertSame($before['size'], $after['size']);
        self::assertSame($before['mtime'], $after['mtime']);
    }

    public function testAnEmptyFileHashesRatherThanReportingAbsent(): void
    {
        $path = $this->tree->write('empty.css', '');

        $result = $this->hasher->hashFile('sha256', $path);

        self::assertTrue($result->isAvailable());
        self::assertSame(\hash('sha256', ''), $result->digest);
    }

    // -----------------------------------------------------------------------
    // A hash that cannot be computed is a reason, never a default
    // -----------------------------------------------------------------------

    public function testAnAbsentFileReportsThatItIsAbsent(): void
    {
        $result = $this->hasher->hashFile('sha256', $this->tree->path('nothing.css'));

        self::assertFalse($result->isAvailable());
        self::assertSame('the file is absent', $result->reason);
        self::assertSame('the file is absent', $result->describe());
    }

    /**
     * A directory and an absent file both fail is_file(). Reporting "absent" for a
     * directory would send an operator looking for the wrong fault.
     */
    public function testADirectoryIsNotReportedAsAnAbsentFile(): void
    {
        $this->tree->write('css/keep.css', 'x');

        $result = $this->hasher->hashFile('sha256', $this->tree->path('css'));

        self::assertFalse($result->isAvailable());
        self::assertSame('a directory is at this path, not a file', $result->reason);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function filesHiddenByAnUnsearchableDirectory(): array
    {
        return [
            // The parent itself is closed.
            'the immediate parent' => ['closed', 'closed/kept.css'],
            // The parent is open, but nothing can reach it to find that out:
            // is_dir() on it answers false exactly as it does for a parent that is
            // not there, which is how the first fix let this case fall through to
            // "absent". Review s1.v21, finding 1.
            'an ancestor above it' => ['closed', 'closed/inner/kept.css'],
            'two levels above'     => ['closed', 'closed/inner/deeper/kept.css'],
        ];
    }

    /**
     * file_exists() answers false for a file behind a directory the account cannot
     * search, so an intact file reads as a missing one. "Absent" would send an
     * operator to restore a file that was never gone instead of to the permission
     * that hid it. Review s1.v20 finding 3, and s1.v21 finding 1 for the ancestor.
     */
    #[DataProvider('filesHiddenByAnUnsearchableDirectory')]
    public function testAFileBehindAnUnsearchableDirectoryIsNotReportedAsAbsent(
        string $closedRelative,
        string $fileRelative
    ): void {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('Permission bits do not stop the superuser searching the directory.');
        }

        $path   = $this->tree->write($fileRelative, 'x');
        $closed = $this->tree->path($closedRelative);
        \chmod($closed, 0000);

        try {
            $result = $this->hasher->hashFile('sha256', $path);

            self::assertFalse($result->isAvailable());
            self::assertSame(
                'a directory on the way to the file cannot be searched, so whether the file '
                . 'is there cannot be told',
                $result->reason
            );
        } finally {
            \chmod($closed, 0755);
        }
    }

    /**
     * The permission diagnostic must not swallow a plain absence, whatever the depth
     * of the path: every directory on the way is searchable here, and the file
     * simply is not there.
     */
    public function testAnAbsentFileUnderSearchableDirectoriesIsStillReportedAsAbsent(): void
    {
        $this->tree->write('open/inner/other.css', 'x');

        $result = $this->hasher->hashFile('sha256', $this->tree->path('open/inner/nothing.css'));

        self::assertFalse($result->isAvailable());
        self::assertSame('the file is absent', $result->reason);

        // And where no part of the path was ever created.
        $missing = $this->hasher->hashFile('sha256', $this->tree->path('gone/inner/nothing.css'));

        self::assertFalse($missing->isAvailable());
        self::assertSame('the file is absent', $missing->reason);
    }

    /**
     * The class answers with a reason or a digest and never throws. Every path
     * function raises a ValueError on a NUL byte, and clearstatcache() gets there
     * first. Review s1.v20, finding 2.
     */
    public function testAPathHoldingANulByteYieldsAReasonRatherThanThrowing(): void
    {
        $result = $this->hasher->hashFile('sha256', $this->tree->path("a\0b.css"));

        self::assertFalse($result->isAvailable());
        self::assertNull($result->digest);
        self::assertSame('the path contains a NUL byte, so it names no file', $result->reason);
    }

    public function testAnUnreadableFileReportsThatItIsUnreadable(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('Permission bits do not stop the superuser reading the file.');
        }

        $path = $this->tree->write('secret.css', 'x');
        \chmod($path, 0000);

        try {
            $result = $this->hasher->hashFile('sha256', $path);

            self::assertFalse($result->isAvailable());
            self::assertSame('the file is unreadable', $result->reason);
        } finally {
            \chmod($path, 0644);
        }
    }

    public function testSomethingThatIsNotARegularFileIsRefused(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('ext-posix is needed to create a FIFO.');
        }

        $path = $this->tree->root() . '/pipe';

        self::assertTrue(\posix_mkfifo($path, 0644));

        try {
            // Hashing a FIFO would block on a reader that never comes. The check is
            // that the class refuses it, which is also why this never opens it.
            $result = $this->hasher->hashFile('sha256', $path);

            self::assertFalse($result->isAvailable());
            self::assertSame('the path names something that is not a regular file', $result->reason);
        } finally {
            // TempTree removes files and links; it does not know about a FIFO, and
            // this test is the only thing that ever makes one.
            \unlink($path);
        }
    }

    /**
     * A run may hash a path it staged moments earlier, and PHP caches stat results
     * per request. A cached "absent" would report a file the package had just
     * written as missing.
     */
    public function testAFileWrittenAfterAFailedReadIsSeen(): void
    {
        $path = $this->tree->path('late.css');

        self::assertFalse($this->hasher->hashFile('sha256', $path)->isAvailable());

        $this->tree->write('late.css', 'late');

        self::assertSame(\hash('sha256', 'late'), $this->hasher->hashFile('sha256', $path)->digest);
    }

    // -----------------------------------------------------------------------
    // Comparing a computed digest with a declared one
    // -----------------------------------------------------------------------

    public function testADigestMatchesItsDeclaredValueInEitherCase(): void
    {
        $result   = $this->hasher->hashBytes('sha256', 'bytes');
        $declared = \hash('sha256', 'bytes');

        self::assertTrue($result->matches($declared));
        self::assertTrue($result->matches(\strtoupper($declared)));
        self::assertFalse($result->matches(\hash('sha256', 'other bytes')));
    }

    /**
     * "We could not read the file" is not evidence that the file is what the rule
     * expects, so an unavailable result matches nothing at all.
     */
    public function testAnUnavailableResultMatchesNothing(): void
    {
        $result = $this->hasher->hashFile('sha256', $this->tree->path('nothing.css'));

        self::assertFalse($result->matches(\hash('sha256', '')));
        self::assertFalse($result->matches(''));
        self::assertFalse($result->matches('the file is absent'));
    }

    public function testAnAvailableResultDescribesItselfWithItsDigest(): void
    {
        $result = $this->hasher->hashBytes('sha256', 'bytes');

        self::assertSame(\hash('sha256', 'bytes'), $result->describe());
    }
}
