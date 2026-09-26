<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Write;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path\PathPolicy;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BackupOutcome;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventLog;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventRecord;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntent;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntentStore;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\MutationTransaction;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\TargetWriter;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\WriteOutcome;
use YakShaver\ECSKCCustomisation\Tests\Support\Crash;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

/**
 * A simulated crash is a checkpoint that throws. TargetWriter catches nothing, so
 * the call stops at that point with everything after it undone, the way a process
 * death leaves the tree. The run lock is not taken here: the caller holds it.
 */
#[CoversClass(TargetWriter::class)]
#[CoversClass(WriteOutcome::class)]
#[CoversClass(MutationTransaction::class)]
final class TargetWriterTest extends TestCase
{
    private const TARGET = 'templates/et_tennis/css/override.css';

    private const ORIGINAL = "/* vendor original */\nbody { color: #333; }\n";

    private const PAYLOAD = "/* ecskc */\r\nbody { color: #000; }";

    private TempTree $tree;

    private PackagePaths $paths;

    private PathPolicy $policy;

    private string $payload;

    /** @var list<string> Directories a test made read-only, restored before removal. */
    private array $readOnly = [];

    protected function setUp(): void
    {
        $this->tree  = TempTree::create('ecskc-writer-');
        $this->paths = new PackagePaths($this->tree->path('site/administrator/components/com_ecskccustom'));

        \mkdir($this->tree->path('site/templates/et_tennis/css'), 0755, true);
        \mkdir($this->tree->path('outside'), 0755, true);

        foreach (PackagePaths::CREATED_FOLDERS as $folder) {
            \mkdir($this->paths->root() . '/' . $folder, 0755, true);
        }

        $this->payload = $this->tree->write(
            'site/administrator/components/com_ecskccustom/payload/' . self::TARGET . '.v1',
            self::PAYLOAD
        );
        $this->policy  = new PathPolicy($this->tree->path('site'), $this->paths->root());
    }

    protected function tearDown(): void
    {
        foreach ($this->readOnly as $directory) {
            @\chmod($directory, 0755);
        }

        $this->tree->remove();
    }

    private function writer(?Closure $checkpoint = null, ?PathPolicy $policy = null): TargetWriter
    {
        return new TargetWriter($this->paths, $policy ?? $this->policy, $checkpoint);
    }

    private function create(?Closure $checkpoint = null, string $target = self::TARGET): WriteOutcome
    {
        return $this->writer($checkpoint)->write(
            $target,
            $this->payload,
            'sha256',
            \hash('sha256', self::PAYLOAD),
            null,
            null
        );
    }

    private function replace(?Closure $checkpoint = null, ?int $backupVersion = null): WriteOutcome
    {
        return $this->writer($checkpoint)->write(
            self::TARGET,
            $this->payload,
            'sha256',
            \hash('sha256', self::PAYLOAD),
            \hash('sha256', self::ORIGINAL),
            $backupVersion
        );
    }

    private function removal(?int $backupVersion, ?Closure $checkpoint = null): WriteOutcome
    {
        return $this->writer($checkpoint)->remove(
            self::TARGET,
            'sha256',
            \hash('sha256', self::ORIGINAL),
            $backupVersion
        );
    }

    private function original(): string
    {
        return $this->tree->write('site/' . self::TARGET, self::ORIGINAL);
    }

    private function target(): string
    {
        return $this->tree->path('site/' . self::TARGET);
    }

    /**
     * @return list<string>
     */
    private static function entries(string $directory): array
    {
        return \array_values(\array_diff(\scandir($directory) ?: [], ['.', '..']));
    }

    /**
     * @return list<EventRecord>
     */
    private function records(): array
    {
        return (new EventLog($this->paths))->read()->records();
    }

    private function intents(): WriteIntentStore
    {
        return new WriteIntentStore($this->paths);
    }

    private function makeReadOnly(string $directory): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('root ignores directory permissions, so the move cannot be made to fail');
        }

        \chmod($directory, 0555);
        $this->readOnly[] = $directory;
    }

    /**
     * The shared assertions for a call that stopped before its intent.
     */
    private function assertNothingPublished(WriteOutcome $outcome): void
    {
        self::assertSame(TargetStatus::FAULTED, $outcome->status, $outcome->reason);
        self::assertFalse($outcome->changed);
        self::assertFalse($outcome->intentLeft);
        self::assertNull($outcome->correlationId);
        self::assertNull($outcome->leftover);
        self::assertFalse($this->intents()->isPresent());
        self::assertFileDoesNotExist($this->paths->eventLog());
        self::assertSame([], self::entries($this->paths->staging()));
    }

    public function testACreatePlacesThePayloadAtMode0644WhateverTheUmask(): void
    {
        $umask = \umask(0077);

        try {
            $outcome = $this->create();
        } finally {
            \umask($umask);
        }

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertTrue($outcome->changed);
        self::assertSame(self::PAYLOAD, \file_get_contents($this->target()));
        self::assertSame(0644, \fileperms($this->target()) & 0777);
        self::assertSame([], self::entries($this->paths->staging()));
    }

    public function testACreateCommitsItsEventAttributedToThePackageAndClearsTheIntent(): void
    {
        $outcome = $this->create();
        $records = $this->records();

        self::assertFalse($outcome->intentLeft);
        self::assertFileDoesNotExist($this->paths->writeIntent());
        self::assertCount(1, $records);
        self::assertSame(EventRecord::WRITE, $records[0]->kind);
        self::assertSame(EventRecord::COPY, $records[0]->operation);
        self::assertSame(EventRecord::PACKAGE, $records[0]->attribution);
        self::assertSame(self::TARGET, $records[0]->target);
        self::assertSame(EventRecord::ABSENT, $records[0]->pre);
        self::assertSame(\hash('sha256', self::PAYLOAD), $records[0]->post);
        self::assertNotNull($outcome->correlationId);
        self::assertSame($outcome->correlationId, $records[0]->correlationId);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function modes(): array
    {
        return [
            'owner only'     => [0600],
            'group writable' => [0664],
        ];
    }

    #[DataProvider('modes')]
    public function testAReplacementLeavesTheTargetsModeUnchanged(int $mode): void
    {
        \chmod($this->original(), $mode);

        $outcome = $this->replace(null, 1);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertSame(self::PAYLOAD, \file_get_contents($this->target()));
        self::assertSame($mode, \fileperms($this->target()) & 0777);
        self::assertSame(BackupOutcome::CREATED, $outcome->backup?->status);
        self::assertSame(self::ORIGINAL, \file_get_contents((string) $outcome->backup?->path));
        self::assertSame(\hash('sha256', self::ORIGINAL), $this->records()[0]->pre);
    }

    public function testACrlfPayloadWithNoTrailingNewlineArrivesByteIdentical(): void
    {
        $this->original();

        $outcome = $this->replace();
        $bytes   = (string) \file_get_contents($this->target());

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertSame(self::PAYLOAD, $bytes);
        self::assertStringContainsString("\r\n", $bytes);
        self::assertStringEndsNotWith("\n", $bytes);
    }

    public function testAFailedStagedHashLeavesTheTargetUnchangedAndPublishesNothing(): void
    {
        $this->original();

        $outcome = $this->replace(static function (string $point, ?string $staged): void {
            if ($point === TargetWriter::STAGED) {
                \file_put_contents((string) $staged, "/* damaged in staging */\n");
            }
        });

        $this->assertNothingPublished($outcome);
        self::assertStringContainsString('staged payload hashes to', $outcome->reason);
        self::assertSame(self::ORIGINAL, \file_get_contents($this->target()));
    }

    public function testAStagingCleanupFailureAfterARefusedWriteReportsTheLeftover(): void
    {
        $this->original();

        $outcome = $this->replace(function (string $point, ?string $staged): void {
            if ($point === TargetWriter::STAGED) {
                \file_put_contents((string) $staged, "/* damaged in staging */\n");
                $this->makeReadOnly($this->paths->staging());
            }
        });

        self::assertSame(TargetStatus::FAULTED, $outcome->status);
        self::assertFalse($outcome->changed);
        self::assertFalse($outcome->intentLeft);
        self::assertNull($outcome->correlationId);
        self::assertNotNull($outcome->leftover);
        self::assertFileExists($outcome->leftover);
        self::assertStringContainsString('staged payload hashes to', $outcome->reason);
        self::assertStringContainsString('could not be removed', $outcome->reason);
        self::assertSame(self::ORIGINAL, \file_get_contents($this->target()));
        self::assertFalse($this->intents()->isPresent());
        self::assertFileDoesNotExist($this->paths->eventLog());
    }

    public function testAFailedChmodLeavesTheTargetUnchangedAndPublishesNothing(): void
    {
        $this->original();

        $outcome = $this->replace(static function (string $point, ?string $staged): void {
            if ($point === TargetWriter::PREPARED) {
                \unlink((string) $staged);
            }
        });

        $this->assertNothingPublished($outcome);
        self::assertStringContainsString('mode of the staged payload', $outcome->reason);
        self::assertSame(self::ORIGINAL, \file_get_contents($this->target()));
    }

    public function testAFailedMoveAfterIntentIsSettledByAMutationFailedEvent(): void
    {
        $this->original();
        $published = false;

        $outcome = $this->replace(function (string $point) use (&$published): void {
            if ($point === TargetWriter::INTENT_PUBLISHED) {
                $published = $this->intents()->isPresent();
                $this->makeReadOnly(\dirname($this->target()));
            }
        });

        $records = $this->records();

        self::assertTrue($published, 'The intent was not published before the move.');
        self::assertSame(TargetStatus::FAULTED, $outcome->status);
        self::assertFalse($outcome->changed);
        self::assertFalse($outcome->intentLeft, $outcome->reason);
        self::assertSame(self::ORIGINAL, \file_get_contents($this->target()));
        self::assertCount(1, $records);
        self::assertSame(EventRecord::MUTATION_FAILED, $records[0]->kind);
        self::assertNotNull($outcome->correlationId);
        self::assertSame($outcome->correlationId, $records[0]->correlationId);
        self::assertFileDoesNotExist($this->paths->writeIntent());
        self::assertNull($outcome->leftover);
        self::assertSame([], self::entries($this->paths->staging()));
    }

    public function testAStagingCleanupFailureAfterAFailedMoveReportsTheLeftover(): void
    {
        $this->original();

        $outcome = $this->replace(function (string $point): void {
            if ($point === TargetWriter::INTENT_PUBLISHED) {
                $this->makeReadOnly(\dirname($this->target()));
                $this->makeReadOnly($this->paths->staging());
            }
        });

        self::assertSame(TargetStatus::FAULTED, $outcome->status);
        self::assertFalse($outcome->changed);
        self::assertFalse($outcome->intentLeft, $outcome->reason);
        self::assertNotNull($outcome->leftover);
        self::assertFileExists($outcome->leftover);
        self::assertStringContainsString('could not be moved', $outcome->reason);
        self::assertStringContainsString('could not be removed', $outcome->reason);
        self::assertSame(self::ORIGINAL, \file_get_contents($this->target()));
        self::assertSame(EventRecord::MUTATION_FAILED, $this->records()[0]->kind);
        self::assertFileDoesNotExist($this->paths->writeIntent());
    }

    public function testAFailedMoveWhoseFaultTheLogCannotTakeLeavesTheIntent(): void
    {
        $this->original();

        $outcome = $this->replace(function (string $point): void {
            if ($point === TargetWriter::INTENT_PUBLISHED) {
                \mkdir($this->paths->eventLog());
                $this->makeReadOnly(\dirname($this->target()));
            }
        });

        self::assertSame(TargetStatus::FAULTED, $outcome->status);
        self::assertTrue($outcome->intentLeft);
        self::assertSame(self::ORIGINAL, \file_get_contents($this->target()));
        self::assertSame($outcome->correlationId, $this->intents()->read()->intent?->id);
    }

    public function testARemovalPublishesItsBackupBeforeTheTargetGoes(): void
    {
        $this->original();
        $backup    = $this->paths->backupFile(self::TARGET, 1, \hash('sha256', self::ORIGINAL));
        $beforeGo  = null;

        $outcome = $this->removal(1, function (string $point) use ($backup, &$beforeGo): void {
            if ($point === TargetWriter::INTENT_PUBLISHED) {
                $beforeGo = [\is_file($this->target()), @\file_get_contents($backup)];
            }
        });

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertSame([true, self::ORIGINAL], $beforeGo);
        self::assertFileDoesNotExist($this->target());
        self::assertSame(self::ORIGINAL, \file_get_contents($backup));
        self::assertSame(EventRecord::REMOVE, $this->records()[0]->kind);
        self::assertFileDoesNotExist($this->paths->writeIntent());
    }

    public function testANoCopyRemovalIsRecordedInTheEventLogAndLeavesNoIntent(): void
    {
        $this->original();

        $outcome = $this->removal(null);
        $records = $this->records();

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertTrue($outcome->changed);
        self::assertFileDoesNotExist($this->target());
        self::assertSame([], self::entries($this->paths->backup()));
        self::assertCount(1, $records);
        self::assertSame(EventRecord::REMOVE, $records[0]->kind);
        self::assertSame(EventRecord::REMOVAL, $records[0]->operation);
        self::assertSame(EventRecord::ABSENT, $records[0]->post);
        self::assertSame($outcome->correlationId, $records[0]->correlationId);
        self::assertFileDoesNotExist($this->paths->writeIntent());
    }

    public function testACrashAfterIntentAndBeforeACreateLeavesTheTargetAbsentAndTheIntentWhole(): void
    {
        Crash::assertReached(fn (): WriteOutcome => $this->create(Crash::at(TargetWriter::INTENT_PUBLISHED)));

        $intent = $this->intents()->read()->intent;

        self::assertFileDoesNotExist($this->target());
        self::assertInstanceOf(WriteIntent::class, $intent);
        self::assertSame(EventRecord::WRITE, $intent->event['event']);
        self::assertSame(EventRecord::ABSENT, $intent->event['pre']);
        self::assertSame(\hash('sha256', self::PAYLOAD), $intent->event['post']);
        self::assertSame($intent->id, $intent->event['id']);
        self::assertNotNull(EventRecord::fromPayload($intent->event));
        self::assertSame([], $this->records());
    }

    public function testACrashAfterIntentAndBeforeANoCopyRemovalLeavesTheTargetAndTheIntent(): void
    {
        $this->original();

        Crash::assertReached(fn (): WriteOutcome => $this->removal(null, Crash::at(TargetWriter::INTENT_PUBLISHED)));

        $intent = $this->intents()->read()->intent;

        self::assertSame(self::ORIGINAL, \file_get_contents($this->target()));
        self::assertSame(EventRecord::REMOVE, $intent?->event['event']);
        self::assertSame([], $this->records());
    }

    public function testACrashAfterACreateAndBeforeItsEventLeavesTheFileAndTheIntent(): void
    {
        Crash::assertReached(fn (): WriteOutcome => $this->create(Crash::at(TargetWriter::MUTATED)));

        self::assertSame(self::PAYLOAD, \file_get_contents($this->target()));
        self::assertTrue($this->intents()->read()->isPresent());
        self::assertSame([], $this->records());
    }

    public function testACrashAfterANoCopyRemovalAndBeforeItsEventLeavesTheAbsenceAndTheIntent(): void
    {
        $this->original();

        Crash::assertReached(fn (): WriteOutcome => $this->removal(null, Crash::at(TargetWriter::MUTATED)));

        self::assertFileDoesNotExist($this->target());
        self::assertTrue($this->intents()->read()->isPresent());
        self::assertSame([], $this->records());
    }

    public function testACrashAfterTheEventCommitsLeavesTheIntentWithTheIdAlreadyLogged(): void
    {
        Crash::assertReached(fn (): WriteOutcome => $this->create(Crash::at(TargetWriter::EVENT_COMMITTED)));

        $intent  = $this->intents()->read()->intent;
        $records = $this->records();

        self::assertInstanceOf(WriteIntent::class, $intent);
        self::assertCount(1, $records);
        self::assertSame($intent->id, $records[0]->correlationId);
    }

    public function testARemovalWhoseBackupFailsLeavesTheTargetInPlace(): void
    {
        $this->original();
        \rmdir($this->paths->backup());

        $outcome = $this->removal(1);

        $this->assertNothingPublished($outcome);
        self::assertSame(BackupOutcome::FAILED, $outcome->backup?->status);
        self::assertSame(self::ORIGINAL, \file_get_contents($this->target()));
    }

    public function testARepeatedRemovalOfAnAbsentTargetIsOkAndWritesNothing(): void
    {
        $this->original();
        $this->removal(null);

        $outcome = $this->removal(null);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertFalse($outcome->changed);
        self::assertCount(1, $this->records());
        self::assertFileDoesNotExist($this->paths->writeIntent());
    }

    public function testARepeatedWriteOfTheSamePayloadWritesNothing(): void
    {
        $this->create();

        $outcome = $this->replaceWith(\hash('sha256', self::PAYLOAD));

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertFalse($outcome->changed);
        self::assertCount(1, $this->records());
        self::assertSame([], self::entries($this->paths->staging()));
    }

    private function replaceWith(string $displacedDigest): WriteOutcome
    {
        return $this->writer()->write(
            self::TARGET,
            $this->payload,
            'sha256',
            \hash('sha256', self::PAYLOAD),
            $displacedDigest,
            null
        );
    }

    public function testATargetEditedAfterItsBackupIsNotOverwritten(): void
    {
        $this->original();

        $outcome = $this->replace(function (string $point): void {
            if ($point === TargetWriter::PREPARED) {
                \file_put_contents($this->target(), "/* edited meanwhile */\n");
            }
        }, 1);

        self::assertSame(TargetStatus::FAULTED, $outcome->status);
        self::assertStringContainsString('changed after it was checked', $outcome->reason);
        self::assertSame("/* edited meanwhile */\n", \file_get_contents($this->target()));
        self::assertFalse($this->intents()->isPresent());
        self::assertFileDoesNotExist($this->paths->eventLog());
    }

    public function testStagingOnADifferentFilesystemIsRefusedRatherThanCopied(): void
    {
        $shm = '/dev/shm';

        if (!\is_dir($shm) || !\is_writable($shm)
            || (int) @\stat($shm)['dev'] === (int) @\stat($this->paths->staging())['dev']) {
            self::markTestSkipped('No writable directory on a second filesystem to put the site on.');
        }

        $site      = $shm . '/' . \uniqid('ecskc-writer-', true);
        $directory = $site . '/templates/et_tennis/css';
        \mkdir($directory, 0755, true);

        try {
            $outcome = $this->writer(null, new PathPolicy($site, $this->paths->root()))->write(
                self::TARGET,
                $this->payload,
                'sha256',
                \hash('sha256', self::PAYLOAD),
                null,
                null
            );

            $this->assertNothingPublished($outcome);
            self::assertStringContainsString('different filesystems', $outcome->reason);
            self::assertSame([], self::entries($directory));
        } finally {
            foreach (self::entries($directory) as $entry) {
                @\unlink($directory . '/' . $entry);
            }

            @\rmdir($directory);
            @\rmdir(\dirname($directory));
            @\rmdir(\dirname($directory, 2));
            @\rmdir($site);
        }
    }

    public function testALinkedDirectoryCannotCarryAWriteOutOfTheSiteRoot(): void
    {
        $this->tree->symlink('site/templates/linked', $this->tree->path('outside'));

        $outcome = $this->create(null, 'templates/linked/override.css');

        $this->assertNothingPublished($outcome);
        self::assertStringContainsString('outside the site root', $outcome->reason);
        self::assertSame([], self::entries($this->tree->path('outside')));
    }

    public function testALinkedDirectoryCannotCarryAWriteIntoTheDenyList(): void
    {
        \mkdir($this->tree->path('site/cache'));
        $this->tree->symlink('site/templates/cached', $this->tree->path('site/cache'));

        $outcome = $this->create(null, 'templates/cached/override.css');

        $this->assertNothingPublished($outcome);
        self::assertStringContainsString('deny list', $outcome->reason);
        self::assertSame([], self::entries($this->tree->path('site/cache')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function swapPoints(): array
    {
        return [
            'before the decision at the move'    => [TargetWriter::STAGED],
            'after the backup, before the mode'  => [TargetWriter::PREPARED],
        ];
    }

    #[DataProvider('swapPoints')]
    public function testALinkSwappedInAfterThePlanIsRefusedAtTheMove(string $point): void
    {
        self::assertTrue($this->policy->decide(self::TARGET)->allowed, 'The plan must allow the target.');

        $outcome = $this->create(function (string $reached) use ($point): void {
            if ($reached === $point) {
                $css = $this->tree->path('site/templates/et_tennis/css');
                \rename($css, $css . '-planned');
                $this->tree->symlink('site/templates/et_tennis/css', $this->tree->path('outside'));
            }
        });

        $this->assertNothingPublished($outcome);
        self::assertSame([], self::entries($this->tree->path('outside')));
    }

    public function testATargetPathNoRuleCanProduceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->create(null, '../configuration.php');
    }
}
