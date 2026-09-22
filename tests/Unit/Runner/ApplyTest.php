<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Runner;

use PHPUnit\Framework\Attributes\CoversClass;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\PlannedOperation;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventRecord;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunLock;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntentStore;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\TargetWriter;
use YakShaver\ECSKCCustomisation\Tests\Support\RuleXml;

/**
 * Plan work items 3, 8, 9, 10 and 12 through the runner: what an apply writes.
 */
#[CoversClass(Runner::class)]
final class ApplyTest extends RunnerHarness
{
    public function testApplyCreatesAPackageOwnedFileAtModeSixFourFour(): void
    {
        $this->writeFixture();

        $report = $this->runner()->apply();
        $path   = $this->tree->path(self::CREATED);

        self::assertFileExists($path);
        self::assertSame(self::FIRST, \file_get_contents($path));
        self::assertSame(
            TargetWriter::CREATE_MODE,
            \fileperms($path) & 0o777,
            'Decision 28 gives a created file 0644.'
        );
        self::assertSame(TargetStatus::OK, $this->target($report, self::CREATED)->status());
    }

    public function testApplyReplacesAVendorFileBacksUpTheOriginalAndKeepsItsMode(): void
    {
        $this->writeFixture();

        $path = $this->tree->path(self::VENDOR);
        \chmod($path, 0o640);

        $this->runner()->apply();

        self::assertSame(self::FIRST, \file_get_contents($path));
        self::assertSame(0o640, \fileperms($path) & 0o777, 'The vendor file keeps its own mode.');

        $backup = $this->paths()->backupFile(self::VENDOR, 1, self::digest(self::ORIGINAL));
        self::assertFileExists($backup);
        self::assertSame(self::ORIGINAL, \file_get_contents($backup));
    }

    /**
     * The same rule for bytes that stopped being recognised after the walk read them.
     *
     * The walk runs before the lock is taken, so its reading of a target is minutes
     * old by the time the chain reaches it. An apply that took whatever it found
     * there as the permitted pre-state would back up somebody's edit under a name
     * that claims the package displaced it, and then write over it.
     */
    public function testAnEditMadeAfterTheWalkIsRefusedRatherThanOverwritten(): void
    {
        $this->writeFixture();

        // chain.css was recognised by the walk. Somebody edits it while the run is
        // part-way through the target before it, which is inside the lock and after
        // the only read that judged it.
        $report = $this->runner(function (string $point, ?string $staged): void {
            if ($point === TargetWriter::STAGED && \str_contains((string) $staged, 'vendor.css')) {
                $this->tree->write(self::CHAIN, self::FOREIGN);
            }
        })->apply();

        self::assertSame(
            self::FOREIGN,
            \file_get_contents($this->tree->path(self::CHAIN)),
            'The edit is left exactly where it is.'
        );
        self::assertStringContainsString(
            'changed after the walk read them',
            $this->target($report, self::CHAIN)->outcome()->describe()
        );
        self::assertFileDoesNotExist(
            $this->paths()->backupFile(self::CHAIN, 1, self::digest(self::FOREIGN)),
            'And is not preserved as though the package had displaced it.'
        );
        self::assertSame([], \array_filter(
            $this->events(),
            static fn (array $record): bool => ($record['target'] ?? null) === self::CHAIN
        ));

        // The refused line still names where its backup would have gone, but the
        // target's backup field names only backups this apply made, and it made none.
        $target = $this->target($report, self::CHAIN);

        self::assertStringContainsString(
            $this->paths()->backupFile(self::CHAIN, 1, self::digest(self::FOREIGN)),
            $target->plan()[0]->backup->describe()
        );
        self::assertFalse($target->backup->isKnown(), $target->backup->describe());

        // The target before it still ran: one target's refusal is that target's.
        self::assertSame(self::FIRST, \file_get_contents($this->tree->path(self::VENDOR)));
    }

    /**
     * The other side of the rule above: bytes that moved after the walk to the payload
     * this step writes are a shipped payload, not an edit.
     *
     * A concurrent run or recovery that installed version 1 leaves nothing to refuse.
     * Refusing it would report a fault and stop the chain short of version 2.
     */
    public function testATargetAdvancedToThisStepsPayloadAfterTheWalkCompletesTheChain(): void
    {
        $this->writeFixture();

        $report = $this->runner(function (string $point, ?string $staged): void {
            if ($point === TargetWriter::STAGED && \str_contains((string) $staged, 'vendor.css')) {
                $this->tree->write(self::CHAIN, self::FIRST);
            }
        })->apply();

        $target = $this->target($report, self::CHAIN);

        self::assertSame(self::SECOND, \file_get_contents($this->tree->path(self::CHAIN)));
        self::assertStringNotContainsString(
            'changed after the walk read them',
            $target->outcome()->describe()
        );
        self::assertStringContainsString(
            'already holds the payload this operation writes',
            $target->plan()[0]->result->describe()
        );
        self::assertFileDoesNotExist(
            $this->paths()->backupFile(self::CHAIN, 1, self::digest(self::ORIGINAL)),
            'Version 1 was not written by this run, so it displaced nothing.'
        );
        self::assertSame(
            self::FIRST,
            \file_get_contents($this->paths()->backupFile(self::CHAIN, 2, self::digest(self::FIRST)))
        );

        // The report names no backup for the step that wrote nothing, on its own line
        // or in the target's backup field.
        $unwritten = $this->paths()->backupFile(self::CHAIN, 1, self::digest(self::FIRST));

        self::assertFalse($target->plan()[0]->backup->isKnown());
        self::assertFalse($target->plan()[0]->displaced->isKnown());
        self::assertStringNotContainsString($unwritten, $target->backup->describe());
        self::assertStringContainsString(
            $this->paths()->backupFile(self::CHAIN, 2, self::digest(self::FIRST)),
            $target->backup->describe()
        );
    }

    /**
     * The same race, one step further: the target reached a later payload of the
     * chain, here its last one.
     *
     * Every step up to that payload is already represented. Refusing would report a
     * fault at a target that holds exactly the bytes the chain ends with.
     */
    public function testATargetAdvancedToALaterPayloadAfterTheWalkSkipsTheStepsItIncludes(): void
    {
        $this->writeFixture();

        $report = $this->runner(function (string $point, ?string $staged): void {
            if ($point === TargetWriter::STAGED && \str_contains((string) $staged, 'vendor.css')) {
                $this->tree->write(self::CHAIN, self::SECOND);
            }
        })->apply();

        $target = $this->target($report, self::CHAIN);
        $plan   = $target->plan();

        self::assertSame(self::SECOND, \file_get_contents($this->tree->path(self::CHAIN)));
        self::assertSame(TargetStatus::OK, $target->status());
        self::assertTrue($target->outcome()->isKnown(), $target->outcome()->describe());
        self::assertCount(2, $plan, 'Both steps of the chain are on the report.');
        self::assertStringContainsString('already holds the version 2 payload', $plan[0]->result->describe());
        self::assertStringContainsString(
            'already holds the payload this operation writes',
            $plan[1]->result->describe()
        );

        foreach ($plan as $line) {
            self::assertFalse($line->backup->isKnown(), 'Neither step displaced anything.');
        }

        self::assertFalse($target->backup->isKnown(), $target->backup->describe());
        self::assertFileDoesNotExist($this->paths()->backupFile(self::CHAIN, 2, self::digest(self::SECOND)));
    }

    /**
     * The same race after this run's own first step wrote: the target reaches the
     * last payload before the second step starts.
     *
     * The second step writes nothing, but the first did, and its backup exists. An
     * outcome that said nothing was written would deny that backup and count the
     * target as unchanged.
     */
    public function testATargetAdvancedAfterThisRunWroteAStepStillReportsThatWrite(): void
    {
        $this->writeFixture();

        $advanced = false;
        $report   = $this->runner(function (string $point) use (&$advanced): void {
            $chain = $this->tree->path(self::CHAIN);

            if (
                !$advanced
                && $point === TargetWriter::EVENT_COMMITTED
                && \file_get_contents($chain) === self::FIRST
            ) {
                $advanced = true;
                $this->tree->write(self::CHAIN, self::SECOND);
            }
        })->apply();

        $target = $this->target($report, self::CHAIN);
        $plan   = $target->plan();
        $first  = $this->paths()->backupFile(self::CHAIN, 1, self::digest(self::ORIGINAL));

        self::assertTrue($advanced, 'The checkpoint moved the target after version 1 was committed.');
        self::assertSame(self::SECOND, \file_get_contents($this->tree->path(self::CHAIN)));
        self::assertFileExists($first);
        self::assertTrue($target->outcome()->isKnown(), $target->outcome()->describe());
        self::assertStringNotContainsString('nothing was written', $target->outcome()->describe());
        self::assertStringContainsString($first, $plan[0]->result->describe());
        self::assertStringContainsString(
            'already holds the payload this operation writes',
            $plan[1]->result->describe()
        );
        self::assertSame($first, $target->backup->describe());
    }

    /**
     * The other way a chain stops after its first step wrote: the second step is
     * refused.
     *
     * The refusal is the target's outcome, but version 1 was written and backed up.
     * A run summary that counted the target as unchanged would contradict that
     * backup. The summary reaches the report only as a run fault, so the marker is
     * made unremovable to put it there.
     */
    public function testAChainThatWritesAndThenRefusesStillCountsTheTargetAsChanged(): void
    {
        $this->writeFixture();

        $intervened = false;
        $report     = $this->runner(function (string $point) use (&$intervened): void {
            if (
                !$intervened
                && $point === TargetWriter::EVENT_COMMITTED
                && \file_get_contents($this->tree->path(self::CHAIN)) === self::FIRST
            ) {
                $intervened = true;
                self::assertTrue((new WriteIntentStore($this->paths()))->remove());
                $this->tree->write(self::CHAIN, self::FOREIGN);
                $this->lockDown($this->paths()->state());
            }
        })->apply(self::CHAIN);

        $target = $this->target($report, self::CHAIN);
        $first  = $this->paths()->backupFile(self::CHAIN, 1, self::digest(self::ORIGINAL));

        self::assertTrue($intervened, 'The checkpoint replaced the target after version 1 was committed.');
        self::assertFileExists($first);
        self::assertStringContainsString(
            'changed after the walk read them',
            $target->outcome()->describe()
        );
        self::assertStringContainsString(
            '1 of 1 targets were changed and 1 faulted',
            \implode("\n", $report->faults())
        );
    }

    /**
     * Brief §5.3: an apply says what it did per object, what it replaced, and where
     * it stored each backup.
     *
     * The plan travels with the run for that reason. Rebuilt from the tree afterwards
     * it would be empty at every target the apply succeeded at, since a file at the
     * head of its chain proposes nothing - so the operator would get an account of
     * exactly the targets that did not work.
     */
    public function testAnApplyReportsThePlanItExecutedWithEachBackupDestination(): void
    {
        $this->writeFixture();

        $target = $this->target($this->runner()->apply(self::CHAIN), self::CHAIN);
        $plan   = $target->plan();
        $first  = $this->paths()->backupFile(self::CHAIN, 1, self::digest(self::ORIGINAL));
        $second = $this->paths()->backupFile(self::CHAIN, 2, self::digest(self::FIRST));

        self::assertCount(2, $plan, 'Both steps of the chain are on the report.');

        self::assertSame(self::digest(self::ORIGINAL), $plan[0]->displaced->value);
        self::assertSame($first, $plan[0]->backup->value);
        self::assertStringContainsString($first, $plan[0]->result->describe());

        self::assertSame(self::digest(self::FIRST), $plan[1]->displaced->value);
        self::assertSame($second, $plan[1]->backup->value);
        self::assertStringContainsString($second, $plan[1]->result->describe());

        foreach ($plan as $line) {
            self::assertTrue($line->result->isKnown(), 'Each step says what it did.');
            self::assertNotSame(PlannedOperation::NOT_EXECUTED, $line->result->reason);
        }

        self::assertStringContainsString($first, $target->backup->describe());
        self::assertStringContainsString($second, $target->backup->describe());
    }

    /**
     * Brief §5.2 puts the permission gate on the directory the move renames into.
     *
     * A vendor file shipped read-only inside a writable directory is a valid atomic
     * replacement: rename does not ask the file for permission, and `TargetWriter`
     * gives the replacement the mode it found. Refusing it would refuse a file the
     * package can in fact write.
     */
    public function testAReadOnlyTargetInAWritableDirectoryIsPlannedAndReplaced(): void
    {
        $this->writeFixture();

        $path = $this->tree->path(self::VENDOR);
        $this->lockDown($path, 0o444);

        $plan = $this->target($this->runner()->dryRun(self::VENDOR), self::VENDOR)->plan();

        self::assertTrue($plan[0]->isPerformable(), \implode('; ', $plan[0]->refusals));

        $this->runner()->apply(self::VENDOR);

        self::assertSame(self::FIRST, \file_get_contents($path));
        self::assertSame(0o444, \fileperms($path) & 0o777, 'The mode it was found with is kept.');
        self::assertSame(
            self::ORIGINAL,
            \file_get_contents($this->paths()->backupFile(self::VENDOR, 1, self::digest(self::ORIGINAL)))
        );
    }

    /**
     * Work item 16 at the apply end: bytes the rule set cannot name are somebody's
     * edit, and writing over them would destroy the only copy.
     */
    public function testApplyRefusesAnUnrecognisedVendorFile(): void
    {
        $this->writeFixture();

        $report = $this->runner()->apply();
        $target = $this->target($report, self::FOREIGN_TARGET);

        self::assertSame(self::FOREIGN, \file_get_contents($this->tree->path(self::FOREIGN_TARGET)));
        self::assertSame(TargetStatus::BLOCKED, $target->status());
        self::assertFalse($target->outcome()->isKnown());
        self::assertStringContainsString(
            'match neither a shipped payload nor an expected original',
            $target->outcome()->describe()
        );
        self::assertSame([], \array_filter(
            $this->events(),
            static fn (array $record): bool => ($record['target'] ?? null) === self::FOREIGN_TARGET
        ));
    }

    public function testAFailedStagedHashLeavesTheTargetUnchanged(): void
    {
        $this->writeFixture();

        // The staged copy is damaged after it is made and before it is verified, so
        // the bytes that would move are not the bytes the rule declared.
        $report = $this->runner(function (string $point, ?string $staged): void {
            if ($point === TargetWriter::STAGED && \str_contains((string) $staged, 'vendor.css')) {
                \file_put_contents((string) $staged, self::SECOND);
            }
        })->apply(self::VENDOR);

        self::assertSame(self::ORIGINAL, \file_get_contents($this->tree->path(self::VENDOR)));
        self::assertStringContainsString(
            'staged payload hashes to',
            $this->target($report, self::VENDOR)->outcome()->describe()
        );
        self::assertSame([], $this->events());
        self::assertFileDoesNotExist($this->paths()->writeIntent());
    }

    public function testAFailedMoveLeavesTheTargetUnchangedFaultsAndLeavesNoIntent(): void
    {
        $this->writeFixture();

        $parent = \dirname($this->tree->path(self::VENDOR));

        $report = $this->runner(function (string $point) use ($parent): void {
            if ($point === 'intent-published') {
                $this->lockDown($parent);
            }
        })->apply(self::VENDOR);

        $target = $this->target($report, self::VENDOR);
        $events = $this->events();

        self::assertSame(self::ORIGINAL, \file_get_contents($this->tree->path(self::VENDOR)));
        self::assertFalse($target->outcome()->isKnown(), 'A faulted write is not a known outcome.');
        self::assertCount(1, $events);
        self::assertSame(EventRecord::MUTATION_FAILED, $events[0]['event']);
        self::assertSame(self::VENDOR, $events[0]['target']);
        self::assertFileDoesNotExist($this->paths()->writeIntent());
        self::assertFileDoesNotExist($this->paths()->marker(), 'The run completed, so its marker went.');
    }

    public function testARepeatedApplyWritesNothingAndMakesNoSecondBackup(): void
    {
        $this->writeFixture();

        $this->runner()->apply();

        $after  = $this->snapshot();
        $events = \count($this->events());

        $report = $this->runner()->apply();

        self::assertSame($after, $this->snapshot(), 'A second apply changes nothing.');
        self::assertCount($events, $this->events(), 'And records nothing.');

        foreach ($report->targets() as $target) {
            self::assertNotSame(TargetStatus::ACTIONABLE, $target->status());
        }
    }

    /**
     * Work item 8: a backup is published atomically, so an interrupted one never
     * appears under the name that claims its hash.
     */
    public function testAnInterruptedBackupIsNeverPublishedAndIsNotTrustedLater(): void
    {
        $this->writeFixture();

        $backup = $this->paths()->backupFile(self::VENDOR, 1, self::digest(self::ORIGINAL));

        // The backup directory is made unwritable while the payload is staged, which
        // is before the backup is published and after the run has committed to it.
        $this->runner(function (string $point, ?string $staged): void {
            if ($point === TargetWriter::STAGED && \str_contains((string) $staged, 'vendor.css')) {
                $this->lockDown($this->paths()->backup());
            }
        })->apply(self::VENDOR);

        self::assertFileDoesNotExist($backup);
        self::assertSame(self::ORIGINAL, \file_get_contents($this->tree->path(self::VENDOR)));

        \chmod($this->paths()->backup(), 0o755);

        $this->runner()->apply(self::VENDOR);

        self::assertSame(self::ORIGINAL, \file_get_contents($backup));
        self::assertSame(self::FIRST, \file_get_contents($this->tree->path(self::VENDOR)));
    }

    /**
     * Work item 9: the deterministic name is a claim about the content, not proof of
     * it, so a backup that no longer hashes to its name is re-made.
     */
    public function testABackupWhoseBytesNoLongerMatchItsNameIsRemade(): void
    {
        $this->writeFixture();

        $backup = $this->paths()->backupFile(self::VENDOR, 1, self::digest(self::ORIGINAL));
        \mkdir(\dirname($backup), 0o755, true);
        \file_put_contents($backup, 'corrupt');

        $this->runner()->apply(self::VENDOR);

        self::assertSame(
            self::ORIGINAL,
            \file_get_contents($backup),
            'The last intact original is what ends up under its own name.'
        );
    }

    public function testARemovalBacksUpTheBytesBeforeTheTargetGoes(): void
    {
        $this->writeFixture();

        $backup = $this->paths()->backupFile(self::REMOVED, 1, self::digest(self::ORIGINAL));

        $this->runner()->apply(self::REMOVED);

        self::assertFileDoesNotExist($this->tree->path(self::REMOVED));
        self::assertSame(self::ORIGINAL, \file_get_contents($backup));

        $removals = \array_values(\array_filter(
            $this->events(),
            static fn (array $record): bool => $record['event'] === EventRecord::REMOVE
        ));

        self::assertCount(1, $removals);
        self::assertSame(EventRecord::ABSENT, $removals[0]['post']);
    }

    /**
     * A removal told to keep no copy still uses the intent protocol, because its
     * event is the only record it leaves.
     *
     * The merged set only allows `backup="false"` where an earlier object at the
     * target ships the bytes being removed, so revert can still put them back. That
     * is why the fixture is a two-object chain whose first step is already done.
     */
    public function testARemovalToldToKeepNoCopyLeavesNoBackupAndIsStillRecorded(): void
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

        $this->runner()->apply();

        self::assertFileDoesNotExist($this->tree->path(self::REMOVED));
        self::assertSame([], $this->backupNames(), 'No copy was asked for and none was kept.');

        $events = $this->events();
        self::assertCount(1, $events);
        self::assertSame(EventRecord::REMOVE, $events[0]['event']);
        self::assertSame(self::REMOVED, $events[0]['target']);
        self::assertSame(EventRecord::ABSENT, $events[0]['post']);
        self::assertFileDoesNotExist($this->paths()->writeIntent());
    }

    public function testARemovalWhoseBackupFailsLeavesTheTargetInPlace(): void
    {
        $this->writeFixture();
        $this->lockDown($this->paths()->backup());

        $report = $this->runner()->apply(self::REMOVED);

        self::assertFileExists($this->tree->path(self::REMOVED));
        self::assertStringContainsString(
            'backup',
            $this->target($report, self::REMOVED)->outcome()->describe()
        );
        self::assertSame([], $this->events());
    }

    public function testARepeatedRemovalOfAnAbsentTargetWritesNothing(): void
    {
        $this->writeFixture();
        $this->runner()->apply(self::REMOVED);

        $after  = $this->snapshot();
        $report = $this->runner()->apply(self::REMOVED);

        self::assertSame($after, $this->snapshot());
        self::assertSame(TargetStatus::OK, $this->target($report, self::REMOVED)->status());
    }

    /**
     * Ticket 31: the path decision is taken at the move, so a precondition that held
     * during the preview and does not hold now stops the write.
     */
    public function testAPreconditionThatChangesBetweenTheDryRunAndTheApplyIsCaught(): void
    {
        $this->writeFixture();

        $plan = $this->target($this->runner()->dryRun(self::VENDOR), self::VENDOR)->plan();
        self::assertTrue($plan[0]->isPerformable(), 'The preview said it could be done.');

        // Between the two, somebody re-cuts the payload badly.
        $this->payload('vendor.css.v1', self::SECOND);

        $report = $this->runner()->apply(self::VENDOR);

        self::assertSame(self::ORIGINAL, \file_get_contents($this->tree->path(self::VENDOR)));
        self::assertStringContainsString(
            'hashes to',
            $this->target($report, self::VENDOR)->outcome()->describe()
        );
    }

    public function testAWholeSetApplyAndASingleTargetApplyAgreeAboutThatTarget(): void
    {
        $this->writeFixture();
        $single = $this->target($this->runner()->apply(self::CHAIN), self::CHAIN);

        $this->tree->write(self::CHAIN, self::ORIGINAL);
        foreach ($this->backupNames() as $name) {
            \unlink($this->paths()->backup() . '/' . $name);
        }

        $whole = $this->target($this->runner()->apply(), self::CHAIN);

        self::assertSame($single->describe(), $whole->describe());
        self::assertSame($single->outcome()->describe(), $whole->outcome()->describe());
        self::assertSame(self::SECOND, \file_get_contents($this->tree->path(self::CHAIN)));
    }

    /**
     * The chain is walked one object at a time, so both backups exist afterwards.
     */
    public function testAChainIsCarriedThroughEveryStepAndPreservesEachDisplacement(): void
    {
        $this->writeFixture();

        $this->runner()->apply(self::CHAIN);

        self::assertSame(self::SECOND, \file_get_contents($this->tree->path(self::CHAIN)));
        self::assertSame(
            self::ORIGINAL,
            \file_get_contents($this->paths()->backupFile(self::CHAIN, 1, self::digest(self::ORIGINAL)))
        );
        self::assertSame(
            self::FIRST,
            \file_get_contents($this->paths()->backupFile(self::CHAIN, 2, self::digest(self::FIRST)))
        );
    }

    public function testTheStateArtefactsAreAtTheirExactPathsAndACompletedRunClearsUp(): void
    {
        $this->writeFixture();

        $paths = $this->paths();
        $this->runner()->apply();

        self::assertSame($paths->state() . '/run.lock', $paths->lock());
        self::assertSame($paths->state() . '/run.marker', $paths->marker());
        self::assertSame($paths->state() . '/write.intent', $paths->writeIntent());
        self::assertSame($paths->state() . '/events.log', $paths->eventLog());

        self::assertFileExists($paths->lock(), 'The lock file is created once and never deleted.');
        self::assertFileExists($paths->eventLog());
        self::assertFileDoesNotExist($paths->marker(), 'A completed run removes its marker.');
        self::assertFileDoesNotExist($paths->writeIntent());
        self::assertSame([], $this->stagingNames(), 'A completed run leaves staging empty.');
        self::assertTrue($this->lockIsFree());
    }

    public function testAConcurrentRunCannotAcquireTheLockAndLeavesTheMarkerAlone(): void
    {
        $this->writeFixture();

        $held = new RunLock($this->paths());
        self::assertTrue($held->acquire());

        try {
            $before = $this->snapshot();
            $report = $this->runner()->apply();

            self::assertSame($before, $this->snapshot(), 'The losing run touches nothing.');
            self::assertFileDoesNotExist($this->paths()->marker());
            self::assertTrue($report->isFaulted());
            self::assertStringContainsString(
                RunLock::IN_PROGRESS,
                \implode("\n", $report->faults())
            );
        } finally {
            $held->release();
        }
    }

    /**
     * Decision 32: an intent may go only once an event carries its correlation id.
     *
     * `state/write.intent` holds one record at one path, published by atomic rename.
     * A run that carried on to the next target would publish a second intent over the
     * first, and the mutation the first one describes has already happened on disk
     * with no event naming it - so recovery would have nothing left to rebuild it
     * from. The run therefore stops where the intent stopped.
     */
    public function testARunStopsAtTheFirstTargetThatLeavesAnIntentOutstanding(): void
    {
        $this->writeFixture();

        $log  = $this->paths()->eventLog();
        $made = false;

        // A directory at the log's path: the mutation lands and its event cannot be
        // appended, which is exactly what leaves an intent outstanding. `state/`
        // itself stays writable, so a second intent could be published over the
        // first, and nothing but the runner stops it.
        $report = $this->runner(function (string $point) use ($log, &$made): void {
            if ($point === TargetWriter::MUTATED && !$made) {
                $made = true;
                \mkdir($log);
            }
        })->apply();

        $intent = (new WriteIntentStore($this->paths()))->read()->intent;

        self::assertNotNull($intent, 'The interrupted mutation still owns the intent record.');
        self::assertSame(
            self::VENDOR,
            EventRecord::fromPayload($intent->event)?->target,
            'And it is the first target\'s, not one a later target published over it.'
        );

        self::assertSame(self::FIRST, \file_get_contents($this->tree->path(self::VENDOR)));
        self::assertFileDoesNotExist($this->tree->path(self::CREATED), 'The run went no further.');
        self::assertSame(self::ORIGINAL, \file_get_contents($this->tree->path(self::CHAIN)));
        self::assertFileExists($this->tree->path(self::REMOVED));

        foreach ([self::CREATED, self::CHAIN, self::FOREIGN_TARGET, self::REMOVED] as $targetPath) {
            self::assertStringContainsString(
                'is still outstanding, so this run went no further',
                $this->target($report, $targetPath)->outcome()->describe(),
                $targetPath . ' was not told why it was left alone.'
            );
        }

        self::assertFileExists($this->paths()->marker(), 'The run did not complete.');
        self::assertTrue($this->lockIsFree(), 'And it still released the lock.');
    }

    /**
     * Brief §5.3: the plan an apply reports has to agree with what the apply did.
     *
     * A target the package cannot read is not a target with nothing at it. Reported
     * as the second, the line says the operation displaces nothing and needs no
     * backup, while the write layer refuses the move for the opposite reason - and
     * the operator reads a plan that contradicts the outcome beside it.
     */
    public function testAnUnreadableTargetIsReportedAsUnreadableRatherThanAbsent(): void
    {
        $this->writeFixture();

        // created.css was absent when the walk read it, so the run planned a create.
        // Somebody puts a file there, unreadable, while the target before it is
        // part-way through - which is inside the lock and after the only read that
        // judged it.
        $report = $this->runner(function (string $point, ?string $staged): void {
            if ($point === TargetWriter::STAGED && \str_contains((string) $staged, 'vendor.css')) {
                $this->lockDown($this->tree->write(self::CREATED, self::FOREIGN), 0o000);
            }
        })->apply();

        $target = $this->target($report, self::CREATED);
        $line   = $target->plan()[0];

        self::assertSame(TargetStatus::FAULTED, $target->status());

        // Readable again only now that the run and its report are done with it.
        \chmod($this->tree->path(self::CREATED), 0o644);

        self::assertSame(
            self::FOREIGN,
            \file_get_contents($this->tree->path(self::CREATED)),
            'The bytes nobody could read are left exactly where they are.'
        );

        self::assertFalse(
            $line->displaced->isDetermined(),
            'What would be displaced is unknown, not determined to be nothing.'
        );
        self::assertFalse($line->backup->isKnown());
        self::assertFalse(
            $line->backup->isDetermined(),
            'And no backup destination is computed for bytes the package cannot hash.'
        );
        self::assertFalse($line->isPerformable());
        self::assertStringContainsString(
            'could not be read, so nothing may be displaced there',
            \implode('; ', $line->refusals)
        );
        self::assertStringNotContainsString(
            'the target does not exist',
            $line->describe(),
            'The plan must not read as a create over an empty path.'
        );
    }

    /**
     * Decision 39: only mutations and recovery outcomes are durable. A run of checks
     * after a write must not displace the write it reports.
     */
    public function testChecksAfterAWriteRecordNothingAndStillReportThatWrite(): void
    {
        $this->writeFixture();
        $this->runner()->apply(self::VENDOR);

        $events = $this->events();
        self::assertCount(1, $events);
        self::assertSame(EventRecord::WRITE, $events[0]['event']);

        $last = null;

        for ($run = 0; $run < 3; $run++) {
            $last = $this->target($this->runner()->check(), self::VENDOR)->lastPackageWrite;
            $this->runner()->dryRun();
        }

        self::assertCount(1, $this->events(), 'Checks and dry runs append nothing.');
        self::assertTrue($last?->isKnown());
        self::assertStringContainsString(EventRecord::WRITE, (string) $last?->value);
    }
}
