<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventLog;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventLogHistory;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventRecord;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\ReportOnlyHistory;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunMarker;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;
use YakShaver\ECSKCCustomisation\Tests\Support\WriteRun;

/**
 * The two report fields the walk cannot answer, read from the package's own records.
 *
 * Each test builds the log through the appender rather than by hand wherever the
 * question is about history, because a history assembled by the writer is the history
 * the runtime will actually meet.
 */
#[CoversClass(EventLogHistory::class)]
#[CoversClass(ReportOnlyHistory::class)]
final class EventLogHistoryTest extends TestCase
{
    private const TARGET = 'templates/et_tennis/css/override.css';

    private const OTHER = 'components/com_kunena/template/aurelia/assets/css/kunena.css';

    private const PAYLOAD_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private const ORIGINAL_HASH = 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad';

    /** 2026-09-09T11:22:33Z. */
    private const AT = 1788952953;

    private TempTree $tree;

    private PackagePaths $paths;

    protected function setUp(): void
    {
        $this->tree  = TempTree::create('ecskc-history-');
        $this->paths = new PackagePaths($this->tree->path('com_ecskccustom'));

        \mkdir($this->paths->state(), 0755, true);
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    private function history(): EventLogHistory
    {
        return new EventLogHistory($this->paths);
    }

    private function append(EventRecord $record): void
    {
        $log = new EventLog($this->paths);

        self::assertTrue($log->append($record), (string) $log->failure());
    }

    private function write(
        string $id,
        string $target = self::TARGET,
        ?int $at = self::AT,
        string $attribution = EventRecord::PACKAGE,
    ): EventRecord {
        return EventRecord::mutation(
            EventRecord::WRITE,
            EventRecord::COPY,
            $target,
            self::ORIGINAL_HASH,
            self::PAYLOAD_HASH,
            $id,
            $attribution,
            at: $at
        );
    }

    public function testAnUnwrittenTargetIsADeterminedNegativeRatherThanAFault(): void
    {
        $answer = $this->history()->lastWriteFor(self::TARGET);

        self::assertTrue($answer->isDetermined());
        self::assertFalse($answer->isKnown());
        self::assertStringContainsString(self::TARGET, (string) $answer->reason);
        self::assertSame([], $this->history()->recordFaults());
    }

    public function testTheLastWriteIsReadFromTheRecordAndNamesItsTimeAndTransition(): void
    {
        $this->append($this->write('a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4'));

        $answer = $this->history()->lastWriteFor(self::TARGET);

        self::assertTrue($answer->isKnown());
        self::assertSame(
            '2026-09-09T11:22:33Z write (package), ' . self::ORIGINAL_HASH . ' to '
            . self::PAYLOAD_HASH,
            $answer->describe()
        );
    }

    public function testAWriteFollowedBySeveralChecksStillReportsTheWrite(): void
    {
        $this->append($this->write('a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4'));

        foreach (['first check', 'second check', 'third check'] as $reason) {
            $this->append(EventRecord::reportOnly(
                EventRecord::REPORT,
                $reason,
                self::TARGET,
                self::PAYLOAD_HASH,
                at: self::AT + 600
            ));
        }

        $this->append(EventRecord::reportOnly(
            EventRecord::DRY_RUN,
            'would advance to v3',
            self::TARGET,
            self::PAYLOAD_HASH,
            EventRecord::COPY,
            self::AT + 900
        ));

        self::assertSame(
            '2026-09-09T11:22:33Z write (package), ' . self::ORIGINAL_HASH . ' to '
            . self::PAYLOAD_HASH,
            $this->history()->lastWriteFor(self::TARGET)->describe(),
            'A later check appends to the same log and must not displace the write.'
        );
    }

    public function testAFreezeIsNotAWriteAtTheTargetItHolds(): void
    {
        $this->append(EventRecord::mutation(
            EventRecord::FREEZE,
            EventRecord::FREEZING,
            self::TARGET,
            EventRecord::ABSENT,
            EventRecord::PRESENT,
            'b2c3d4e5b2c3d4e5b2c3d4e5b2c3d4e5',
            at: self::AT
        ));

        $answer = $this->history()->lastWriteFor(self::TARGET);

        self::assertFalse(
            $answer->isKnown(),
            'Freezing mutates the marker under backup/, not the target.'
        );
        self::assertTrue($answer->isDetermined());
    }

    public function testTheAnswerIsPerTargetAndNotThePackagesLastWriteAnywhere(): void
    {
        $this->append($this->write('a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4', self::OTHER));

        $history = $this->history();

        self::assertTrue($history->lastWriteFor(self::OTHER)->isKnown());
        self::assertFalse($history->lastWriteFor(self::TARGET)->isKnown());
    }

    public function testTwoIdenticalTransitionsStayDistinctAndTheLaterWriteWins(): void
    {
        $first  = $this->write('a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4');
        $second = $this->write('c3d4e5f6c3d4e5f6c3d4e5f6c3d4e5f6', at: self::AT + 3600);

        $this->append($first);
        $this->append($second);

        $records = (new EventLog($this->paths))->read()->records();

        self::assertNotSame(
            $records[0]->correlationId,
            $records[1]->correlationId,
            'Target, operation, pre-state and post-state are identical; only the id differs.'
        );
        self::assertSame(
            '2026-09-09T12:22:33Z write (package), ' . self::ORIGINAL_HASH . ' to '
            . self::PAYLOAD_HASH,
            $this->history()->lastWriteFor(self::TARGET)->describe()
        );
    }

    public function testARecoveredWriteIsStillTheLastWriteAndSaysItWasObserved(): void
    {
        $this->append($this->write(
            'd4e5f6a7d4e5f6a7d4e5f6a7d4e5f6a7',
            attribution: EventRecord::OBSERVED
        ));

        $answer = $this->history()->lastWriteFor(self::TARGET);

        self::assertTrue($answer->isKnown());
        self::assertStringContainsString(
            '(observed)',
            $answer->describe(),
            'Attribution travels on the record: recovery saw the bytes, it did not write them.'
        );
    }

    public function testOneReadAnswersBothTheLastWriteAndTheDamagedLineBesideIt(): void
    {
        $this->append($this->write('a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4'));
        \file_put_contents($this->paths->eventLog(), "torn bytes with no token\n", \FILE_APPEND);

        $history = $this->history();

        self::assertTrue($history->lastWriteFor(self::TARGET)->isKnown());
        self::assertCount(1, $history->recordFaults());
        self::assertStringContainsString('damaged record on line 2', $history->recordFaults()[0]);
    }

    public function testADamagedTailDoesNotMakeTheReaderFaultForGood(): void
    {
        \file_put_contents($this->paths->eventLog(), '{"event":"wri');

        self::assertCount(1, $this->history()->recordFaults());

        $this->append($this->write('a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4'));

        $history = $this->history();

        self::assertCount(1, $history->recordFaults(), 'Still the one damaged line.');
        self::assertTrue(
            $history->lastWriteFor(self::TARGET)->isKnown(),
            'The record appended after it is read like any other.'
        );
    }

    public function testALogThatCannotBeReadLeavesTheWriteUnknownAndReportsTheReason(): void
    {
        \mkdir($this->paths->eventLog(), 0755);

        $history = $this->history();
        $answer  = $history->lastWriteFor(self::TARGET);

        self::assertFalse(
            $answer->isDetermined(),
            'A log nobody can read is not a site nobody has written to.'
        );
        self::assertStringContainsString('is unknown', (string) $answer->reason);
        self::assertStringContainsString('is not the regular file', (string) $answer->reason);
        self::assertCount(1, $history->recordFaults());
    }

    public function testTheHistoryReadsTheLogOnceSoOneReportHasOneHistory(): void
    {
        $this->append($this->write('a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4'));

        $history = $this->history();
        $before  = $history->lastWriteFor(self::TARGET)->describe();

        $this->append($this->write('c3d4e5f6c3d4e5f6c3d4e5f6c3d4e5f6', at: self::AT + 3600));

        self::assertSame(
            $before,
            $history->lastWriteFor(self::TARGET)->describe(),
            'Targets later in one report must not see a different history from earlier ones.'
        );
        self::assertSame(
            '2026-09-09T12:22:33Z write (package), ' . self::ORIGINAL_HASH . ' to '
            . self::PAYLOAD_HASH,
            $this->history()->lastWriteFor(self::TARGET)->describe(),
            'The next run reads the record the last one appended.'
        );
    }

    /**
     * The writer had already written the commit mark, so the record is committed.
     *
     * Read at the history's own API rather than the reader's, because it is
     * `lastWriteFor()` and `recordFaults()` that the ticket requires to be unchanged
     * by the later append that only seals the line.
     */
    public function testBothAnswersAreUnchangedByTheAppendThatSealsACommittedTail(): void
    {
        \file_put_contents(
            $this->paths->eventLog(),
            $this->write('a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4')->toJson() . EventLog::COMMITTED
        );

        $before = $this->history();
        $write  = $before->lastWriteFor(self::TARGET)->describe();
        $faults = $before->recordFaults();

        self::assertSame([], $faults, 'A commit mark without LF is not damage.');

        // A check rather than a write, so that what the later append changes is only
        // the line boundary and not which record is the last write.
        $this->append(EventRecord::reportOnly(
            EventRecord::REPORT,
            'a later run of checks',
            self::TARGET,
            self::PAYLOAD_HASH,
            at: self::AT + 3600
        ));

        $after = $this->history();

        self::assertSame($faults, $after->recordFaults());
        self::assertSame($write, $after->lastWriteFor(self::TARGET)->describe());
        self::assertStringStartsWith(
            '2026-09-09T11:22:33Z write',
            $write,
            'The sealed tail answered as a committed write both times.'
        );
        self::assertStringEndsWith(
            "\tcommitted\n",
            $this->tree->read('com_ecskccustom/state/events.log'),
            'Nothing was truncated, and the later record is itself complete.'
        );
    }

    public function testACleanTreeReportsNoInterruption(): void
    {
        $interruption = $this->history()->interruption();

        self::assertFalse($interruption->isKnown());
        self::assertTrue($interruption->isDetermined());
        self::assertSame(RunMarker::NO_MARKER, $interruption->reason);
    }

    public function testAMarkerLeftBehindWithAFreeLockIsTheInterruption(): void
    {
        $run = WriteRun::holding($this->paths->root());
        $run->kill();

        $interruption = $this->history()->interruption();

        self::assertTrue(
            $interruption->isKnown(),
            'The marker survives the run that died; the lock does not.'
        );
        self::assertMatchesRegularExpression(
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/',
            (string) $interruption->value
        );
    }

    public function testALiveRunIsNotAnInterruptionOfAnEarlierOne(): void
    {
        $run = WriteRun::holding($this->paths->root());

        try {
            $interruption = $this->history()->interruption();

            self::assertFalse($interruption->isKnown());
            self::assertTrue($interruption->isDetermined());
            self::assertStringContainsString('in progress', (string) $interruption->reason);
        } finally {
            $run->kill();
        }
    }

    public function testAnIntentLeftWithNoMarkerIsStillAnInterruption(): void
    {
        $run = WriteRun::holding($this->paths->root(), 'marker+intent');
        $run->kill();

        // The marker is what a run publishes first, so removing it leaves the shape
        // that should not arise: a mutation may have happened with no record of a run.
        \unlink($this->paths->marker());

        $interruption = $this->history()->interruption();

        self::assertTrue($interruption->isKnown());
        self::assertStringContainsString('write intent', (string) $interruption->value);
    }

    public function testAnUnreadableMarkerIsAFaultOnTheFieldRatherThanACleanRun(): void
    {
        \file_put_contents($this->paths->marker(), "the run began at some point\n");

        $interruption = $this->history()->interruption();

        self::assertFalse($interruption->isDetermined());
        self::assertFalse($interruption->isKnown());
        self::assertStringContainsString('ISO-8601', (string) $interruption->reason);
    }

    public function testTheReportOnlyHistoryStillAnswersAndHasNoRecordFaults(): void
    {
        $history = new ReportOnlyHistory();

        self::assertFalse($history->lastWriteFor(self::TARGET)->isKnown());
        self::assertTrue($history->lastWriteFor(self::TARGET)->isDetermined());
        self::assertSame([], $history->recordFaults());
    }
}
