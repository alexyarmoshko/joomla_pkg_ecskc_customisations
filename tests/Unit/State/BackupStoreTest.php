<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\State;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\ProcDescriptor;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BackupOutcome;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BackupStore;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BoundBackupDirectory;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\FreezeMarkers;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

#[CoversClass(BackupStore::class)]
#[CoversClass(BackupOutcome::class)]
#[CoversClass(BoundBackupDirectory::class)]
#[CoversClass(ProcDescriptor::class)]
final class BackupStoreTest extends TestCase
{
    private const TARGET = 'templates/et_tennis/css/override.css';

    private const ORIGINAL = "/* vendor original */\nbody { color: #333; }\n";

    private TempTree $tree;

    private PackagePaths $paths;

    private string $source;

    protected function setUp(): void
    {
        $this->tree   = TempTree::create('ecskc-backup-');
        $this->paths  = new PackagePaths($this->tree->path('com_ecskccustom'));
        $this->source = $this->tree->write('site/' . self::TARGET, self::ORIGINAL);

        \mkdir($this->paths->backup(), 0755, true);
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    private function preserve(?string $digest = null, int $version = 2): BackupOutcome
    {
        return (new BackupStore($this->paths))->preserve(
            self::TARGET,
            $version,
            'sha256',
            $digest ?? \hash('sha256', self::ORIGINAL),
            $this->source
        );
    }

    private function destination(string $bytes = self::ORIGINAL, int $version = 2): string
    {
        return $this->paths->backupFile(self::TARGET, $version, \hash('sha256', $bytes));
    }

    /**
     * @return list<string> Everything beside the backup, hidden entries included.
     */
    private function backupEntries(): array
    {
        $directory = \dirname($this->destination());

        if (!\is_dir($directory)) {
            return [];
        }

        return \array_values(\array_diff(\scandir($directory) ?: [], ['.', '..']));
    }

    /**
     * @return list<string> Publication temporaries of $destination, names only.
     */
    private function publicationTemporaries(string $destination): array
    {
        $pattern = PackagePaths::publicationTemporaryPattern(\basename($destination));

        return \array_values(\array_filter(
            $this->backupEntries(),
            static fn (string $entry): bool => \preg_match($pattern, $entry) === 1
        ));
    }

    /**
     * Run preserve() in a child whose file-size limit is 8 blocks (4096 bytes).
     *
     * @return array{int, string, string} status, stdout, stderr
     */
    private function preserveUnderFileSizeLimit(string $digest, bool $ignoreXfsz = false): array
    {
        $script  = \dirname(__DIR__, 2) . '/Support/preserve_backup.php';
        $limits  = $ignoreXfsz
            ? "trap '' XFSZ; ulimit -c 0; ulimit -f 8; "
            : 'ulimit -c 0; ulimit -f 8; ';
        $command = $limits . \sprintf(
            '%s %s %s %s %s %s %s %s',
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($script),
            \escapeshellarg($this->paths->root()),
            \escapeshellarg(self::TARGET),
            \escapeshellarg('2'),
            \escapeshellarg('sha256'),
            \escapeshellarg($digest),
            \escapeshellarg($this->source)
        );

        $pipes   = [];
        $process = \proc_open(
            ['sh', '-c', $command],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        self::assertIsResource($process, 'Could not start the file-size-limited backup child.');

        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($process), $stdout, $stderr];
    }

    public function testABackupIsPublishedUnderTheMirroredTargetPathWithTheDisplacedBytes(): void
    {
        $outcome = $this->preserve();

        self::assertSame(BackupOutcome::CREATED, $outcome->status, $outcome->reason);
        self::assertTrue($outcome->isPreserved());
        self::assertSame(
            $this->paths->backup() . '/' . self::TARGET . '.v2.' . \hash('sha256', self::ORIGINAL),
            $outcome->path
        );
        self::assertSame(self::ORIGINAL, \file_get_contents($outcome->path));
        self::assertSame(0644, \fileperms($outcome->path) & 0777);
        self::assertSame(
            [\basename($outcome->path)],
            $this->backupEntries(),
            'A completed backup leaves the backup and no temporary.'
        );
        self::assertSame(self::ORIGINAL, $this->tree->read('site/' . self::TARGET));
    }

    public function testARepeatedBackupWritesNothingAndMakesNoDuplicate(): void
    {
        $first = $this->preserve();
        $inode = \fileinode($first->path);

        $second = $this->preserve();

        self::assertSame(BackupOutcome::KEPT, $second->status, $second->reason);
        self::assertSame($first->path, $second->path);
        self::assertSame($inode, \fileinode($second->path), 'A kept backup is not rewritten.');
        self::assertSame([\basename($first->path)], $this->backupEntries());
    }

    public function testAMatchingBackupIsNotKeptWhenTheSourceNoLongerMatchesTheDigest(): void
    {
        // The kept path used to return on the backup hash alone. A stale caller
        // digest then certified the wrong live bytes, and a later write could
        // overwrite an outside edit the kept backup does not contain.
        $destination = $this->destination();

        \mkdir(\dirname($destination), 0755, true);
        \file_put_contents($destination, self::ORIGINAL);
        $inode = \fileinode($destination);

        \file_put_contents($this->source, "outside edit\n");

        $outcome = $this->preserve();

        self::assertSame(BackupOutcome::FAILED, $outcome->status, $outcome->reason);
        self::assertFalse($outcome->isPreserved());
        self::assertStringContainsString('changed after it was hashed', $outcome->reason);
        self::assertSame(self::ORIGINAL, \file_get_contents($destination));
        self::assertSame($inode, \fileinode($destination), 'The existing backup is not rewritten.');
        self::assertSame("outside edit\n", $this->tree->read('site/' . self::TARGET));
        self::assertSame([\basename($destination)], $this->backupEntries());
    }

    public function testALeftoverTemporaryIsNotTakenForAValidBackup(): void
    {
        // What a crash between the copy and the rename leaves: a partial temporary
        // beside the name, and nothing at the name itself. The next run must ignore
        // that leftover. The copy itself being interrupted is the test below.
        $destination = $this->destination();
        $partial     = \dirname($destination) . '/'
            . PackagePaths::publicationTemporaryName(\basename($destination), 'a1b2c3d4e5f6');

        \mkdir(\dirname($destination), 0755, true);
        \file_put_contents($partial, \substr(self::ORIGINAL, 0, 10));

        self::assertFileDoesNotExist($destination, 'An interrupted backup published nothing.');

        $outcome = $this->preserve();

        self::assertSame(BackupOutcome::CREATED, $outcome->status, $outcome->reason);
        self::assertSame(self::ORIGINAL, \file_get_contents($destination));
        self::assertSame(
            \substr(self::ORIGINAL, 0, 10),
            \file_get_contents($partial),
            'The leftover temporary is neither read nor reused.'
        );
    }

    public function testAnInterruptedCopyPublishesNothingAndLeavesAPartialTemporary(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The copy is interrupted with ulimit -f, a Linux shell limit.');
        }

        $bytes  = \str_repeat('A', 1024 * 1024);
        $digest = \hash('sha256', $bytes);
        \file_put_contents($this->source, $bytes);

        $destination = $this->destination($bytes);
        \mkdir(\dirname($destination), 0755, true);

        [$status, $stdout, $stderr] = $this->preserveUnderFileSizeLimit($digest);

        self::assertSame(
            '',
            $stdout,
            'The child must die in the copy, not finish a backup and print a result.'
        );
        self::assertStringContainsString(
            'File size limit exceeded',
            $stderr,
            'The shell reports SIGXFSZ. A preserve() failure would name a reason instead.'
        );
        self::assertSame(
            153,
            $status,
            'SIGXFSZ is 25, and the shell reports it as 128+25 when the copy exceeds ulimit -f 8.'
        );
        self::assertFileDoesNotExist($destination, 'An interrupted copy must not publish the name.');

        $partials = $this->publicationTemporaries($destination);
        self::assertCount(1, $partials, 'The killed copy leaves its temporary beside the name.');
        $partial = \dirname($destination) . '/' . $partials[0];
        $written = \filesize($partial);
        self::assertNotFalse($written);
        self::assertGreaterThan(0, $written);
        self::assertLessThan(\strlen($bytes), $written);

        $outcome = $this->preserve($digest);

        self::assertSame(BackupOutcome::CREATED, $outcome->status, $outcome->reason);
        self::assertSame($bytes, \file_get_contents($destination));
        self::assertFileExists($partial, 'The leftover temporary is neither read nor reused.');
        self::assertSame($written, \filesize($partial));
    }

    public function testAnIncompleteCopyPublishesNothingAndRemovesItsTemporary(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The incomplete copy is reached with ulimit -f, a Linux shell limit.');
        }

        $bytes  = \str_repeat('A', 1024 * 1024);
        $digest = \hash('sha256', $bytes);
        \file_put_contents($this->source, $bytes);

        $destination = $this->destination($bytes);
        \mkdir(\dirname($destination), 0755, true);

        [$status, $stdout, $stderr] = $this->preserveUnderFileSizeLimit($digest, ignoreXfsz: true);

        self::assertSame(2, $status, 'The child reports a preserve() failure, not a signal death.');
        self::assertSame('', $stdout);
        self::assertStringContainsString('did not complete', $stderr);
        self::assertFileDoesNotExist($destination, 'An incomplete copy must not publish the name.');
        self::assertSame(
            [],
            $this->backupEntries(),
            'The copy removes the temporary it created.'
        );
    }

    public function testAnExistingBackupWhoseBytesNoLongerMatchItsNameIsRemade(): void
    {
        $destination = $this->destination();

        \mkdir(\dirname($destination), 0755, true);
        \file_put_contents($destination, \substr(self::ORIGINAL, 0, 10));

        $outcome = $this->preserve();

        self::assertSame(BackupOutcome::REMADE, $outcome->status, $outcome->reason);
        self::assertTrue($outcome->isPreserved());
        self::assertSame(self::ORIGINAL, \file_get_contents($destination));
        self::assertSame([\basename($destination)], $this->backupEntries());
    }

    public function testASymbolicLinkAtTheBackupNameIsReplacedRatherThanTrusted(): void
    {
        $destination = $this->destination();
        $elsewhere   = $this->tree->write('elsewhere/original.css', self::ORIGINAL);

        \mkdir(\dirname($destination), 0755, true);
        \symlink($elsewhere, $destination);

        $outcome = $this->preserve();

        self::assertSame(
            BackupOutcome::REMADE,
            $outcome->status,
            'A link is not a backup, even when the file it points at holds the right bytes.'
        );
        self::assertFalse(\is_link($destination));
        self::assertSame(self::ORIGINAL, \file_get_contents($destination));
        self::assertSame(self::ORIGINAL, $this->tree->read('elsewhere/original.css'));
    }

    public function testACopyThatFailsVerificationPublishesNothingAndLeavesTheTargetUnchanged(): void
    {
        // The caller hashed the target, then something rewrote it before the backup.
        $outcome = $this->preserve(\hash('sha256', "the bytes the caller saw\n"));

        self::assertSame(BackupOutcome::FAILED, $outcome->status);
        self::assertFalse($outcome->isPreserved());
        self::assertStringContainsString('no backup was published', $outcome->reason);
        self::assertSame([], $this->backupEntries(), 'Neither a backup nor a temporary is left.');
        self::assertSame(self::ORIGINAL, $this->tree->read('site/' . self::TARGET));
    }

    public function testADirectoryAtTheBackupNameIsAFailureAndIsLeftAlone(): void
    {
        $destination = $this->destination();

        \mkdir($destination, 0755, true);
        \file_put_contents($destination . '/inside', 'not a backup');

        $outcome = $this->preserve();

        self::assertSame(BackupOutcome::FAILED, $outcome->status);
        self::assertDirectoryExists($destination);
        self::assertSame([\basename($destination)], $this->backupEntries());
        self::assertSame(self::ORIGINAL, $this->tree->read('site/' . self::TARGET));
    }

    public function testAnAbsentSourceIsAFailureAndPublishesNothing(): void
    {
        \unlink($this->source);

        $outcome = $this->preserve();

        self::assertSame(BackupOutcome::FAILED, $outcome->status);
        self::assertStringContainsString('not a regular file', $outcome->reason);
        self::assertFileDoesNotExist($outcome->path);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function crlfPayloads(): array
    {
        return [
            'CRLF with a final newline'    => ["a {\r\n  color: red;\r\n}\r\n"],
            'CRLF with no final newline'   => ["a {\r\n  color: red;\r\n}"],
            'mixed endings and a BOM'      => ["\xEF\xBB\xBFa {\r\n}\nb {}\r"],
        ];
    }

    #[DataProvider('crlfPayloads')]
    public function testLineEndingsSurviveTheBackupByteForByte(string $bytes): void
    {
        \file_put_contents($this->source, $bytes);

        $outcome = $this->preserve(\hash('sha256', $bytes));

        self::assertSame(BackupOutcome::CREATED, $outcome->status, $outcome->reason);
        self::assertSame($this->destination($bytes), $outcome->path);
        self::assertSame($bytes, \file_get_contents($outcome->path));
    }

    public function testASymbolicLinkInTheMirroredTreeIsRefusedAndNothingIsWrittenThroughIt(): void
    {
        $outside = $this->tree->path('outside');
        \mkdir($outside);
        \symlink($outside, $this->paths->backup() . '/templates');

        $outcome = $this->preserve();

        self::assertSame(BackupOutcome::FAILED, $outcome->status);
        self::assertStringContainsString('symbolic link', $outcome->reason);
        self::assertSame(['.', '..'], \scandir($outside));
    }

    public function testAMatchingFileBehindALinkedMirroredDirectoryIsNotKept(): void
    {
        $outside     = $this->tree->path('outside');
        $destination = $this->destination();
        $behind      = $outside . '/et_tennis/css/' . \basename($destination);

        \mkdir(\dirname($behind), 0755, true);
        \file_put_contents($behind, self::ORIGINAL);
        \symlink($outside, $this->paths->backup() . '/templates');

        $outcome = $this->preserve();

        self::assertSame(BackupOutcome::FAILED, $outcome->status);
        self::assertStringContainsString('symbolic link', $outcome->reason);
        self::assertFalse($outcome->isPreserved());
        self::assertSame(['.', '..', 'templates'], \scandir($this->paths->backup()));
        self::assertSame(self::ORIGINAL, \file_get_contents($behind));
    }

    public function testATemporaryThatCannotBeCreatedPublishesNothing(): void
    {
        // The exclusive name is a random token, so this test cannot plant a file
        // at it. The rule that copy() leaves a name it did not create alone has
        // no test without a seam. decoy.css only shows the directory is not emptied.
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('Permission bits do not stop the superuser creating a file.');
        }

        $directory = \dirname($this->destination());
        $decoy     = $directory . '/decoy.css';
        \mkdir($directory, 0755, true);
        \file_put_contents($decoy, 'leave me');
        \chmod($directory, 0555);

        try {
            $outcome = $this->preserve();

            self::assertSame(BackupOutcome::FAILED, $outcome->status);
            self::assertStringContainsString('could not be created', $outcome->reason);
            self::assertFileDoesNotExist($this->destination());
            self::assertSame(['decoy.css'], $this->backupEntries());
            self::assertSame('leave me', \file_get_contents($decoy));
        } finally {
            \chmod($directory, 0755);
        }
    }

    public function testAMirroredDirectoryThatCannotBeCreatedIsAFailureAndPublishesNothing(): void
    {
        \file_put_contents($this->paths->backup() . '/templates', 'not a directory');

        $outcome = $this->preserve();

        self::assertSame(BackupOutcome::FAILED, $outcome->status);
        self::assertStringContainsString('could not be created', $outcome->reason);
        self::assertFileDoesNotExist($this->destination());
        self::assertSame(self::ORIGINAL, $this->tree->read('site/' . self::TARGET));
    }

    public function testAnUnreadableSourceIsAFailureAndPublishesNothing(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('Permission bits do not stop the superuser reading the file.');
        }

        \chmod($this->source, 0000);

        try {
            $outcome = $this->preserve();

            self::assertSame(BackupOutcome::FAILED, $outcome->status);
            self::assertStringContainsString('could not be opened for reading', $outcome->reason);
            self::assertSame([], $this->backupEntries());
            self::assertFileDoesNotExist($this->destination());
        } finally {
            \chmod($this->source, 0644);
        }
    }

    public function testAMissingBackupFolderIsAFailureAndIsNotCreated(): void
    {
        \rmdir($this->paths->backup());

        $outcome = $this->preserve();

        self::assertSame(BackupOutcome::FAILED, $outcome->status);
        self::assertDirectoryDoesNotExist($this->paths->backup());
    }

    public function testAnUnsupportedAlgorithmIsAFailureAndPublishesNothing(): void
    {
        $outcome = (new BackupStore($this->paths))->preserve(
            self::TARGET,
            2,
            'md5',
            \md5(self::ORIGINAL),
            $this->source
        );

        self::assertSame(BackupOutcome::FAILED, $outcome->status);
        self::assertStringContainsString('version 1 supports sha256 only', $outcome->reason);
        self::assertSame([], $this->backupEntries());
    }

    public function testAnUpperCaseDigestNamesTheBackupInLowerCaseAndIsKeptOnTheNextRun(): void
    {
        $upper = \strtoupper(\hash('sha256', self::ORIGINAL));

        $first  = $this->preserve($upper);
        $second = $this->preserve();

        self::assertSame(BackupOutcome::CREATED, $first->status, $first->reason);
        self::assertSame($this->destination(), $first->path);
        self::assertSame(BackupOutcome::KEPT, $second->status, $second->reason);
    }

    public function testEachVersionKeepsItsOwnBackupBesideTheOthers(): void
    {
        $first  = $this->preserve(null, 1);
        $second = $this->preserve(null, 2);

        self::assertNotSame($first->path, $second->path);
        self::assertSame(BackupOutcome::CREATED, $second->status, $second->reason);
        self::assertCount(2, $this->backupEntries());
    }

    public function testATargetWithNoParentDirectoryIsBackedUpInTheBackupFolder(): void
    {
        $source = $this->tree->write('site/override.css', self::ORIGINAL);
        $digest = \hash('sha256', self::ORIGINAL);

        $outcome = (new BackupStore($this->paths))->preserve(
            'override.css',
            2,
            'sha256',
            $digest,
            $source
        );

        self::assertSame(BackupOutcome::CREATED, $outcome->status, $outcome->reason);
        self::assertSame(
            $this->paths->backup() . '/override.css.v2.' . $digest,
            $outcome->path
        );
        self::assertSame(self::ORIGINAL, \file_get_contents($outcome->path));
    }

    public function testABackupIsNeverReadAsAFreezeMarker(): void
    {
        $outcome = $this->preserve();

        self::assertStringEndsNotWith(PackagePaths::FREEZE_MARKER, $outcome->path);
        self::assertFalse((new FreezeMarkers($this->paths))->isFrozen(self::TARGET));
    }

    public function testADigestOfTheWrongWidthIsAContractViolation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->preserve(\md5(self::ORIGINAL));
    }
}
