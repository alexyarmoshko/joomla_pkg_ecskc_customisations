<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Runner;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventLog;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventRecord;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunLock;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunMarker;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntent;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntentStore;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\RecoveryOutcome;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\Reverter;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\RunRecovery;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\TargetWriter;
use YakShaver\ECSKCCustomisation\Tests\Support\RuleXml;
use YakShaver\ECSKCCustomisation\Tests\Support\WriteRun;

/**
 * A run marker and a `state/write.intent` with a known correlation id are exactly
 * what a process that died between two steps leaves behind, so most cases write
 * those two files and then run. The cases that need a lock released by a real death,
 * or a second process working on the tree at the same time, use `WriteRun` and a
 * child process, because neither can be produced inside one.
 */
#[CoversClass(Runner::class)]
#[CoversClass(RunRecovery::class)]
#[CoversClass(RecoveryOutcome::class)]
final class RecoveryTest extends RunnerHarness
{
    /**
     * The intent a run would have published immediately before one mutation.
     *
     * @param string $pre  The state the mutation was to replace.
     * @param string $post The state it was to leave.
     */
    private function seedIntent(
        string $id,
        string $kind,
        string $operation,
        string $targetPath,
        string $pre,
        string $post,
    ): EventRecord {
        $record = EventRecord::mutation($kind, $operation, $targetPath, $pre, $post, $id);

        self::assertTrue(
            (new WriteIntentStore($this->paths()))->publish(WriteIntent::of($id, $record->toPayload())),
            'The fixture could not publish its write intent.'
        );

        return $record;
    }

    private function seedMarker(): void
    {
        self::assertTrue(
            (new RunMarker($this->paths()))->publish(),
            'The fixture could not publish its run marker.'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventsFor(string $id): array
    {
        return \array_values(\array_filter(
            $this->events(),
            static fn (array $record): bool => ($record['id'] ?? null) === $id
        ));
    }

    private function assertRunCompleted(): void
    {
        self::assertFileDoesNotExist($this->paths()->marker(), 'A completed run removes its marker.');
        self::assertFileDoesNotExist($this->paths()->writeIntent());
        self::assertSame([], $this->stagingNames(), 'A completed run leaves staging empty.');
        self::assertTrue($this->lockIsFree());
    }

    public function testAnInterruptedMarkerIsReportedOnceTheLockIsReleased(): void
    {
        $this->writeFixture();

        $run = WriteRun::holding($this->tree->path(self::PACKAGE));

        try {
            $live = $this->runner()->check();
            self::assertFalse(
                $live->wasInterrupted(),
                'A marker under a held lock is a live run, not an interrupted one.'
            );
        } finally {
            $run->kill();
        }

        $report = $this->runner()->check();

        self::assertTrue($report->wasInterrupted());
        self::assertFileExists($this->paths()->marker(), 'A check settles nothing.');
    }

    /**
     * Recovery is an outcome of this call, not ambient process state. A runner that
     * settled a stale marker and is then asked again while another handle holds the
     * lock must report the lock it could not acquire, not the interruption it
     * already cleared.
     */
    public function testASecondCallDoesNotReportTheFirstCallsRecovery(): void
    {
        $this->writeFixture();
        $this->seedMarker();

        $runner = $this->runner();
        $first  = $runner->apply();

        self::assertTrue($first->wasInterrupted());
        self::assertStringContainsString(
            'an earlier run left the run marker',
            $first->interruption->describe()
        );

        $held = new RunLock($this->paths());
        self::assertTrue($held->acquire());

        try {
            $second = $runner->apply();

            self::assertTrue($second->isFaulted());
            self::assertStringContainsString(
                RunLock::IN_PROGRESS,
                \implode("\n", $second->faults())
            );
            self::assertFalse(
                $second->wasInterrupted(),
                'A lock this call could not acquire is a live run, not the previous recovery.'
            );
            self::assertStringNotContainsString(
                'an earlier run left the run marker',
                $second->interruption->describe()
            );
        } finally {
            $held->release();
        }
    }

    public function testAnApplyAfterAnInterruptionCompletesTheWorkAndClearsUp(): void
    {
        $this->writeFixture();

        $this->seedMarker();
        $abandoned = $this->paths()->staging() . '/'
            . PackagePaths::publicationTemporaryName('vendor.css', 'aaaaaaaaaaaa');
        \file_put_contents($abandoned, 'a staged copy of a run that died');
        \file_put_contents(
            $this->paths()->state() . '/'
                . PackagePaths::publicationTemporaryName(PackagePaths::MARKER, 'bbbbbbbbbbbb'),
            'half a marker'
        );

        $report = $this->runner()->apply();

        self::assertTrue($report->wasInterrupted(), 'The interruption is reported, not hidden.');
        self::assertFileDoesNotExist($abandoned, 'The abandoned staged copy is swept.');
        self::assertSame(self::FIRST, \file_get_contents($this->tree->path(self::VENDOR)));
        self::assertSame(self::SECOND, \file_get_contents($this->tree->path(self::CHAIN)));
        $this->assertRunCompleted();
    }

    public function testASecondRunAfterARecoveryFindsACleanTree(): void
    {
        $this->writeFixture();
        $this->seedMarker();

        $this->runner()->apply();
        $report = $this->runner()->apply();

        self::assertFalse($report->wasInterrupted(), 'The tree is clean the second time.');
        self::assertFalse($report->isFaulted(), \implode("\n", $report->faults()));
        $this->assertRunCompleted();
    }

    public function testADeathBeforeACreateLeavesTheTargetAloneAndRecordsAnAbandonedIntent(): void
    {
        $this->writeFixture();
        $this->seedMarker();
        $this->seedIntent(
            'abandoned01',
            EventRecord::WRITE,
            EventRecord::COPY,
            self::CREATED,
            EventRecord::ABSENT,
            self::digest(self::FIRST)
        );

        // The target is still absent when recovery looks, because the run died
        // before the move. Recovery must not invent the write.
        self::assertFileDoesNotExist($this->tree->path(self::CREATED));

        $report    = $this->runner()->apply();
        $recovered = $this->eventsFor('abandoned01');

        self::assertCount(1, $recovered);
        self::assertSame(EventRecord::INTENT_ABANDONED, $recovered[0]['event']);
        self::assertSame(EventRecord::OBSERVED, $recovered[0]['by']);
        self::assertTrue($report->wasInterrupted());

        self::assertSame(self::FIRST, \file_get_contents($this->tree->path(self::CREATED)));
        $this->assertRunCompleted();
    }

    public function testADeathAfterACreateAndBeforeTheEventCompletesTheLogAsObserved(): void
    {
        $this->writeFixture();

        // The mutation is on disk; only the record of it is missing.
        $this->tree->write(self::CREATED, self::FIRST);
        $this->seedMarker();
        $this->seedIntent(
            'completed01',
            EventRecord::WRITE,
            EventRecord::COPY,
            self::CREATED,
            EventRecord::ABSENT,
            self::digest(self::FIRST)
        );

        $this->runner()->apply();

        $recovered = $this->eventsFor('completed01');

        self::assertCount(1, $recovered);
        self::assertSame(EventRecord::WRITE, $recovered[0]['event']);
        self::assertSame(
            EventRecord::OBSERVED,
            $recovered[0]['by'],
            'Nobody saw the package do it, so it is not attributed to the package.'
        );
        $this->assertRunCompleted();
    }

    public function testADeathAfterTheEventRemovesTheIntentWithoutASecondRecord(): void
    {
        $this->writeFixture();
        $this->tree->write(self::CREATED, self::FIRST);

        $log = new EventLog($this->paths());

        // The same transition at the same target, under a different id, committed
        // earlier. Matching on target and states would settle the leftover against
        // this record and lose the second write entirely.
        self::assertTrue($log->append(EventRecord::mutation(
            EventRecord::WRITE,
            EventRecord::COPY,
            self::CREATED,
            EventRecord::ABSENT,
            self::digest(self::FIRST),
            'earlier00001'
        )));
        self::assertTrue($log->append(EventRecord::mutation(
            EventRecord::WRITE,
            EventRecord::COPY,
            self::CREATED,
            EventRecord::ABSENT,
            self::digest(self::FIRST),
            'settled00001'
        )));

        $this->seedMarker();
        $this->seedIntent(
            'settled00001',
            EventRecord::WRITE,
            EventRecord::COPY,
            self::CREATED,
            EventRecord::ABSENT,
            self::digest(self::FIRST)
        );

        $this->runner()->apply();

        self::assertCount(1, $this->eventsFor('settled00001'), 'No second record is appended.');
        self::assertCount(1, $this->eventsFor('earlier00001'));
        $this->assertRunCompleted();
    }

    /**
     * A removal told to keep no copy, and the intent a run publishes before it.
     *
     * The merged set allows `backup="false"` only where an earlier object ships the
     * bytes being removed, so the target starts at the chain's first step.
     */
    private function writeNoCopyRemoval(string $id): void
    {
        $this->tree->write(self::REMOVED, self::FIRST);
        $this->payload('comingsoon.php.v1', self::FIRST);
        $this->tree->write(self::PACKAGE . '/rules/10-run.xml', RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: self::REMOVED,
                source: 'comingsoon.php.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            )
            . RuleXml::object(
                version: 2,
                dest: self::REMOVED,
                expectedHash: self::digest(self::FIRST),
                backup: false
            )
        ));

        $this->seedMarker();
        $this->seedIntent(
            $id,
            EventRecord::REMOVE,
            EventRecord::REMOVAL,
            self::REMOVED,
            self::digest(self::FIRST),
            EventRecord::ABSENT
        );
    }

    public function testADeathBeforeANoCopyRemovalRecordsAnAbandonedIntent(): void
    {
        $this->writeNoCopyRemoval('noremove0001');

        $report    = $this->runner()->apply();
        $recovered = $this->eventsFor('noremove0001');

        self::assertCount(1, $recovered);
        self::assertSame(EventRecord::INTENT_ABANDONED, $recovered[0]['event']);
        self::assertSame(EventRecord::OBSERVED, $recovered[0]['by']);
        self::assertTrue($report->wasInterrupted());

        $own = \array_values(\array_filter(
            $this->events(),
            static fn (array $record): bool => ($record['id'] ?? null) !== 'noremove0001'
        ));

        self::assertCount(1, $own);
        self::assertSame(EventRecord::REMOVE, $own[0]['event']);
        self::assertSame(EventRecord::PACKAGE, $own[0]['by']);
        self::assertFileDoesNotExist($this->tree->path(self::REMOVED));
        self::assertSame([], $this->backupNames(), 'No copy was asked for and none was kept.');
        $this->assertRunCompleted();
    }

    public function testADeathAfterANoCopyRemovalCompletesTheLogAsObserved(): void
    {
        $this->writeNoCopyRemoval('noremove0002');
        \unlink($this->tree->path(self::REMOVED));

        $report = $this->runner()->apply();

        $recovered = $this->eventsFor('noremove0002');

        self::assertCount(1, $recovered);
        self::assertSame(EventRecord::REMOVE, $recovered[0]['event']);
        self::assertSame(EventRecord::OBSERVED, $recovered[0]['by']);
        self::assertSame(EventRecord::ABSENT, $recovered[0]['post']);
        self::assertCount(1, $this->events(), 'The run finds nothing left to remove.');
        self::assertFileDoesNotExist($this->tree->path(self::REMOVED));
        self::assertTrue($report->wasInterrupted());
        $this->assertRunCompleted();
    }

    public function testADeathAfterANoCopyRemovalEventRemovesTheIntentWithoutASecondRecord(): void
    {
        $this->writeNoCopyRemoval('noremove0003');
        \unlink($this->tree->path(self::REMOVED));

        $log = new EventLog($this->paths());

        foreach (['earlier00002', 'noremove0003'] as $id) {
            self::assertTrue($log->append(EventRecord::mutation(
                EventRecord::REMOVE,
                EventRecord::REMOVAL,
                self::REMOVED,
                self::digest(self::FIRST),
                EventRecord::ABSENT,
                $id
            )));
        }

        $report = $this->runner()->apply();

        self::assertCount(1, $this->eventsFor('noremove0003'), 'No second record is appended.');
        self::assertCount(1, $this->eventsFor('earlier00002'));
        self::assertCount(2, $this->events());
        self::assertFileDoesNotExist($this->tree->path(self::REMOVED));
        self::assertTrue($report->wasInterrupted());
        $this->assertRunCompleted();
    }

    /**
     * An outside writer that puts the post-state there produces the same evidence a
     * completed mutation does, so it produces the same observed event. What it does
     * not produce is a package write.
     */
    public function testAnOutsideWriterThatCreatesThePostStateIsRecordedAsObserved(): void
    {
        $this->writeFixture();
        $this->seedMarker();
        $this->seedIntent(
            'outside00001',
            EventRecord::WRITE,
            EventRecord::COPY,
            self::CREATED,
            EventRecord::ABSENT,
            self::digest(self::FIRST)
        );

        $this->tree->write(self::CREATED, self::FIRST);

        $this->runner()->apply();

        $recovered = $this->eventsFor('outside00001');

        self::assertCount(1, $recovered);
        self::assertSame(EventRecord::OBSERVED, $recovered[0]['by']);
        self::assertNotSame(EventRecord::PACKAGE, $recovered[0]['by']);
    }

    /**
     * And the mirror: the pre-state restored after the package mutated reads as an
     * abandoned intent, never as a silent clear and never as a package write.
     */
    public function testAnOutsideWriterThatRestoresThePreStateRecordsAnAbandonedIntent(): void
    {
        $this->writeFixture();
        $this->seedMarker();
        $this->seedIntent(
            'restored0001',
            EventRecord::WRITE,
            EventRecord::COPY,
            self::VENDOR,
            self::digest(self::ORIGINAL),
            self::digest(self::FIRST)
        );

        $this->tree->write(self::VENDOR, self::ORIGINAL);

        $this->runner()->apply();

        $recovered = $this->eventsFor('restored0001');

        self::assertCount(1, $recovered);
        self::assertSame(EventRecord::INTENT_ABANDONED, $recovered[0]['event']);
        self::assertSame(EventRecord::OBSERVED, $recovered[0]['by']);
    }

    /**
     * "Keeps" is for the length of this run. The stale marker is replaced by the
     * run's own, by atomic rename, so a death before the run finishes is still an
     * interruption - and the ticket's own test list requires that a run which does
     * complete after the settlement leaves no marker and no intent.
     */
    public function testANeitherStateCommitsAnUnresolvedIntentAndCompletesTheRun(): void
    {
        $this->writeFixture();
        $this->seedMarker();
        $this->seedIntent(
            'unresolved01',
            EventRecord::WRITE,
            EventRecord::COPY,
            self::VENDOR,
            self::digest(self::ORIGINAL),
            self::digest(self::FIRST)
        );

        $this->tree->write(self::VENDOR, self::FOREIGN);

        $report = $this->runner()->apply();

        $recovered = $this->eventsFor('unresolved01');

        self::assertCount(1, $recovered);
        self::assertSame(EventRecord::UNRESOLVED_INTENT, $recovered[0]['event']);
        self::assertSame(EventRecord::OBSERVED, $recovered[0]['by']);
        self::assertSame(
            self::digest(self::FOREIGN),
            $recovered[0]['observed'],
            'The fault carries what was actually there.'
        );
        self::assertTrue($report->wasInterrupted(), 'The interruption is reported, not hidden.');
        self::assertSame(
            self::FOREIGN,
            \file_get_contents($this->tree->path(self::VENDOR)),
            'Bytes the rule set cannot name are still not written over.'
        );
        $this->assertRunCompleted();
    }

    /**
     * The run settles the neither-state, publishes its own marker over the stale one
     * and then dies before it finishes. What it leaves is a marker and no intent,
     * which is what makes the next apply recover rather than read a clean history.
     */
    public function testADeathAfterANeitherStateSettlementLeavesAMarkerAndNoIntent(): void
    {
        $this->writeFixture();
        $this->seedMarker();
        $this->seedIntent(
            'unresolved02',
            EventRecord::WRITE,
            EventRecord::COPY,
            self::VENDOR,
            self::digest(self::ORIGINAL),
            self::digest(self::FIRST)
        );
        $this->tree->write(self::VENDOR, self::FOREIGN);

        try {

            $this->runner(static function (string $point): void {
                if ($point === TargetWriter::STAGED) {
                    throw new \RuntimeException('the process died');
                }
            })->apply();
            self::fail('The staged death did not happen.');
        } catch (\RuntimeException $error) {
            self::assertSame('the process died', $error->getMessage());
        }

        self::assertCount(1, $this->eventsFor('unresolved02'), 'The settlement committed.');
        self::assertFileDoesNotExist($this->paths()->writeIntent(), 'And left nothing outstanding.');
        self::assertFileExists($this->paths()->marker(), 'The death is still an interruption.');

        $report = $this->runner()->apply();

        self::assertTrue($report->wasInterrupted(), 'The retained marker is still reported.');
        self::assertCount(1, $this->eventsFor('unresolved02'), 'And is not settled twice.');
        $this->assertRunCompleted();
    }

    /**
     * A completed recovery leaves `staging/` empty, so one that cannot is not
     * complete and the run that called it keeps its marker.
     */
    public function testASweepThatCannotFinishKeepsTheRunMarker(): void
    {
        $this->writeFixture();

        $abandoned = $this->paths()->staging() . '/'
            . PackagePaths::publicationTemporaryName('vendor.css', 'eeeeeeeeeeee');
        \file_put_contents($abandoned, 'a staged copy of a run that died');

        self::assertTrue($this->lockIsFree());
        $this->lockDown($this->paths()->staging(), 0o500);

        $report = $this->runner()->apply();

        self::assertFileExists($abandoned, 'The sweep could not remove it.');
        self::assertStringContainsString(
            'could not be removed from the staging folder',
            \implode("\n", $report->faults())
        );
        self::assertFileExists(
            $this->paths()->marker(),
            'A run that left an abandoned staged copy has not completed.'
        );
        self::assertFileDoesNotExist($this->paths()->writeIntent());
        self::assertTrue($this->lockIsFree(), 'The lock is still released.');

        \chmod($this->paths()->staging(), 0o755);

        $later = $this->runner()->apply();

        self::assertTrue($later->wasInterrupted(), 'The retained marker is reported first.');
        $this->assertRunCompleted();
    }

    /**
     * The same rule for a staged file this run's own operation left, not a dead
     * one's.
     */
    public function testAStagedFileThisRunCouldNotRemoveKeepsTheRunMarker(): void
    {
        $this->writeFixture();

        // Damaged after it is staged, so the write is refused, and the folder is
        // closed in the same moment, so the call cannot take its own copy away again.
        $report = $this->runner(function (string $point, ?string $staged): void {
            if ($point === TargetWriter::STAGED && \str_contains((string) $staged, 'vendor.css')) {
                \file_put_contents((string) $staged, self::SECOND);
                $this->lockDown($this->paths()->staging(), 0o500);
            }
        })->apply(self::VENDOR);

        self::assertSame(self::ORIGINAL, \file_get_contents($this->tree->path(self::VENDOR)));
        self::assertCount(1, $this->stagingNames(), 'The staged copy is still there.');
        self::assertStringContainsString(
            'could not be removed',
            $this->target($report, self::VENDOR)->outcome()->describe()
        );
        self::assertFileExists(
            $this->paths()->marker(),
            'A run that left its own staged copy has not completed.'
        );
        self::assertFileDoesNotExist($this->paths()->writeIntent());
        self::assertTrue($this->lockIsFree(), 'The lock is still released.');

        \chmod($this->paths()->staging(), 0o755);

        $later = $this->runner()->apply();

        self::assertTrue($later->wasInterrupted(), 'The retained marker is reported first.');
        self::assertSame(self::FIRST, \file_get_contents($this->tree->path(self::VENDOR)));
        $this->assertRunCompleted();
    }

    public function testAMarkerThatCannotBeRemovedIsReportedAfterTheTargetsHaveChanged(): void
    {
        $this->writeFixture();

        $report = $this->runner(function (string $point): void {
            if ($point !== TargetWriter::EVENT_COMMITTED) {
                return;
            }

            // The intent has to go first or a read-only `state/` would keep it
            // for recovery and never reach the marker unlink. Clearing it here
            // is what a successful settlement already did; the checkpoint then
            // closes the folder before `WriteRun` tries to take the marker away.
            self::assertTrue((new WriteIntentStore($this->paths()))->remove());
            $this->lockDown($this->paths()->state(), 0o500);
        })->apply(self::VENDOR);

        self::assertSame(self::FIRST, \file_get_contents($this->tree->path(self::VENDOR)));
        self::assertFileExists($this->paths()->marker(), 'The run did not complete.');
        self::assertFileDoesNotExist($this->paths()->writeIntent());
        self::assertStringContainsString(
            'could not be removed',
            \implode("\n", $report->faults())
        );
        self::assertTrue($this->lockIsFree(), 'The lock is still released.');
    }

    /**
     * A present marker that does not parse is a fault, never a clean history.
     *
     * The run may still complete and replace the marker. The report has to say
     * the timestamp could not be trusted, because afterwards the bad record is gone.
     */
    public function testAMalformedRunMarkerIsReportedWhenTheRunReplacesIt(): void
    {
        $this->writeFixture();
        \file_put_contents($this->paths()->marker(), "not a timestamp\n");

        $report = $this->runner()->apply(self::VENDOR);

        self::assertSame(self::FIRST, \file_get_contents($this->tree->path(self::VENDOR)));
        self::assertFileDoesNotExist($this->paths()->marker(), 'A completed run removes the marker.');
        self::assertFileDoesNotExist($this->paths()->writeIntent());
        self::assertTrue($report->isFaulted());
        self::assertStringContainsString(
            'does not hold one ISO-8601 UTC timestamp',
            \implode("\n", $report->faults())
        );
        self::assertTrue($this->lockIsFree());
    }

    /**
     * The same break after a revert. With no line to carry it, the warning that the
     * next apply undoes the revert goes into the fault text beside the outcome.
     */
    public function testARuleSetThatBreaksAfterARevertKeepsTheReapplyWarning(): void
    {
        $this->writeFixture();
        $this->runner()->apply(self::VENDOR);

        $report = $this->runner(function (string $point): void {
            if ($point === TargetWriter::EVENT_COMMITTED) {
                $this->tree->write(self::PACKAGE . '/rules/10-run.xml', '<patch');
            }
        })->revert(self::VENDOR);

        self::assertSame(self::ORIGINAL, \file_get_contents($this->tree->path(self::VENDOR)));
        self::assertSame([], $report->targets());
        self::assertStringContainsString(
            \sprintf(Reverter::REAPPLY_WARNING, self::VENDOR),
            \implode("\n", $report->faults())
        );
    }

    /**
     * The rule set can change under a run after it wrote. The walk that composes the
     * report then fails, and the report still has to say what was written and what
     * recovery found, because the malformed marker it replaced is gone.
     */
    public function testARuleSetThatBreaksAfterTheWriteKeepsTheOutcomeAndTheRecovery(): void
    {
        $this->writeFixture();
        \file_put_contents($this->paths()->marker(), "not a timestamp\n");

        $report = $this->runner(function (string $point): void {
            if ($point === TargetWriter::EVENT_COMMITTED) {
                $this->tree->write(self::PACKAGE . '/rules/10-run.xml', '<patch');
            }
        })->apply(self::VENDOR);

        self::assertSame(self::FIRST, \file_get_contents($this->tree->path(self::VENDOR)));
        self::assertFileDoesNotExist($this->paths()->marker(), 'A completed run removes the marker.');
        self::assertSame([], $report->targets(), 'There is no state to report a line against.');

        $faults = \implode("\n", $report->faults());

        self::assertStringContainsString('10-run.xml', $faults);
        self::assertStringContainsString(
            'the rule set could not be walked again after this run, so ' . self::VENDOR,
            $faults
        );
        self::assertStringContainsString('does not hold one ISO-8601 UTC timestamp', $faults);
        self::assertStringContainsString(
            'replaces ' . self::digest(self::ORIGINAL),
            $faults,
            'The executed plan still names what the write displaced.'
        );

        $backups = $this->backupNames();

        self::assertCount(1, $backups);
        self::assertStringContainsString(
            'backup ' . $this->paths()->backup() . '/' . $backups[0],
            $faults,
            'The executed plan still names where the displaced original went.'
        );
        self::assertTrue($report->wasInterrupted(), 'The replaced marker is still reported.');
        self::assertTrue($this->lockIsFree());
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function freezeMarkerShapes(): array
    {
        return [
            'a regular file'     => [false],
            'an empty directory' => [true],
        ];
    }

    /**
     * The marker is already the post-state, so recovery records the interrupted
     * freeze as observed and the freeze itself finds the path already held.
     */
    #[DataProvider('freezeMarkerShapes')]
    public function testFreezeRecoversEitherAcceptedMarkerShape(bool $directory): void
    {
        $this->writeFixture();
        $this->seedMarker();
        $this->seedIntent(
            'freezing0002',
            EventRecord::FREEZE,
            EventRecord::FREEZING,
            self::VENDOR,
            EventRecord::ABSENT,
            EventRecord::PRESENT
        );
        $marker = $this->placeFreezeMarker($directory);

        $report = $this->runner()->freeze(self::VENDOR);

        $recovered = $this->eventsFor('freezing0002');

        self::assertCount(1, $recovered);
        self::assertSame(EventRecord::FREEZE, $recovered[0]['event']);
        self::assertSame(EventRecord::OBSERVED, $recovered[0]['by']);
        self::assertFileDoesNotExist($this->paths()->writeIntent());
        self::assertFileDoesNotExist($this->paths()->marker(), 'The recovery completed.');
        self::assertFileExists($marker, 'The path stays frozen.');
        self::assertStringContainsString(
            'already frozen',
            $this->target($report, self::VENDOR)->outcome()->describe()
        );
        self::assertTrue($report->wasInterrupted());
        self::assertTrue($this->lockIsFree());
    }

    /**
     * The same two shapes for an interrupted unfreeze. The marker is still the
     * pre-state, so recovery records the intent as abandoned and the unfreeze
     * then removes it.
     */
    #[DataProvider('freezeMarkerShapes')]
    public function testUnfreezeRecoversEitherAcceptedMarkerShape(bool $directory): void
    {
        $this->writeFixture();
        $this->seedMarker();
        $this->seedIntent(
            'release00001',
            EventRecord::UNFREEZE,
            EventRecord::RELEASE,
            self::VENDOR,
            EventRecord::PRESENT,
            EventRecord::ABSENT
        );
        $marker = $this->placeFreezeMarker($directory);

        $report = $this->runner()->unfreeze(self::VENDOR);

        $recovered = $this->eventsFor('release00001');

        self::assertCount(1, $recovered);
        self::assertSame(EventRecord::INTENT_ABANDONED, $recovered[0]['event']);
        self::assertSame(EventRecord::OBSERVED, $recovered[0]['by']);
        self::assertFileDoesNotExist($marker, 'The unfreeze removed the marker.');
        self::assertFileDoesNotExist($this->paths()->writeIntent());
        self::assertFileDoesNotExist($this->paths()->marker(), 'The recovery completed.');
        self::assertSame(
            TargetStatus::ACTIONABLE,
            $this->target($report, self::VENDOR)->status()
        );
        self::assertTrue($report->wasInterrupted());
        self::assertTrue($this->lockIsFree());
    }

    private function placeFreezeMarker(bool $directory): string
    {
        $marker = $this->paths()->freezeMarker(self::VENDOR);
        \mkdir(\dirname($marker), 0o755, true);

        if ($directory) {
            \mkdir($marker, 0o755);
        } else {
            \file_put_contents($marker, "held by hand\n");
        }

        return $marker;
    }

    /**
     * A freeze marker is `absent` or `present` and never a digest, so there is no
     * third state for one to be found in.
     */
    public function testFreezeAndUnfreezeNeverReachTheNeitherStateBranch(): void
    {
        $this->writeFixture();
        $this->seedMarker();
        $this->seedIntent(
            'freezing0001',
            EventRecord::FREEZE,
            EventRecord::FREEZING,
            self::VENDOR,
            EventRecord::ABSENT,
            EventRecord::PRESENT
        );

        $this->tree->write(self::VENDOR, self::FOREIGN);

        $this->runner()->apply();

        $recovered = $this->eventsFor('freezing0001');

        self::assertCount(1, $recovered);
        self::assertSame(EventRecord::INTENT_ABANDONED, $recovered[0]['event']);
        self::assertNotSame(EventRecord::UNRESOLVED_INTENT, $recovered[0]['event']);
    }

    /**
     * A recovery that cannot commit its event keeps both records, because clearing
     * the intent would destroy the only evidence of the mutation.
     */
    public function testARecoveryThatCannotCommitItsEventLeavesTheMarkerAndTheIntent(): void
    {
        $this->writeFixture();
        $this->tree->write(self::CREATED, self::FIRST);
        $this->seedMarker();
        $this->seedIntent(
            'uncommitted1',
            EventRecord::WRITE,
            EventRecord::COPY,
            self::CREATED,
            EventRecord::ABSENT,
            self::digest(self::FIRST)
        );

        // The lock file first, so what the read-only folder stops is the append and
        // not the acquisition: a run that never took the lock proves nothing here.
        self::assertTrue($this->lockIsFree());
        $this->lockDown($this->paths()->state(), 0o500);

        $report = $this->runner()->apply();

        self::assertFileExists($this->paths()->marker());
        self::assertFileExists($this->paths()->writeIntent());
        self::assertTrue($report->isFaulted());
        self::assertStringContainsString('could not be appended', \implode("\n", $report->faults()));
        self::assertSame(
            self::ORIGINAL,
            \file_get_contents($this->tree->path(self::VENDOR)),
            'An unsettled intent stops the run before it writes.'
        );

        \chmod($this->paths()->state(), 0o755);

        $this->runner()->apply();

        self::assertCount(1, $this->eventsFor('uncommitted1'));
        $this->assertRunCompleted();
    }

    /**
     * A neither-state whose fault cannot be appended is the same rule, and the later
     * apply commits it and continues under the marker it retained.
     */
    public function testANeitherStateFaultThatCannotBeAppendedIsCommittedLater(): void
    {
        $this->writeFixture();
        $this->seedMarker();
        $this->seedIntent(
            'unresolved03',
            EventRecord::WRITE,
            EventRecord::COPY,
            self::VENDOR,
            self::digest(self::ORIGINAL),
            self::digest(self::FIRST)
        );
        $this->tree->write(self::VENDOR, self::FOREIGN);

        self::assertTrue($this->lockIsFree());
        $this->lockDown($this->paths()->state(), 0o500);

        $report = $this->runner()->apply();

        self::assertFileExists($this->paths()->marker());
        self::assertFileExists($this->paths()->writeIntent());
        self::assertSame([], $this->events());
        self::assertStringContainsString('could not be appended', \implode("\n", $report->faults()));

        \chmod($this->paths()->state(), 0o755);

        $report = $this->runner()->apply();

        self::assertCount(1, $this->eventsFor('unresolved03'));
        self::assertSame(EventRecord::UNRESOLVED_INTENT, $this->eventsFor('unresolved03')[0]['event']);
        self::assertTrue($report->wasInterrupted());
        self::assertFileDoesNotExist($this->paths()->writeIntent());
    }

    /**
     * A run that does not hold the lock is looking at a live run's tree, and nothing
     * in it may be swept: the staged copy it would delete is about to be moved.
     */
    public function testARunWithoutTheLockSweepsNothingOfALiveRuns(): void
    {
        $this->writeFixture();

        $run    = WriteRun::holding($this->tree->path(self::PACKAGE), 'marker+intent', 'live00000001');
        $staged = $this->paths()->staging() . '/'
            . PackagePaths::publicationTemporaryName('vendor.css', 'cccccccccccc');
        $temp   = $this->paths()->state() . '/'
            . PackagePaths::publicationTemporaryName(PackagePaths::WRITE_INTENT, 'dddddddddddd');

        \file_put_contents($staged, 'a live run is about to move this');
        \file_put_contents($temp, 'a live run is about to rename this');

        try {
            $report = $this->runner()->apply();

            self::assertFileExists($staged, 'A losing run does not sweep a live run staging file.');
            self::assertFileExists($temp, 'Nor its publication temporary.');
            self::assertFileExists($this->paths()->writeIntent(), 'Nor its intent.');
            self::assertTrue($report->isFaulted());
        } finally {
            $run->kill();
        }
    }

    public function testADamagedTailStaysDamagedAndTheLastWriteIsStillAnswered(): void
    {
        $this->writeFixture();
        $this->runner()->apply(self::VENDOR);

        $log = $this->paths()->eventLog();

        $uncommitted = \json_encode([
            'event' => EventRecord::WRITE,
            'at'    => '2026-01-01T00:00:00Z',
            'by'    => EventRecord::PACKAGE,
            'target' => self::VENDOR,
            'operation' => EventRecord::COPY,
            'pre'   => self::digest(self::FIRST),
            'post'  => self::digest(self::SECOND),
            'id'    => 'damaged00001',
        ], \JSON_UNESCAPED_SLASHES);

        \file_put_contents($log, $uncommitted, \FILE_APPEND);

        $report = $this->runner()->apply();
        $bytes  = (string) \file_get_contents($log);

        self::assertStringContainsString(
            $uncommitted . "\n",
            $bytes,
            'The damaged line keeps its bytes and never gains a commit token.'
        );
        self::assertTrue($report->isFaulted());
        self::assertStringContainsString('damaged', \strtolower(\implode("\n", $report->faults())));

        $last = $this->target($report, self::VENDOR)->lastPackageWrite;

        self::assertTrue($last->isKnown(), 'The complete records around it still answer.');
        self::assertStringNotContainsString('damaged00001', (string) $last->value);
    }

    /**
     * A tail sealed with its token but no line feed is committed, before and after
     * the next record is appended behind it.
     */
    public function testATokenWithoutALineFeedIsCommittedBeforeAndAfterTheNextAppend(): void
    {
        $this->writeFixture();
        $this->runner()->apply(self::VENDOR);

        $log   = $this->paths()->eventLog();
        $bytes = \rtrim((string) \file_get_contents($log), "\n");

        \file_put_contents($log, $bytes);

        $before = $this->target($this->runner()->check(), self::VENDOR)->lastPackageWrite;

        self::assertTrue($before->isKnown());
        self::assertSame([], $this->runner()->check()->faults(), 'A sealed tail is not damaged.');

        $this->runner()->apply(self::CHAIN);

        $after = $this->target($this->runner()->check(), self::VENDOR)->lastPackageWrite;

        self::assertSame($before->describe(), $after->describe());
        self::assertSame([], $this->runner()->check()->faults());
    }

    /**
     * A death between a create and its event, staged through the write itself rather
     * than by seeding files: the checkpoint stops the run where the process would.
     */
    public function testAnInterruptionStagedAtTheRealCheckpointRecoversTheSameWay(): void
    {
        $this->writeFixture();

        $id = null;

        try {
            $this->runner(function (string $point) use (&$id): void {
                if ($point === TargetWriter::MUTATED && $id === null) {
                    $id = (new WriteIntentStore($this->paths()))->read()->intent?->id;

                    throw new \RuntimeException('the process died');
                }
            })->apply();
            self::fail('The staged death did not happen.');
        } catch (\RuntimeException $error) {
            self::assertSame('the process died', $error->getMessage());
        }

        self::assertIsString($id, 'The intent was published before the mutation.');
        self::assertFileExists($this->paths()->writeIntent());
        self::assertFileExists($this->paths()->marker());

        $report = $this->runner()->apply();

        self::assertTrue($report->wasInterrupted());
        self::assertCount(1, $this->eventsFor($id));
        self::assertSame(EventRecord::OBSERVED, $this->eventsFor($id)[0]['by']);
        $this->assertRunCompleted();
    }
}
