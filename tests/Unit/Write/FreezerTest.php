<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Write;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BackupStore;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventLog;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventRecord;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\FreezeMarkers;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunLock;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunMarker;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntent;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntentStore;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\Freezer;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\MutationTransaction;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\WriteOutcome;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;
use YakShaver\ECSKCCustomisation\Tests\Support\WriteRun;

/**
 * Component tests for ticket 32 on a real temporary tree.
 *
 * A simulated crash is a checkpoint that throws. Freezer catches nothing, so the
 * call stops there with the run marker and any intent left the way a process death
 * leaves them. Only the lock is released, by the `finally` that stands in for the
 * operating system releasing a dead holder's lock.
 */
#[CoversClass(Freezer::class)]
#[CoversClass(EventRecord::class)]
#[CoversClass(MutationTransaction::class)]
final class FreezerTest extends TestCase
{
    private const TARGET = 'templates/et_tennis/css/override.css';

    private const ORIGINAL = "/* vendor original */\nbody { color: #333; }\n";

    private TempTree $tree;

    private PackagePaths $paths;

    /** @var list<string> Directories a test made read-only, restored before removal. */
    private array $readOnly = [];

    protected function setUp(): void
    {
        $this->tree  = TempTree::create('ecskc-freezer-');
        $this->paths = new PackagePaths($this->tree->path('site/administrator/components/com_ecskccustom'));

        \mkdir($this->tree->path('outside'), 0755, true);

        foreach (PackagePaths::CREATED_FOLDERS as $folder) {
            \mkdir($this->paths->root() . '/' . $folder, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->readOnly as $directory) {
            @\chmod($directory, 0755);
        }

        $this->tree->remove();
    }

    private function freezer(?Closure $checkpoint = null): Freezer
    {
        return new Freezer($this->paths, $checkpoint);
    }

    private function marker(): string
    {
        return $this->paths->freezeMarker(self::TARGET);
    }

    private function markerExists(): bool
    {
        \clearstatcache(true, $this->marker());

        return \file_exists($this->marker()) || \is_link($this->marker());
    }

    /** A marker directory made by hand, the way an operator might. */
    private function markerDirectory(): string
    {
        $directory = \dirname($this->marker());

        if (!\is_dir($directory)) {
            \mkdir($directory, 0755, true);
        }

        return $directory;
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

    private function isFrozen(): bool
    {
        return (new FreezeMarkers($this->paths))->isFrozen(self::TARGET);
    }

    private function lockIsFree(): bool
    {
        $lock = new RunLock($this->paths);

        if (!$lock->acquire()) {
            return false;
        }

        $lock->release();

        return true;
    }

    private function makeReadOnly(string $directory): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('root ignores directory permissions, so the mutation cannot be made to fail');
        }

        \chmod($directory, 0555);
        $this->readOnly[] = $directory;
    }

    private static function crashAt(string $point): Closure
    {
        return static function (string $reached) use ($point): void {
            if ($reached === $point) {
                throw new RuntimeException('simulated crash at ' . $point);
            }
        };
    }

    /**
     * @param Closure(): WriteOutcome $operation
     */
    private static function crash(Closure $operation): void
    {
        try {
            $operation();
        } catch (RuntimeException $crash) {
            self::assertStringStartsWith('simulated crash at ', $crash->getMessage());

            return;
        }

        self::fail('The operation did not reach its crash point.');
    }

    /**
     * The shared assertions for a call that published nothing and left a clean run.
     */
    private function assertNoRunLeftovers(): void
    {
        self::assertFileDoesNotExist($this->paths->writeIntent());
        self::assertFileDoesNotExist($this->paths->marker());
        self::assertTrue($this->lockIsFree(), 'The call must release the run lock.');
    }

    public function testFreezeCreatesARegularEmptyMarkerBesideTheBackupChain(): void
    {
        $outcome = $this->freezer()->freeze(self::TARGET);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertTrue($outcome->changed);
        self::assertSame($this->paths->backup() . '/' . self::TARGET . '.frozen', $this->marker());
        self::assertFalse(\is_link($this->marker()));
        self::assertTrue(\is_file($this->marker()));
        self::assertSame(0, \filesize($this->marker()));
        self::assertTrue($this->isFrozen());
    }

    public function testUnfreezeRemovesTheMarkerAtTheSamePath(): void
    {
        $this->freezer()->freeze(self::TARGET);

        $outcome = $this->freezer()->unfreeze(self::TARGET);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertTrue($outcome->changed);
        self::assertFalse($this->markerExists());
        self::assertFalse($this->isFrozen());
    }

    public function testABackupWrittenAtTheSameTargetDoesNotDisturbTheMarker(): void
    {
        $source = $this->tree->write('site/' . self::TARGET, self::ORIGINAL);
        $this->freezer()->freeze(self::TARGET);

        $backup = (new BackupStore($this->paths))->preserve(
            self::TARGET,
            1,
            'sha256',
            \hash('sha256', self::ORIGINAL),
            $source
        );

        self::assertTrue($backup->isPreserved(), $backup->reason ?? '');
        self::assertSame(\dirname($this->marker()), \dirname((string) $backup->path));
        self::assertTrue(\is_file($this->marker()));
        self::assertSame(0, \filesize($this->marker()));
        self::assertTrue($this->isFrozen());

        $this->freezer()->unfreeze(self::TARGET);

        self::assertFalse($this->markerExists());
        self::assertSame(self::ORIGINAL, \file_get_contents((string) $backup->path));
    }

    public function testFreezeAndUnfreezeReachTheEventLogWithTheirCorrelationIds(): void
    {
        $frozen   = $this->freezer()->freeze(self::TARGET);
        $unfrozen = $this->freezer()->unfreeze(self::TARGET);
        $records  = $this->records();

        self::assertCount(2, $records);

        self::assertSame(EventRecord::FREEZE, $records[0]->kind);
        self::assertSame(EventRecord::FREEZING, $records[0]->operation);
        self::assertSame(EventRecord::ABSENT, $records[0]->pre);
        self::assertSame(EventRecord::PRESENT, $records[0]->post);
        self::assertSame(EventRecord::PACKAGE, $records[0]->attribution);
        self::assertSame(self::TARGET, $records[0]->target);
        self::assertNotNull($frozen->correlationId);
        self::assertSame($frozen->correlationId, $records[0]->correlationId);

        self::assertSame(EventRecord::UNFREEZE, $records[1]->kind);
        self::assertSame(EventRecord::RELEASE, $records[1]->operation);
        self::assertSame(EventRecord::PRESENT, $records[1]->pre);
        self::assertSame(EventRecord::ABSENT, $records[1]->post);
        self::assertSame(EventRecord::PACKAGE, $records[1]->attribution);
        self::assertNotNull($unfrozen->correlationId);
        self::assertSame($unfrozen->correlationId, $records[1]->correlationId);
        self::assertNotSame($frozen->correlationId, $unfrozen->correlationId);
    }

    public function testAfterASuccessfulFreezeOrUnfreezeTheRunMarkerAndIntentAreAbsent(): void
    {
        $this->freezer()->freeze(self::TARGET);
        $this->assertNoRunLeftovers();

        $this->freezer()->unfreeze(self::TARGET);
        $this->assertNoRunLeftovers();

        self::assertFileExists($this->paths->lock(), 'The lock file is created once and never deleted.');
    }

    public function testUnfreezeOfAnUnfrozenPathIsHarmlessAndAppendsNothing(): void
    {
        $outcome = $this->freezer()->unfreeze(self::TARGET);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertFalse($outcome->changed);
        self::assertNull($outcome->correlationId);
        self::assertStringContainsString('not frozen', $outcome->reason);
        self::assertFalse($this->markerExists());
        self::assertFileDoesNotExist($this->paths->eventLog());
        $this->assertNoRunLeftovers();
    }

    public function testFreezeOfAFrozenPathIsHarmlessAndAppendsNothing(): void
    {
        $this->freezer()->freeze(self::TARGET);

        $outcome = $this->freezer()->freeze(self::TARGET);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertFalse($outcome->changed);
        self::assertNull($outcome->correlationId);
        self::assertStringContainsString('already frozen', $outcome->reason);
        self::assertTrue($this->isFrozen());
        self::assertCount(1, $this->records());
        $this->assertNoRunLeftovers();
    }

    public function testFreezeHoldsTheLockAndPublishesTheRunMarkerBeforeItWritesTheFreezeMarker(): void
    {
        $seen = [];

        $this->freezer(function (string $point) use (&$seen): void {
            $seen[$point] = [
                'lock free'     => $this->lockIsFree(),
                'run marker'    => (new RunMarker($this->paths))->isPresent(),
                'freeze marker' => $this->markerExists(),
            ];
        })->freeze(self::TARGET);

        self::assertSame(
            ['lock free' => false, 'run marker' => true, 'freeze marker' => false],
            $seen[Freezer::LOCKED]
        );
        self::assertSame(
            ['lock free' => false, 'run marker' => true, 'freeze marker' => false],
            $seen[Freezer::INTENT_PUBLISHED]
        );
        self::assertSame(
            ['lock free' => false, 'run marker' => true, 'freeze marker' => true],
            $seen[Freezer::MUTATED]
        );
    }

    public function testAProcessHoldingTheLockPreventsFreezeAndUnfreeze(): void
    {
        $run = WriteRun::holding($this->paths->root(), 'no-marker');

        try {
            $frozen = $this->freezer()->freeze(self::TARGET);

            self::assertSame(TargetStatus::FAULTED, $frozen->status);
            self::assertStringContainsString(RunLock::IN_PROGRESS, $frozen->reason);
            self::assertFalse($this->markerExists(), 'A freeze without the lock leaves the marker path untouched.');
            self::assertFileDoesNotExist($this->paths->marker());
            self::assertFileDoesNotExist($this->paths->writeIntent());

            \touch($this->markerDirectory() . '/' . \basename($this->marker()));

            $unfrozen = $this->freezer()->unfreeze(self::TARGET);

            self::assertSame(TargetStatus::FAULTED, $unfrozen->status);
            self::assertStringContainsString(RunLock::IN_PROGRESS, $unfrozen->reason);
            self::assertTrue($this->isFrozen());
            self::assertFileDoesNotExist($this->paths->marker());
            self::assertFileDoesNotExist($this->paths->eventLog());
        } finally {
            $run->kill();
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function interruptions(): array
    {
        return [
            'a leftover write intent' => ['intent'],
            'a leftover run marker'   => ['marker'],
        ];
    }

    #[DataProvider('interruptions')]
    public function testAnInterruptedRunStopsFreezeBeforeItTouchesAnything(string $leftover): void
    {
        $intent = WriteIntent::begin(['event' => 'write']);

        if ($leftover === 'intent') {
            self::assertTrue($this->intents()->publish($intent));
        } else {
            self::assertTrue((new RunMarker($this->paths))->publish(0));
        }

        $outcome = $this->freezer(static function (string $point): void {
            self::fail('A call facing an interrupted run reached ' . $point);
        })->freeze(self::TARGET);

        self::assertSame(TargetStatus::FAULTED, $outcome->status);
        self::assertStringContainsString('interrupted run', $outcome->reason);
        self::assertFalse($this->markerExists());
        self::assertFileDoesNotExist($this->paths->eventLog());
        self::assertTrue($this->lockIsFree());

        if ($leftover === 'intent') {
            self::assertSame($intent->id, $this->intents()->read()->intent?->id);
            self::assertFileDoesNotExist($this->paths->marker());
        } else {
            self::assertSame('1970-01-01T00:00:00Z', (new RunMarker($this->paths))->read()->value);
            self::assertFileDoesNotExist($this->paths->writeIntent());
        }
    }

    /**
     * @return array<string, array{Closure(string): void}>
     */
    public static function markerShapes(): array
    {
        return [
            'a regular file'     => [static function (string $marker): void {
                \file_put_contents($marker, "held by hand\n");
            }],
            'an unreadable file' => [static function (string $marker): void {
                \touch($marker);
                \chmod($marker, 0);
            }],
            'an empty directory' => [static function (string $marker): void {
                \mkdir($marker);
            }],
        ];
    }

    /**
     * @param Closure(string): void $make
     */
    #[DataProvider('markerShapes')]
    public function testUnfreezeRemovesEveryShapeTheReaderTreatsAsFrozen(Closure $make): void
    {
        $this->markerDirectory();
        $make($this->marker());

        self::assertTrue($this->isFrozen());

        $outcome = $this->freezer()->unfreeze(self::TARGET);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertTrue($outcome->changed);
        self::assertFalse($this->markerExists());
        self::assertSame(EventRecord::UNFREEZE, $this->records()[0]->kind);
        $this->assertNoRunLeftovers();
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function markerLinks(): array
    {
        return [
            'a link to a file' => [false],
            'a dangling link'  => [true],
        ];
    }

    /**
     * Decision 40: the package tree holds no links, so a link at the marker name is not a freeze.
     */
    #[DataProvider('markerLinks')]
    public function testALinkAtTheMarkerNameIsNotAFreezeAndIsLeftAlone(bool $dangling): void
    {
        $this->markerDirectory();
        $outside = $this->tree->write('outside/kept.txt', 'not the marker');
        \symlink($dangling ? $this->marker() . '.gone' : $outside, $this->marker());

        self::assertFalse($this->isFrozen());

        $unfrozen = $this->freezer()->unfreeze(self::TARGET);

        self::assertSame(TargetStatus::OK, $unfrozen->status, $unfrozen->reason);
        self::assertFalse($unfrozen->changed);
        self::assertStringContainsString('not frozen', $unfrozen->reason);

        $frozen = $this->freezer(static function (string $point): void {
            if ($point === Freezer::INTENT_PUBLISHED) {
                self::fail('A link at the marker name must be refused before any intent.');
            }
        })->freeze(self::TARGET);

        self::assertSame(TargetStatus::FAULTED, $frozen->status);
        self::assertStringContainsString('symbolic link', $frozen->reason);
        self::assertTrue(\is_link($this->marker()));
        self::assertSame('not the marker', \file_get_contents($outside));
        self::assertFileDoesNotExist($this->paths->eventLog());
        $this->assertNoRunLeftovers();
    }

    public function testUnfreezeOfANonEmptyDirectoryFaultsWithoutIntentAndLeavesIt(): void
    {
        $this->markerDirectory();
        \mkdir($this->marker());
        \file_put_contents($this->marker() . '/note.txt', 'kept');

        $outcome = $this->freezer(static function (string $point): void {
            if ($point === Freezer::INTENT_PUBLISHED) {
                self::fail('A non-empty directory must fault before any intent is published.');
            }
        })->unfreeze(self::TARGET);

        self::assertSame(TargetStatus::FAULTED, $outcome->status);
        self::assertFalse($outcome->changed);
        self::assertNull($outcome->correlationId);
        self::assertStringContainsString('not empty', $outcome->reason);
        self::assertSame('kept', \file_get_contents($this->marker() . '/note.txt'));
        self::assertTrue($this->isFrozen());
        self::assertFileDoesNotExist($this->paths->eventLog());
        $this->assertNoRunLeftovers();
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function removableShapes(): array
    {
        return [
            'unlink of a file'           => [false],
            'rmdir of an empty directory' => [true],
        ];
    }

    #[DataProvider('removableShapes')]
    public function testAFailedRemovalAfterIntentIsSettledByAMutationFailedEvent(bool $directory): void
    {
        $parent = $this->markerDirectory();
        $directory ? \mkdir($this->marker()) : \touch($this->marker());
        $published = false;

        $outcome = $this->freezer(function (string $point) use ($parent, &$published): void {
            if ($point === Freezer::INTENT_PUBLISHED) {
                $published = $this->intents()->isPresent();
                $this->makeReadOnly($parent);
            }
        })->unfreeze(self::TARGET);

        $records = $this->records();

        self::assertTrue($published, 'The intent was not published before the removal.');
        self::assertSame(TargetStatus::FAULTED, $outcome->status);
        self::assertFalse($outcome->changed);
        self::assertFalse($outcome->intentLeft, $outcome->reason);
        self::assertTrue($this->isFrozen());
        self::assertCount(1, $records);
        self::assertSame(EventRecord::MUTATION_FAILED, $records[0]->kind);
        self::assertSame(EventRecord::RELEASE, $records[0]->operation);
        self::assertNotNull($outcome->correlationId);
        self::assertSame($outcome->correlationId, $records[0]->correlationId);
        $this->assertNoRunLeftovers();
    }

    public function testAFailedCreateAfterIntentIsSettledAndTheMarkerIsStillAbsent(): void
    {
        $outcome = $this->freezer(function (string $point): void {
            if ($point === Freezer::INTENT_PUBLISHED) {
                $this->makeReadOnly(\dirname($this->marker()));
            }
        })->freeze(self::TARGET);

        $records = $this->records();

        self::assertSame(TargetStatus::FAULTED, $outcome->status);
        self::assertFalse($outcome->intentLeft, $outcome->reason);
        self::assertFalse($this->markerExists());
        self::assertCount(1, $records);
        self::assertSame(EventRecord::MUTATION_FAILED, $records[0]->kind);
        self::assertSame(EventRecord::FREEZING, $records[0]->operation);
        self::assertSame(EventRecord::ABSENT, $records[0]->pre);
        self::assertSame(EventRecord::PRESENT, $records[0]->post);
        self::assertSame($outcome->correlationId, $records[0]->correlationId);
        $this->assertNoRunLeftovers();
    }

    public function testAnEventTheLogCannotTakeLeavesTheIntentAndTheRunMarker(): void
    {
        \mkdir($this->paths->eventLog());

        $outcome = $this->freezer()->freeze(self::TARGET);

        self::assertSame(TargetStatus::FAULTED, $outcome->status);
        self::assertTrue($outcome->changed);
        self::assertTrue($outcome->intentLeft);
        self::assertTrue($this->isFrozen());
        self::assertSame($outcome->correlationId, $this->intents()->read()->intent?->id);
        self::assertFileExists($this->paths->marker(), 'A run that leaves an intent has not completed.');
        self::assertTrue($this->lockIsFree());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function intentStates(): array
    {
        return [
            'freeze'   => ['freeze', EventRecord::ABSENT, EventRecord::PRESENT],
            'unfreeze' => ['unfreeze', EventRecord::PRESENT, EventRecord::ABSENT],
        ];
    }

    #[DataProvider('intentStates')]
    public function testTheIntentCarriesFreezeMarkerStatesAndNeverADigest(string $call, string $pre, string $post): void
    {
        if ($call === 'unfreeze') {
            \touch($this->markerDirectory() . '/' . \basename($this->marker()));
        }

        $freezer = $this->freezer(self::crashAt(Freezer::INTENT_PUBLISHED));

        self::crash(fn (): WriteOutcome => $freezer->{$call}(self::TARGET));

        $intent = $this->intents()->read()->intent;

        self::assertInstanceOf(WriteIntent::class, $intent);
        self::assertSame($call, $intent->event['event']);
        self::assertSame($pre, $intent->event['pre']);
        self::assertSame($post, $intent->event['post']);
        self::assertSame($intent->id, $intent->event['id']);
        self::assertNotNull(EventRecord::fromPayload($intent->event));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function digestTransitions(): array
    {
        $digest = \hash('sha256', self::ORIGINAL);

        return [
            'a freeze from a digest'  => [EventRecord::FREEZING, $digest, EventRecord::PRESENT],
            'an unfreeze to a digest' => [EventRecord::RELEASE, EventRecord::PRESENT, $digest],
        ];
    }

    #[DataProvider('digestTransitions')]
    public function testADigestIsNotALegalFreezeMarkerState(string $operation, string $pre, string $post): void
    {
        $this->expectException(InvalidArgumentException::class);

        EventRecord::mutation(
            EventRecord::MUTATION_FAILED,
            $operation,
            self::TARGET,
            $pre,
            $post,
            WriteIntent::mintId()
        );
    }

    public function testACrashBeforeRemovingADirectoryMarkerLeavesTheDirectoryAndTheIntent(): void
    {
        $this->markerDirectory();
        \mkdir($this->marker());

        $freezer = $this->freezer(self::crashAt(Freezer::INTENT_PUBLISHED));

        self::crash(fn (): WriteOutcome => $freezer->unfreeze(self::TARGET));

        self::assertTrue(\is_dir($this->marker()));
        self::assertSame(EventRecord::UNFREEZE, $this->intents()->read()->intent?->event['event']);
        self::assertSame([], $this->records());
    }

    public function testACrashAfterRemovingAFileMarkerLeavesTheAbsenceAndTheIntent(): void
    {
        \touch($this->markerDirectory() . '/' . \basename($this->marker()));

        self::crash(fn (): WriteOutcome => $this->freezer(self::crashAt(Freezer::MUTATED))->unfreeze(self::TARGET));

        self::assertFalse($this->markerExists());
        self::assertSame(EventRecord::UNFREEZE, $this->intents()->read()->intent?->event['event']);
        self::assertSame([], $this->records());
    }

    public function testUnfreezeRemovesAnUnreadableEmptyDirectory(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('root ignores directory permissions, so the marker cannot be made unlistable');
        }

        $this->markerDirectory();
        \mkdir($this->marker());
        \chmod($this->marker(), 0000);
        $this->readOnly[] = $this->marker();

        $outcome = $this->freezer()->unfreeze(self::TARGET);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertTrue($outcome->changed);
        self::assertFalse($this->markerExists());
        self::assertSame(EventRecord::UNFREEZE, $this->records()[0]->kind);
        $this->assertNoRunLeftovers();
    }

    /**
     * Replace `backup/templates` with a symbolic link after the parent directory is bound.
     *
     * @return string The renamed real directory, which still holds the bound inode.
     */
    private function swapBackupAncestorToOutside(): string
    {
        $templates = $this->paths->backup() . '/templates';
        $moved     = $templates . '.real';
        $css       = $this->tree->path('outside/et_tennis/css');

        if (!\is_dir($css)) {
            \mkdir($css, 0755, true);
        }

        \rename($templates, $moved);
        \symlink($this->tree->path('outside'), $templates);

        return $moved;
    }

    public function testASwapOfAnAncestorAfterIntentDoesNotWriteAFreezeOutsideBackup(): void
    {
        $outcome = $this->freezer(function (string $point): void {
            if ($point === Freezer::INTENT_PUBLISHED) {
                $this->swapBackupAncestorToOutside();
            }
        })->freeze(self::TARGET);

        $moved   = $this->paths->backup() . '/templates.real/et_tennis/css/override.css.frozen';
        $outside = $this->tree->path('outside/et_tennis/css/override.css.frozen');

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertTrue($outcome->changed);
        self::assertFileExists($moved);
        self::assertFileDoesNotExist($outside);
        $this->assertNoRunLeftovers();
    }

    public function testASwapOfAnAncestorAfterIntentDoesNotRemoveAnUnfreezeOutsideBackup(): void
    {
        $this->freezer()->freeze(self::TARGET);
        $decoy = $this->tree->write('outside/et_tennis/css/override.css.frozen', '');

        $outcome = $this->freezer(function (string $point): void {
            if ($point === Freezer::INTENT_PUBLISHED) {
                $this->swapBackupAncestorToOutside();
            }
        })->unfreeze(self::TARGET);

        $moved = $this->paths->backup() . '/templates.real/et_tennis/css/override.css.frozen';

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertTrue($outcome->changed);
        self::assertFileDoesNotExist($moved);
        self::assertFileExists($decoy);
        $this->assertNoRunLeftovers();
    }

    public function testALinkedDirectoryUnderBackupCarriesNeitherFreezeNorUnfreezeOutOfTheTree(): void
    {
        \mkdir($this->tree->path('outside/et_tennis/css'), 0755, true);
        $this->tree->symlink(
            'site/administrator/components/com_ecskccustom/backup/templates',
            $this->tree->path('outside')
        );

        $frozen = $this->freezer()->freeze(self::TARGET);

        self::assertSame(TargetStatus::FAULTED, $frozen->status);
        self::assertStringContainsString('symbolic link', $frozen->reason);
        self::assertSame(['.', '..'], \scandir($this->tree->path('outside/et_tennis/css')));

        $outside = $this->tree->write('outside/et_tennis/css/override.css.frozen', '');
        self::assertFalse($this->isFrozen(), 'Decision 40: a marker reached through a link is not a freeze.');

        $unfrozen = $this->freezer()->unfreeze(self::TARGET);

        self::assertSame(TargetStatus::OK, $unfrozen->status, $unfrozen->reason);
        self::assertFalse($unfrozen->changed);
        self::assertFileExists($outside);
        self::assertFileDoesNotExist($this->paths->eventLog());
        $this->assertNoRunLeftovers();
    }

    public function testATargetPathNoRuleCanProduceIsRejectedBeforeTheLock(): void
    {
        try {
            $this->freezer()->freeze('../configuration.php');
            self::fail('A non-canonical target path was accepted.');
        } catch (InvalidArgumentException) {
            self::assertFileDoesNotExist($this->paths->lock());
        }
    }
}
