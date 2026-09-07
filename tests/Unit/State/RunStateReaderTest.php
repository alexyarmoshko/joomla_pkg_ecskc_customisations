<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunLock;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunMarker;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunPhase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunState;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunStateReader;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\StateFile;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntent;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntentStore;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;
use YakShaver\ECSKCCustomisation\Tests\Support\WriteRun;

#[CoversClass(RunStateReader::class)]
#[CoversClass(RunState::class)]
#[CoversClass(RunPhase::class)]
final class RunStateReaderTest extends TestCase
{
    /** @var array<string, mixed> */
    private const EVENT = ['event' => 'write', 'target' => 'index.php', 'pre' => 'absent'];

    private TempTree $tree;

    private PackagePaths $paths;

    protected function setUp(): void
    {
        $this->tree  = TempTree::create('ecskc-runstate-');
        $this->paths = new PackagePaths($this->tree->path('com_ecskccustom'));

        \mkdir($this->paths->state(), 0755, true);
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    private function state(): RunState
    {
        return (new RunStateReader($this->paths))->read();
    }

    public function testAFreeLockAndNoMarkerIsClean(): void
    {
        $state = $this->state();

        self::assertTrue($state->isClean());
        self::assertSame(RunPhase::CLEAN, $state->phase);
        self::assertFalse($state->isFaulted());
        self::assertFalse($state->hasLeftoverIntent());
    }

    public function testAHeldLockIsALiveRunWhetherOrNotAMarkerIsThere(): void
    {
        $lock = new RunLock($this->paths);
        self::assertTrue($lock->acquire());

        // Between acquiring the lock and renaming the marker into place.
        self::assertTrue(
            $this->state()->isLive(),
            'Lock held and marker absent is a live run, not a clean tree.'
        );

        self::assertTrue((new RunMarker($this->paths))->publish());
        self::assertTrue($this->state()->isLive());

        // Between removing the marker and releasing the lock.
        self::assertTrue((new RunMarker($this->paths))->remove());
        self::assertTrue($this->state()->isLive());

        $lock->release();
        self::assertTrue($this->state()->isClean(), 'The run is over once the lock is released.');
    }

    public function testAMarkerLeftBehindByAFreeLockIsAnInterruptedRun(): void
    {
        self::assertTrue((new RunMarker($this->paths))->publish(1_757_275_985));

        $state = $this->state();

        self::assertTrue($state->wasInterrupted());
        self::assertSame('2025-09-07T20:13:05Z', $state->startedAt->value);
        self::assertFalse($state->isFaulted());
        self::assertFalse($state->hasLeftoverIntent());
    }

    public function testAnInterruptionIsReportedOnlyOnceTheLockIsReleased(): void
    {
        $run = WriteRun::holding($this->paths->root(), 'marker');

        self::assertFileExists($this->paths->marker());
        self::assertTrue(
            $this->state()->isLive(),
            'A marker under a held lock belongs to the run that is still going.'
        );
        self::assertFalse($this->state()->wasInterrupted());

        $run->kill();

        $state = $this->state();
        self::assertTrue(
            $state->wasInterrupted(),
            'The same marker, with the lock released by the holder dying, is a crash.'
        );
        self::assertTrue($state->startedAt->isKnown());
    }

    public function testALeftoverIntentUnderAFreeLockIsAMutationThatMayNotBeRecorded(): void
    {
        $id  = \str_repeat('a1b2', 8);
        $run = WriteRun::holding($this->paths->root(), 'marker+intent', $id);

        self::assertTrue($this->state()->isLive());
        self::assertFalse(
            $this->state()->hasLeftoverIntent(),
            'An intent under a held lock may be the holder\'s own mutation of a moment ago.'
        );

        $run->kill();

        $state = $this->state();
        self::assertTrue($state->wasInterrupted());
        self::assertTrue($state->hasLeftoverIntent());

        // Readable as the record it is, which is what recovery matches on.
        self::assertSame($id, (new WriteIntentStore($this->paths))->read()->intent?->id);
    }

    public function testAMalformedMarkerIsAnInterruptionCarryingItsFault(): void
    {
        \file_put_contents($this->paths->marker(), "a run began\n");

        $state = $this->state();

        self::assertTrue($state->wasInterrupted(), 'A marker nobody can read is never a clean history.');
        self::assertTrue($state->isFaulted());
        self::assertCount(1, $state->faults());
        self::assertStringContainsString($this->paths->marker(), $state->faults()[0]);
    }

    public function testAMalformedIntentIsAnInterruptionCarryingItsFault(): void
    {
        \file_put_contents($this->paths->writeIntent(), '{"id":"a1b2c3d4","event":{');

        $state = $this->state();

        self::assertTrue($state->wasInterrupted());
        self::assertTrue($state->hasLeftoverIntent());
        self::assertTrue($state->isFaulted());
        self::assertStringContainsString($this->paths->writeIntent(), $state->faults()[0]);
    }

    public function testAnAbandonedTemporaryOnItsOwnIsStillClean(): void
    {
        $temporary = (new StateFile($this->paths->marker()))->beginPublication("2025-09-07T20:13:05Z\n");
        self::assertIsString($temporary);

        self::assertTrue(
            $this->state()->isClean(),
            'A death before the marker was published leaves nothing to recover.'
        );

        // And the next write run clears the leftover.
        $lock = new RunLock($this->paths);
        self::assertTrue($lock->acquire());
        self::assertSame([], (new RunMarker($this->paths))->sweepTemporaries());
        $lock->release();

        self::assertFileDoesNotExist($temporary);
    }

    public function testAProcessThatLosesTheLockRaceTouchesNeitherRecord(): void
    {
        $winner = new RunLock($this->paths);
        self::assertTrue($winner->acquire());
        self::assertTrue((new RunMarker($this->paths))->publish(1_757_275_985));
        self::assertTrue((new WriteIntentStore($this->paths))->publish(WriteIntent::of('winner1', self::EVENT)));

        $marker = \file_get_contents($this->paths->marker());
        $intent = \file_get_contents($this->paths->writeIntent());

        // What a second write run does: ask for the lock, and stop when refused.
        $loser = new RunLock($this->paths);

        if ($loser->acquire()) {
            self::fail('The second run must not acquire a held lock.');
        }

        self::assertSame($marker, \file_get_contents($this->paths->marker()));
        self::assertSame($intent, \file_get_contents($this->paths->writeIntent()));
        self::assertTrue($this->state()->isLive(), 'The loser does not read the tree as clean.');

        $winner->release();
    }

    public function testALockThatCannotBeOpenedIsNeverACleanTree(): void
    {
        $state = (new RunStateReader(new PackagePaths($this->tree->path('missing'))))->read();

        self::assertFalse($state->isClean(), 'A tree the package cannot read is not a clean one.');
        self::assertTrue($state->isFaulted());
        self::assertStringContainsString('run.lock', $state->faults()[0]);
        self::assertFalse($state->startedAt->isDetermined());
    }

    public function testAWriteRunCannotBeginWhileTheRecordsAreBeingRead(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('The rendezvous needs posix_mkfifo().');
        }

        // A marker that is a FIFO. The reading process stops on it inside read(),
        // which is the only way to look at what read() holds while it runs, and a
        // FIFO also says when it got there: an open for writing completes only once
        // the reader's open for reading has, so the two are one event.
        self::assertTrue(\posix_mkfifo($this->paths->marker(), 0644));

        $reading = WriteRun::reading($this->paths->root());
        $writer  = null;

        try {
            // Returns with the reading process inside read(), past the lock and
            // waiting on bytes this writer never sends. Nothing here is timed:
            // the reader stays there until it is killed, so a single attempt
            // settles it. A reader that took the lock, dropped it and only then
            // read the records would leave the lock free at this exact point, and
            // this attempt would take it.
            $writer = WriteRun::holdingOpen($this->paths->marker());

            $contender = new RunLock($this->paths);

            self::assertFalse(
                $contender->acquire(),
                'A read holds the run lock from before it asks it to after it has read both '
                . 'records, so no run can begin in the middle of one.'
            );
            self::assertSame(RunLock::IN_PROGRESS, $contender->refusal());
        } finally {
            $reading->kill();
            $writer?->kill();
        }

        // And the read owns the lock only for as long as it is reading.
        $after = new RunLock($this->paths);
        self::assertTrue($after->acquire());
        $after->release();
    }

    public function testAProcessWithoutTheLockLeavesTheRecordPathsUntouched(): void
    {
        // Nothing has run, so neither record exists, and a reader creates neither.
        self::assertTrue($this->state()->isClean());

        self::assertFileDoesNotExist($this->paths->marker());
        self::assertFileDoesNotExist($this->paths->writeIntent());
    }
}
