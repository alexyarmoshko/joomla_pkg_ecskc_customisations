<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\State;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\ProcDescriptor;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventLog;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventLogRead;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventRecord;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntent;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;
use YakShaver\ECSKCCustomisation\Tests\Support\WriteRun;

/**
 * The log writer and reader, on a real tree.
 *
 * Record boundaries are the whole point of this file, so the assertions look at bytes
 * wherever the contract is about bytes. A damaged tail is written by hand rather than
 * by killing a process mid-write: the reader's contract is about what is on disk, and
 * bytes laid down deliberately are the same bytes with a reproducible shape.
 */
#[CoversClass(EventLog::class)]
#[CoversClass(EventLogRead::class)]
#[CoversClass(EventRecord::class)]
#[CoversClass(ProcDescriptor::class)]
final class EventLogTest extends TestCase
{
    private const TARGET = 'templates/et_tennis/css/override.css';

    private const PAYLOAD_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private const ORIGINAL_HASH = 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad';

    /** 2026-09-09T11:22:33Z. */
    private const AT = 1788952953;

    private TempTree $tree;

    private PackagePaths $paths;

    protected function setUp(): void
    {
        $this->tree  = TempTree::create('ecskc-events-');
        $this->paths = new PackagePaths($this->tree->path('com_ecskccustom'));

        \mkdir($this->paths->state(), 0755, true);
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    private function log(): EventLog
    {
        return new EventLog($this->paths);
    }

    private function write(string $id = 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4'): EventRecord
    {
        return EventRecord::mutation(
            EventRecord::WRITE,
            EventRecord::COPY,
            self::TARGET,
            self::ORIGINAL_HASH,
            self::PAYLOAD_HASH,
            $id,
            at: self::AT
        );
    }

    public function testTheLogIsCreatedAtItsExactPathUnderStateAndIsNotRemoved(): void
    {
        $log = $this->log();

        self::assertSame($this->paths->state() . '/events.log', $log->path());
        self::assertSame($this->paths->eventLog(), $log->path());
        self::assertFileDoesNotExist($log->path());

        self::assertTrue($log->append($this->write()));
        self::assertFileExists($log->path());

        $log->read();

        self::assertFileExists($log->path());
    }

    public function testReadingDoesNotCreateTheLog(): void
    {
        $read = $this->log()->read();

        self::assertTrue($read->isDetermined(), 'A site with no history is not a fault.');
        self::assertSame([], $read->records());
        self::assertSame([], $read->recordFaults());
        self::assertSame(EventLog::NO_LOG, $read->reason);
        self::assertFileDoesNotExist($this->paths->eventLog());
    }

    public function testAnUnknownReadableKindCannotBeAppendedByThisVersion(): void
    {
        $record = EventRecord::fromPayload([
            'event' => 'report',
            'at'    => '2026-09-09T11:22:33Z',
            'by'    => EventRecord::PACKAGE,
            'reason' => 'a legacy check record',
        ]);

        self::assertNotNull($record, 'Unknown kinds remain readable for compatibility.');

        $log = $this->log();

        self::assertFalse($log->append($record));
        self::assertSame(
            'the event log accepts only mutation and recovery records, not report',
            $log->failure()
        );
        self::assertFileDoesNotExist($this->paths->eventLog());
    }

    public function testACommittedRecordIsOneJsonObjectThenTabCommittedThenLineFeed(): void
    {
        self::assertTrue($this->log()->append($this->write()));

        $bytes = $this->tree->read('com_ecskccustom/state/events.log');

        self::assertSame(
            '{"event":"write","at":"2026-09-09T11:22:33Z","by":"package",'
            . '"target":"' . self::TARGET . '","operation":"copy",'
            . '"pre":"' . self::ORIGINAL_HASH . '","post":"' . self::PAYLOAD_HASH . '",'
            . '"id":"a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4"}'
            . "\tcommitted\n",
            $bytes
        );
    }

    /**
     * @return array<string, array{0: EventRecord}>
     */
    public static function everyRecordedMutation(): array
    {
        $id = 'b2c3d4e5b2c3d4e5b2c3d4e5b2c3d4e5';

        return [
            'a file created where none existed' => [
                EventRecord::mutation(
                    EventRecord::WRITE,
                    EventRecord::COPY,
                    self::TARGET,
                    EventRecord::ABSENT,
                    self::PAYLOAD_HASH,
                    $id
                ),
            ],
            'a removal told to keep no copy' => [
                EventRecord::mutation(
                    EventRecord::REMOVE,
                    EventRecord::REMOVAL,
                    self::TARGET,
                    self::PAYLOAD_HASH,
                    EventRecord::ABSENT,
                    $id,
                    reason: 'the rule keeps no backup, so this record is the only trace'
                ),
            ],
            'a revert' => [
                EventRecord::mutation(
                    EventRecord::REVERT,
                    EventRecord::COPY,
                    self::TARGET,
                    self::PAYLOAD_HASH,
                    self::ORIGINAL_HASH,
                    $id
                ),
            ],
            'a redo' => [
                EventRecord::mutation(
                    EventRecord::REDO,
                    EventRecord::COPY,
                    self::TARGET,
                    self::ORIGINAL_HASH,
                    self::PAYLOAD_HASH,
                    $id
                ),
            ],
            'a freeze' => [
                EventRecord::mutation(
                    EventRecord::FREEZE,
                    EventRecord::FREEZING,
                    self::TARGET,
                    EventRecord::ABSENT,
                    EventRecord::PRESENT,
                    $id
                ),
            ],
            'an unfreeze, which deletes its own evidence' => [
                EventRecord::mutation(
                    EventRecord::UNFREEZE,
                    EventRecord::RELEASE,
                    self::TARGET,
                    EventRecord::PRESENT,
                    EventRecord::ABSENT,
                    $id
                ),
            ],
            'a recovered write, observed rather than performed' => [
                EventRecord::mutation(
                    EventRecord::WRITE,
                    EventRecord::COPY,
                    self::TARGET,
                    EventRecord::ABSENT,
                    self::PAYLOAD_HASH,
                    $id,
                    EventRecord::OBSERVED
                ),
            ],
            'a mutation failure after intent publication' => [
                EventRecord::mutation(
                    EventRecord::MUTATION_FAILED,
                    EventRecord::COPY,
                    self::TARGET,
                    self::ORIGINAL_HASH,
                    self::PAYLOAD_HASH,
                    $id,
                    reason: 'the move failed and the target still holds its pre-state'
                ),
            ],
            'an abandoned intent' => [
                EventRecord::mutation(
                    EventRecord::INTENT_ABANDONED,
                    EventRecord::COPY,
                    self::TARGET,
                    self::ORIGINAL_HASH,
                    self::PAYLOAD_HASH,
                    $id,
                    EventRecord::OBSERVED,
                    reason: 'the target still holds its pre-state, so the write did not happen'
                ),
            ],
            'an unresolved intent' => [
                EventRecord::mutation(
                    EventRecord::UNRESOLVED_INTENT,
                    EventRecord::COPY,
                    self::TARGET,
                    self::ORIGINAL_HASH,
                    self::PAYLOAD_HASH,
                    $id,
                    EventRecord::OBSERVED,
                    observed: 'c0ffee1c0ffee1c0ffee1c0ffee1c0ffee1c0ffee1c0ffee1c0ffee1c0ffee1c',
                    reason: 'the target matches neither state'
                ),
            ],
        ];
    }

    #[DataProvider('everyRecordedMutation')]
    public function testTheLogRecordsEveryMutationTheBriefNames(EventRecord $record): void
    {
        $log = $this->log();

        self::assertTrue($log->append($record), (string) $log->failure());

        $read = $log->read();

        self::assertSame([], $read->recordFaults());
        self::assertCount(1, $read->records());
        self::assertSame($record->toPayload(), $read->records()[0]->toPayload());
    }

    public function testRecordsAreAppendedInOrderAndNothingEarlierIsRewritten(): void
    {
        $log   = $this->log();
        $first = EventRecord::mutation(
            EventRecord::FREEZE,
            EventRecord::FREEZING,
            self::TARGET,
            EventRecord::ABSENT,
            EventRecord::PRESENT,
            'b2c3d4e5b2c3d4e5b2c3d4e5b2c3d4e5'
        );

        self::assertTrue($log->append($first));
        self::assertTrue($log->append($this->write()));

        $read = $log->read();

        self::assertCount(2, $read->records());
        self::assertSame(EventRecord::FREEZE, $read->records()[0]->kind);
        self::assertSame(EventRecord::WRITE, $read->records()[1]->kind);
    }

    public function testAPartialRecordIsDamagedAndLeavesEveryCompleteRecordReadable(): void
    {
        $log = $this->log();

        self::assertTrue($log->append($this->write()));

        \file_put_contents($this->paths->eventLog(), '{"event":"write","at":"2026-', \FILE_APPEND);

        $read = $log->read();

        self::assertCount(1, $read->records());
        self::assertCount(1, $read->recordFaults());
        self::assertStringContainsString('damaged record on line 2', $read->recordFaults()[0]);
        self::assertSame(
            self::PAYLOAD_HASH,
            $read->lastWriteAt(self::TARGET)?->post,
            'A damaged tail does not displace the write recorded whole before it.'
        );
    }

    public function testACompleteJsonObjectWithNoCommitTokenStaysDamagedOnceLineFeedArrives(): void
    {
        $log     = $this->log();
        $orphan  = $this->write()->toJson();

        self::assertTrue($log->append($this->write()));
        \file_put_contents($this->paths->eventLog(), $orphan, \FILE_APPEND);

        $before = $log->read();

        self::assertCount(1, $before->records());
        self::assertCount(1, $before->recordFaults());

        self::assertTrue($log->append($this->write('c3d4e5f6c3d4e5f6c3d4e5f6c3d4e5f6')));

        $after = $log->read();

        self::assertCount(2, $after->records(), 'The later append is itself complete.');
        self::assertSame(
            $before->recordFaults(),
            $after->recordFaults(),
            'An LF is not a commit token, so the orphaned object is still damaged.'
        );
        self::assertStringContainsString(
            'does not end with the commit token',
            $after->recordFaults()[0]
        );
        self::assertStringContainsString(
            $orphan . "\n",
            $this->tree->read('com_ecskccustom/state/events.log'),
            'Nothing is truncated or rewritten.'
        );
    }

    public function testATokenWithoutLineFeedIsCommittedWhenRead(): void
    {
        $log = $this->log();

        \file_put_contents(
            $this->paths->eventLog(),
            $this->write()->toJson() . EventLog::COMMITTED
        );

        $before = $log->read();

        self::assertCount(1, $before->records(), 'The writer had already written the commit mark.');
        self::assertSame([], $before->recordFaults());

        self::assertTrue($log->append($this->write('d4e5f6a7d4e5f6a7d4e5f6a7d4e5f6a7')));

        $after = $log->read();

        self::assertSame(
            $before->lastWriteAt(self::TARGET)?->toPayload(),
            $after->records()[0]->toPayload(),
            'The same record before and after the append that seals its line.'
        );
        self::assertSame($before->recordFaults(), $after->recordFaults());
        self::assertCount(2, $after->records());
    }

    public function testAnAppendAfterAPartialJsonTailIsItselfCompleteAndTruncatesNothing(): void
    {
        $log     = $this->log();
        $partial = '{"event":"write","at":"2026-';

        \file_put_contents($this->paths->eventLog(), $partial);

        self::assertTrue($log->append($this->write()));

        $read = $log->read();

        self::assertCount(1, $read->records(), 'The record appended after the tail is whole.');
        self::assertCount(1, $read->recordFaults(), 'The terminated tail is still damaged.');
        self::assertStringStartsWith(
            $partial . "\n",
            $this->tree->read('com_ecskccustom/state/events.log'),
            'The partial bytes are still there, on a line of their own.'
        );
    }

    public function testATokenWithNoJsonObjectInFrontOfItIsDamaged(): void
    {
        \file_put_contents($this->paths->eventLog(), '{"event":"wri' . EventLog::COMMITTED . "\n");

        $read = $this->log()->read();

        self::assertSame([], $read->records());
        self::assertCount(1, $read->recordFaults());
        self::assertStringContainsString('are not one JSON object', $read->recordFaults()[0]);
    }

    public function testACommittedObjectThatIsNotAnEventRecordIsDamaged(): void
    {
        \file_put_contents(
            $this->paths->eventLog(),
            '{"event":"write","at":"yesterday","by":"package"}' . EventLog::COMMITTED . "\n"
        );

        $read = $this->log()->read();

        self::assertSame([], $read->records());
        self::assertCount(1, $read->recordFaults());
        self::assertStringContainsString('is not an event record', $read->recordFaults()[0]);
    }

    public function testAMalformedKnownWriteIsARecordFaultAndDoesNotDisplaceTheLastWrite(): void
    {
        $log   = $this->log();
        $write = $this->write();

        self::assertTrue($log->append($write));

        // A committed object labelled write, with a target and none of the mutation
        // fields recovery would need. Structure is fine; the known kind is not.
        \file_put_contents(
            $this->paths->eventLog(),
            '{"event":"write","at":"2026-09-10T00:00:00Z","by":"package","target":"'
            . self::TARGET . '"}' . EventLog::COMMITTED . "\n",
            \FILE_APPEND
        );

        $read = $log->read();

        self::assertCount(1, $read->records());
        self::assertCount(1, $read->recordFaults());
        self::assertStringContainsString('is not an event record', $read->recordFaults()[0]);
        self::assertSame(
            $write->toPayload(),
            $read->lastWriteAt(self::TARGET)?->toPayload(),
            'A known write missing its mutation fields is damaged, not the latest write.'
        );
    }

    public function testANewLogIsMode0644EvenWhenUmaskWouldLeaveItWider(): void
    {
        $previous = \umask(0o000);

        try {
            self::assertTrue($this->log()->append($this->write()));
            self::assertSame(
                0o644,
                \fileperms($this->paths->eventLog()) & 0o777,
                'The mode is set on the opened inode, not left to the process umask.'
            );
        } finally {
            \umask($previous);
        }
    }

    public function testCompleteRecordsOnBothSidesOfADamagedLineStayReadable(): void
    {
        $log = $this->log();

        self::assertTrue($log->append($this->write()));
        \file_put_contents($this->paths->eventLog(), "not a record at all\n", \FILE_APPEND);
        self::assertTrue($log->append($this->write('e5f6a7b8e5f6a7b8e5f6a7b8e5f6a7b8')));

        $read = $log->read();

        self::assertCount(2, $read->records());
        self::assertCount(1, $read->recordFaults());
        self::assertStringContainsString('line 2', $read->recordFaults()[0]);
    }

    public function testALogThatCannotBeReadIsAFaultAndNotAnEmptyHistory(): void
    {

        \mkdir($this->paths->eventLog(), 0755);

        $read = $this->log()->read();

        self::assertFalse($read->isDetermined());
        self::assertSame([], $read->records());
        self::assertStringContainsString('is not the regular file', (string) $read->reason);
    }

    public function testALogThatCannotBeAppendedToReportsItsReasonAndIsNotItselfAppended(): void
    {
        \mkdir($this->paths->eventLog(), 0755);

        $log = $this->log();

        self::assertFalse($log->append($this->write()));
        self::assertStringContainsString('is not the regular file', (string) $log->failure());
        self::assertSame(
            [],
            \array_diff(\scandir($this->paths->eventLog()) ?: [], ['.', '..']),
            'The I/O fault is carried on the report, never written into the log.'
        );
    }

    /**
     * A FIFO at the path, which is the one damaged shape that would not fail loudly.
     *
     * Opening it for reading blocks until somebody writes to it, so without the guard
     * a report would hang instead of saying what is wrong. Nothing in this test opens
     * it, which is the point: a hang here would be the failure.
     */
    public function testAFifoAtTheLogPathIsAFaultRatherThanAReportThatNeverReturns(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('The case needs posix_mkfifo().');
        }

        self::assertTrue(\posix_mkfifo($this->paths->eventLog(), 0644));

        $log  = $this->log();
        $read = $log->read();

        self::assertFalse($read->isDetermined());
        self::assertStringContainsString('is not the regular file', (string) $read->reason);

        self::assertFalse($log->append($this->write()));
        self::assertStringContainsString('is not the regular file', (string) $log->failure());
    }

    /**
     * A link at the log path, which only a hand could have put there.
     *
     * `fopen()` follows one, so appending through it would write the package's history
     * into whatever the link names, and reading through it would report a history from
     * somewhere else as this site's. The package writes the log directly.
     */
    public function testASymbolicLinkAtTheLogPathIsRefusedForReadingAndForAppending(): void
    {
        $elsewhere = $this->tree->write('elsewhere.log', '');
        \symlink($elsewhere, $this->paths->eventLog());

        $log  = $this->log();
        $read = $log->read();

        self::assertFalse($read->isDetermined());
        self::assertStringContainsString('is not the regular file', (string) $read->reason);

        self::assertFalse($log->append($this->write()));
        self::assertStringContainsString('is not the regular file', (string) $log->failure());
        self::assertSame('', \file_get_contents($elsewhere), 'Nothing was written through it.');
    }

    public function testAnEmptyLogHoldsNoRecordAndIsNotDamaged(): void
    {
        \touch($this->paths->eventLog());

        $read = $this->log()->read();

        self::assertTrue($read->isDetermined());
        self::assertSame([], $read->records());
        self::assertSame([], $read->recordFaults());
        self::assertSame(EventLog::EMPTY_LOG, $read->reason);
    }

    /**
     * Two real processes, because an exclusive lock per append is the claim.
     *
     * Each child appends its own run of records as fast as it can, so the two overlap.
     * What must hold afterwards is every record whole, none interleaved, and the exact
     * count of both runs.
     */
    public function testTwoProcessesAppendingAtOnceProduceOnlyCompleteRecords(): void
    {
        $each     = 120;
        $appenders = [
            WriteRun::appending($this->paths->root(), 'first', $each),
            WriteRun::appending($this->paths->root(), 'second', $each),
        ];

        foreach ($appenders as $appender) {
            self::assertSame(0, $appender->wait(), 'The child could not append its records.');
        }

        $read = $this->log()->read();

        self::assertSame([], $read->recordFaults(), 'No record was torn by the other writer.');
        self::assertCount($each * 2, $read->records());

        $byReason = [];

        foreach ($read->records() as $record) {
            $byReason[(string) $record->reason] = ($byReason[(string) $record->reason] ?? 0) + 1;
        }

        // Sorted because which child reached the log first is the race under test.
        \ksort($byReason);

        self::assertSame(['first' => $each, 'second' => $each], $byReason);
    }

    /**
     * @return array<string, array{0: callable(): EventRecord}>
     */
    public static function recordsNoCallerMayBuild(): array
    {
        $id = 'f6a7b8c9f6a7b8c9f6a7b8c9f6a7b8c9';

        return [
            'a kind that is not a mutation' => [static fn (): EventRecord => EventRecord::mutation(
                'report',
                EventRecord::COPY,
                self::TARGET,
                EventRecord::ABSENT,
                self::PAYLOAD_HASH,
                $id
            )],
            'a target that climbs out of the tree' => [static fn (): EventRecord => EventRecord::mutation(
                EventRecord::WRITE,
                EventRecord::COPY,
                '../../etc/passwd',
                EventRecord::ABSENT,
                self::PAYLOAD_HASH,
                $id
            )],
            'a state that is neither absent, present nor a digest' => [
                static fn (): EventRecord => EventRecord::mutation(
                    EventRecord::WRITE,
                    EventRecord::COPY,
                    self::TARGET,
                    'probably the vendor original',
                    self::PAYLOAD_HASH,
                    $id
                ),
            ],
            'a correlation id no intent could carry' => [static fn (): EventRecord => EventRecord::mutation(
                EventRecord::WRITE,
                EventRecord::COPY,
                self::TARGET,
                EventRecord::ABSENT,
                self::PAYLOAD_HASH,
                "a1b2\nc3d4"
            )],
            'an attribution that is neither' => [static fn (): EventRecord => EventRecord::mutation(
                EventRecord::WRITE,
                EventRecord::COPY,
                self::TARGET,
                EventRecord::ABSENT,
                self::PAYLOAD_HASH,
                $id,
                'probably us'
            )],
            'a freeze kind with a copy operation' => [static fn (): EventRecord => EventRecord::mutation(
                EventRecord::FREEZE,
                EventRecord::COPY,
                self::TARGET,
                self::ORIGINAL_HASH,
                self::PAYLOAD_HASH,
                $id
            )],
            'a freeze kind whose states are digests' => [static fn (): EventRecord => EventRecord::mutation(
                EventRecord::FREEZE,
                EventRecord::FREEZING,
                self::TARGET,
                self::ORIGINAL_HASH,
                self::PAYLOAD_HASH,
                $id
            )],
            'an unresolved freeze whose observed state is a digest' => [
                static fn (): EventRecord => EventRecord::mutation(
                    EventRecord::UNRESOLVED_INTENT,
                    EventRecord::FREEZING,
                    self::TARGET,
                    EventRecord::ABSENT,
                    EventRecord::PRESENT,
                    $id,
                    EventRecord::OBSERVED,
                    observed: self::ORIGINAL_HASH,
                    reason: 'the marker matches neither state'
                ),
            ],
            'a freeze failure whose observed state is a digest' => [
                static fn (): EventRecord => EventRecord::mutation(
                    EventRecord::MUTATION_FAILED,
                    EventRecord::FREEZING,
                    self::TARGET,
                    EventRecord::ABSENT,
                    EventRecord::PRESENT,
                    $id,
                    observed: self::ORIGINAL_HASH
                ),
            ],
        ];
    }

    /**
     * @param callable(): EventRecord $build
     */
    #[DataProvider('recordsNoCallerMayBuild')]
    public function testARecordNoReaderCouldSettleIsRefusedBeforeItIsWritten(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);

        $build();
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function freezePayloadsNoReaderMayAccept(): array
    {
        $id     = 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4';
        $common = [
            'at'     => '2026-09-09T11:22:33Z',
            'by'     => EventRecord::PACKAGE,
            'target' => self::TARGET,
            'id'     => $id,
        ];

        return [
            'kind freeze with operation copy and digest states' => [\array_merge($common, [
                'event'     => EventRecord::FREEZE,
                'operation' => EventRecord::COPY,
                'pre'       => self::ORIGINAL_HASH,
                'post'      => self::PAYLOAD_HASH,
            ])],
            'kind freeze with freeze-marker operation and digest states' => [\array_merge($common, [
                'event'     => EventRecord::FREEZE,
                'operation' => EventRecord::FREEZING,
                'pre'       => self::ORIGINAL_HASH,
                'post'      => self::PAYLOAD_HASH,
            ])],
            'unresolved-intent with freeze operation and a digest as observed' => [\array_merge($common, [
                'event'     => EventRecord::UNRESOLVED_INTENT,
                'operation' => EventRecord::FREEZING,
                'pre'       => EventRecord::ABSENT,
                'post'      => EventRecord::PRESENT,
                'observed'  => self::ORIGINAL_HASH,
                'by'        => EventRecord::OBSERVED,
                'reason'    => 'the marker matches neither state',
            ])],
            'mutation-failed freeze with a digest as observed' => [\array_merge($common, [
                'event'     => EventRecord::MUTATION_FAILED,
                'operation' => EventRecord::FREEZING,
                'pre'       => EventRecord::ABSENT,
                'post'      => EventRecord::PRESENT,
                'observed'  => self::ORIGINAL_HASH,
            ])],
        ];
    }

    /**
     * @param array<string, string> $payload
     */
    #[DataProvider('freezePayloadsNoReaderMayAccept')]
    public function testAKnownFreezeEventWithDigestStatesIsNotReadable(array $payload): void
    {
        self::assertNull(EventRecord::fromPayload($payload));
    }

    public function testTheRecordAcceptsExactlyTheCorrelationIdsTheIntentMints(): void
    {
        $id     = WriteIntent::begin(['event' => 'write'])->id;
        $record = $this->write($id);

        self::assertSame($id, $record->correlationId);
    }

    public function testAKindALaterReleaseAddedIsStillReadableHistory(): void
    {
        \file_put_contents(
            $this->paths->eventLog(),
            '{"event":"quarantine","at":"2026-09-09T11:22:33Z","by":"package","target":"'
            . self::TARGET . '","pre":"absent","post":"absent"}' . EventLog::COMMITTED . "\n"
        );

        $read = $this->log()->read();

        self::assertSame([], $read->recordFaults());
        self::assertSame('quarantine', $read->records()[0]->kind);
        self::assertNull(
            $read->lastWriteAt(self::TARGET),
            'A kind this version does not know is not counted as a write at the target.'
        );
    }

    public function testRecoveryReAttributesARecordWithoutMovingItsTimestamp(): void
    {
        $performed = $this->write();
        $observed  = $performed->asObserved();

        self::assertSame(EventRecord::OBSERVED, $observed->attribution);
        self::assertSame($performed->at, $observed->at);
        self::assertSame($performed->correlationId, $observed->correlationId);
        self::assertSame($observed, $observed->asObserved());
    }
}
