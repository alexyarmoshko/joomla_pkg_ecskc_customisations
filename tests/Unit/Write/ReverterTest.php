<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Write;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition\ConditionEvaluator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentValues;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\Hasher;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path\PathPolicy;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleLoader;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleSet;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\SchemaValidator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BackupStore;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventLog;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventRecord;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\FreezeMarkers;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunLock;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunMarker;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntentStore;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ObjectWalker;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\Reverter;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\TargetWriter;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\WriteOutcome;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\WriteRun;
use YakShaver\ECSKCCustomisation\Tests\Support\RuleXml;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

/**
 * Every step moves bytes that are on disk. The chain is three revisions deep, so
 * "one object at a time" is a claim the file content settles after each call,
 * rather than one a single step would satisfy by accident.
 *
 * Rules go in as XML through RuleLoader, as they do in the walk's own tests, so a
 * chain asserted here is one an author can write.
 */
#[CoversClass(Reverter::class)]
#[CoversClass(TargetWriter::class)]
#[CoversClass(WriteOutcome::class)]
#[CoversClass(WriteRun::class)]
final class ReverterTest extends TestCase
{
    private const PACKAGE = 'administrator/components/com_ecskccustom';

    private const TARGET = 'templates/et_tennis/css/vendor.css';

    private const ORIGINAL = "body { color: red; }\r\n.a { top: 0 }";
    private const FIRST    = "body { color: blue; }\r\n.a { top: 0 }";
    private const SECOND   = "body { color: green; }\r\n.a { top: 1 }";
    private const THIRD    = "body { color: black; }\r\n.a { top: 2 }";
    private const FOREIGN  = "body { color: rebeccapurple; }\r\n/* edited on the server */";

    private TempTree $tree;

    private PackagePaths $paths;

    protected function setUp(): void
    {
        $this->tree  = TempTree::create('ecskc-revert-');
        $this->paths = new PackagePaths($this->tree->path(self::PACKAGE));

        foreach (PackagePaths::CREATED_FOLDERS as $folder) {
            \mkdir($this->paths->root() . '/' . $folder, 0755, true);
        }

        $this->tree->write(self::PACKAGE . '/rules/index.html', '');
        $this->tree->write(self::PACKAGE . '/payload/index.html', '');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    public function testRevertWalksTheChainOneObjectAtATime(): void
    {
        $ruleSet = $this->chain();
        $this->backupsForTheWholeChain();
        $this->tree->write(self::TARGET, self::THIRD);

        foreach ([self::SECOND, self::FIRST, self::ORIGINAL] as $step => $expected) {
            $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

            self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
            self::assertTrue($outcome->changed);
            self::assertSame($expected, $this->bytes(), \sprintf('step %d landed elsewhere', $step + 1));
            self::assertCount($step + 1, $this->records(), 'one step appended more than one event');
            $this->assertRunClosed();
        }
    }

    public function testRedoWalksTheChainOneObjectAtATime(): void
    {
        // The state three reverts leave: the file back at the vendor original, with
        // every displaced revision still under backup/. That is the only state a
        // redo is ever asked from, and it is what makes the bytes it displaces
        // reproducible without a backup of its own.
        $ruleSet = $this->chain();
        $this->backupsForTheWholeChain();
        $this->tree->write(self::TARGET, self::ORIGINAL);

        foreach ([self::FIRST, self::SECOND, self::THIRD] as $step => $expected) {
            $outcome = $this->reverter()->redo(self::TARGET, $ruleSet, self::environment());

            self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
            self::assertTrue($outcome->changed);
            self::assertSame($expected, $this->bytes(), \sprintf('step %d landed elsewhere', $step + 1));
            self::assertCount($step + 1, $this->records(), 'one step appended more than one event');
            $this->assertRunClosed();
        }
    }

    public function testAStepIsLoggedUnderItsOwnEventKind(): void
    {
        $ruleSet = $this->chain();
        $this->backupsForTheWholeChain();
        $this->tree->write(self::TARGET, self::THIRD);

        $this->reverter()->revert(self::TARGET, $ruleSet);
        $this->reverter()->redo(self::TARGET, $ruleSet, self::environment());

        $records = $this->records();

        self::assertCount(2, $records);
        self::assertSame(EventRecord::REVERT, $records[0]->kind);
        self::assertSame(EventRecord::REDO, $records[1]->kind);
        self::assertSame(EventRecord::COPY, $records[0]->operation);
        self::assertSame(self::digest(self::THIRD), $records[0]->pre);
        self::assertSame(self::digest(self::SECOND), $records[0]->post);
        self::assertSame(self::TARGET, $records[0]->target);
    }

    public function testRevertRestoresTheNewestBackupAtThatVersion(): void
    {
        $ruleSet = $this->chain();
        $this->tree->write(self::TARGET, self::THIRD);

        $this->backup(3, self::FOREIGN, 1_700_000_000);
        $this->backup(3, self::SECOND, 1_800_000_000);

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertSame(self::SECOND, $this->bytes());
        self::assertStringStartsWith(
            'the revert restored the earlier content of the target',
            $outcome->reason,
            'A backup is not a payload, so the outcome does not call it one.'
        );
    }

    public function testRevertRefusesABackupWhoseBytesNoLongerMatchItsName(): void
    {
        $ruleSet = $this->chain();
        $this->tree->write(self::TARGET, self::THIRD);

        $backup = $this->backup(3, self::SECOND);
        \file_put_contents($backup, self::FOREIGN);

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertStringContainsString('hashes to', $outcome->reason);
        self::assertSame(self::THIRD, $this->bytes());
        self::assertSame([], $this->records());
        $this->assertRunClosed();
    }

    public function testRedoRefusesABackupWhoseBytesNoLongerMatchItsName(): void
    {
        // Inverse of the revert case above. The original is not a shipped payload,
        // so redo's only claim that it can put these bytes back is the backup
        // named for them. The name is a claim, not proof: corrupt bytes under it
        // would let redo destroy the last intact original.
        $ruleSet = $this->chain();
        $this->tree->write(self::TARGET, self::ORIGINAL);

        $backup = $this->backup(1, self::ORIGINAL);
        \file_put_contents($backup, self::FOREIGN);

        $outcome = $this->reverter()->redo(self::TARGET, $ruleSet, self::environment());

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertStringContainsString('writes no backup', $outcome->reason);
        self::assertSame(self::ORIGINAL, $this->bytes());
        self::assertSame([], $this->records());
        $this->assertRunClosed();
    }

    public function testRevertStepsBackThroughTwoObjectsThatShipTheSamePayload(): void
    {
        // NTS KBee topic-edit and error-widget v1/v2: two objects, one payload.
        // After v1 writes, the file holds that payload and only a v1 backup exists.
        // Picking the last matching object looks for a v2 backup that was never
        // made and leaves the file where it is.
        $ruleSet = $this->repeatedPayloadChain();
        $this->backup(1, self::ORIGINAL);
        $this->tree->write(self::TARGET, self::FIRST);

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertTrue($outcome->changed);
        self::assertSame(self::ORIGINAL, $this->bytes());
        $this->assertRunClosed();
    }

    public function testRevertPrefersTheNewestBackupWhenTwoVersionsShipTheSamePayload(): void
    {
        // Both versions ship FIRST. An older v2 backup and a newer v1 backup must
        // restore the v1 original: the newest matching backup is the step, not the
        // last matching object in the rule file.
        $ruleSet = $this->repeatedPayloadChain();
        $this->backup(2, self::FOREIGN, 1_700_000_000);
        $this->backup(1, self::ORIGINAL, 1_800_000_000);
        $this->tree->write(self::TARGET, self::FIRST);

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertTrue($outcome->changed);
        self::assertSame(self::ORIGINAL, $this->bytes());
        $this->assertRunClosed();
    }

    public function testRedoRefusesWhenTheOnlyIntactCopyIsTheLiveTarget(): void
    {
        // The declared sourceHash matches, but the payload file is damaged. The
        // live target is the only intact copy of those bytes, and redo writes no
        // backup of what it displaces.
        $ruleSet = $this->chain();
        $this->backup(1, self::ORIGINAL);
        $this->tree->write(self::TARGET, self::FIRST);
        \file_put_contents($this->paths->payload() . '/vendor.css.v1', self::FOREIGN);

        $outcome = $this->reverter()->redo(self::TARGET, $ruleSet, self::environment());

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertStringContainsString('writes no backup', $outcome->reason);
        self::assertSame(self::FIRST, $this->bytes());
        self::assertSame([], $this->records());
        $this->assertRunClosed();
    }

    public function testRevertRefusesWhenTheOnlyIntactCopyIsTheLiveTarget(): void
    {
        $ruleSet = $this->chain();
        $this->backup(2, self::FIRST);
        $this->tree->write(self::TARGET, self::SECOND);
        \file_put_contents($this->paths->payload() . '/vendor.css.v2', self::FOREIGN);

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertStringContainsString('writes no backup', $outcome->reason);
        self::assertSame(self::SECOND, $this->bytes());
        self::assertSame([], $this->records());
        $this->assertRunClosed();
    }

    public function testRedoRefusesWhenTheMatchingPayloadFileIsMissing(): void
    {
        $ruleSet = $this->chain();
        $this->backup(1, self::ORIGINAL);
        $this->tree->write(self::TARGET, self::FIRST);
        \unlink($this->paths->payload() . '/vendor.css.v1');

        $outcome = $this->reverter()->redo(self::TARGET, $ruleSet, self::environment());

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertStringContainsString('writes no backup', $outcome->reason);
        self::assertSame(self::FIRST, $this->bytes());
        $this->assertRunClosed();
    }

    public function testRevertRefusesWhenTheMatchingPayloadFileIsMissing(): void
    {
        $ruleSet = $this->chain();
        $this->backup(2, self::FIRST);
        $this->tree->write(self::TARGET, self::SECOND);
        \unlink($this->paths->payload() . '/vendor.css.v2');

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertStringContainsString('writes no backup', $outcome->reason);
        self::assertSame(self::SECOND, $this->bytes());
        $this->assertRunClosed();
    }

    public function testRevertRestoresAReusedBackupOverAnOlderSiblingAtTheSameVersion(): void
    {
        $ruleSet = $this->chain();
        $this->tree->write(self::TARGET, self::THIRD);

        $this->backup(3, self::FOREIGN, 1_600_000_000);
        $this->backup(3, self::SECOND, 1_700_000_000);

        // Version 3 later displaced FOREIGN again. The name already held those
        // bytes, so BackupStore keeps the file. Recency has to move with that
        // reuse, or revert would restore SECOND.
        $this->tree->write(self::TARGET, self::FOREIGN);
        $kept = (new BackupStore($this->paths))->preserve(
            self::TARGET,
            3,
            'sha256',
            self::digest(self::FOREIGN),
            $this->tree->path(self::TARGET)
        );

        self::assertTrue($kept->isPreserved(), $kept->reason);

        $this->tree->write(self::TARGET, self::THIRD);

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertSame(self::FOREIGN, $this->bytes());
    }

    public function testRevertRestoresTheNewerOfTwoBackupsPreservedInOneSecond(): void
    {
        $ruleSet = $this->chain();
        $store   = new BackupStore($this->paths);

        foreach ([self::FOREIGN, self::SECOND] as $displaced) {
            $this->tree->write(self::TARGET, $displaced);

            $preserved = $store->preserve(
                self::TARGET,
                3,
                'sha256',
                self::digest($displaced),
                $this->tree->path(self::TARGET)
            );

            self::assertTrue($preserved->isPreserved(), $preserved->reason);
        }

        $this->tree->write(self::TARGET, self::THIRD);

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertSame(self::SECOND, $this->bytes());
    }

    public function testRevertRefusesWhenTheTargetsBackupsCannotBeInspected(): void
    {
        $ruleSet = $this->chain();
        $this->tree->write(self::TARGET, self::THIRD);

        \mkdir($this->tree->path('elsewhere/et_tennis/css'), 0755, true);
        \symlink($this->tree->path('elsewhere'), $this->paths->backup() . '/templates');

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertStringContainsString('could not be inspected', $outcome->reason);
        self::assertSame(self::THIRD, $this->bytes());
        self::assertSame([], $this->records());
        $this->assertRunClosed();
    }

    public function testRedoDoesNotTreatAVersionZeroNameAsABackup(): void
    {
        $ruleSet = $this->chain();
        $this->tree->write(self::TARGET, self::ORIGINAL);

        $directory = \dirname($this->paths->backupFile(self::TARGET, 1, self::digest(self::ORIGINAL)));
        \mkdir($directory, 0755, true);
        \file_put_contents(
            $directory . '/' . \basename(self::TARGET) . '.v0.' . self::digest(self::ORIGINAL),
            self::ORIGINAL
        );

        $outcome = $this->reverter()->redo(self::TARGET, $ruleSet, self::environment());

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertStringContainsString('writes no backup', $outcome->reason);
        self::assertSame(self::ORIGINAL, $this->bytes());
        $this->assertRunClosed();
    }

    public function testRevertAtTheStartOfTheChainRefusesCleanly(): void
    {
        $ruleSet = $this->chain();
        $this->backupsForTheWholeChain();
        $this->tree->write(self::TARGET, self::ORIGINAL);

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertFalse($outcome->changed);
        self::assertStringContainsString('start of its chain', $outcome->reason);
        self::assertSame(self::ORIGINAL, $this->bytes());
        self::assertSame([], $this->records());
        $this->assertRunClosed();
    }

    public function testRedoAtTheHeadOfTheChainRefusesCleanly(): void
    {
        $ruleSet = $this->chain();
        $this->tree->write(self::TARGET, self::THIRD);

        $outcome = $this->reverter()->redo(self::TARGET, $ruleSet, self::environment());

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertFalse($outcome->changed);
        self::assertStringContainsString('head of its chain', $outcome->reason);
        self::assertSame(self::THIRD, $this->bytes());
        self::assertSame([], $this->records());
        $this->assertRunClosed();
    }

    public function testRevertFaultsWhenTheFirstObjectPreservedNothingAndThePredecessorIsUnavailable(): void
    {
        // The file holds v1's payload and no backup of the vendor original remains.
        // Version 1 still names that original; without a verified copy the step faults
        // rather than pretending the chain has nowhere to go, and without taking the
        // vendor's file away the way a package-owned create would.
        $ruleSet = $this->chain();
        $this->tree->write(self::TARGET, self::FIRST);

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertStringContainsString('names predecessor', $outcome->reason);
        self::assertStringNotContainsString('start of its chain', $outcome->reason);
        self::assertSame(self::FIRST, $this->bytes());
        self::assertSame([], $this->records());
        $this->assertRunClosed();
    }

    public function testRevertTakesAwayAFileThePackageCreated(): void
    {

        $ruleSet = $this->creationChain();
        $this->tree->write(self::TARGET, self::FIRST);

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertTrue($outcome->changed);
        self::assertFileDoesNotExist($this->tree->path(self::TARGET));
        self::assertSame([], $this->backupNames(), 'the revert wrote a backup');

        $record = $this->records()[0];

        self::assertSame(EventRecord::REVERT, $record->kind);
        self::assertSame(EventRecord::REMOVAL, $record->operation);
        self::assertSame(self::digest(self::FIRST), $record->pre);
        self::assertSame(EventRecord::ABSENT, $record->post);
        self::assertNotNull($outcome->warning);
        $this->assertRunClosed();
    }

    public function testRedoCreatesTheFileAgainAfterThatRevert(): void
    {
        $ruleSet = $this->creationChain();
        $this->tree->write(self::TARGET, self::FIRST);

        self::assertTrue($this->reverter()->revert(self::TARGET, $ruleSet)->changed);

        $outcome = $this->reverter()->redo(self::TARGET, $ruleSet, self::environment());

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertSame(self::FIRST, $this->bytes());
        self::assertSame([], $this->backupNames(), 'the redo wrote a backup');
    }

    public function testRevertOfAnAbsentCreatedTargetRefusesCleanly(): void
    {
        // Nothing is there and nothing is meant to be: the chain starts at absence.
        // Reading that as bytes the rule set cannot name would send an operator
        // looking for a local edit that never happened.
        $ruleSet = $this->creationChain();
        \mkdir(\dirname($this->tree->path(self::TARGET)), 0755, true);

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertFalse($outcome->changed);
        self::assertStringContainsString('start of its chain', $outcome->reason);
        self::assertNull($outcome->warning);
        self::assertSame([], $this->records());
        $this->assertRunClosed();
    }

    public function testRevertRefusesAnUnrecordedLocalEditAndChangesNothing(): void
    {
        $ruleSet = $this->chain();
        $this->backupsForTheWholeChain();
        $this->tree->write(self::TARGET, self::FOREIGN);

        $before  = $this->backupNames();
        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertStringContainsString('unrecorded change', $outcome->reason);
        self::assertSame(self::FOREIGN, $this->bytes());
        self::assertSame([], $this->records());
        self::assertSame($before, $this->backupNames());
        $this->assertRunClosed();
    }

    public function testRedoRefusesToDisplaceContentNoPayloadAndNoBackupHolds(): void
    {

        $this->tree->write(self::PACKAGE . '/payload/vendor.css.v1', self::FIRST);

        $ruleSet = $this->load(RuleXml::patch(RuleXml::object(
            version: 1,
            dest: self::TARGET,
            source: 'vendor.css.v1',
            sourceHash: self::digest(self::FIRST),
            replace: true
        )));

        $this->tree->write(self::TARGET, self::FOREIGN);

        $outcome = $this->reverter()->redo(self::TARGET, $ruleSet, self::environment());

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertStringContainsString('writes no backup', $outcome->reason);
        self::assertSame(self::FOREIGN, $this->bytes());
        self::assertSame([], $this->records());
        $this->assertRunClosed();
    }

    public function testRedoStepsThroughContentAnExistingBackupHolds(): void
    {
        $ruleSet = $this->chain();
        $this->tree->write(self::TARGET, self::ORIGINAL);

        // The original is not a shipped payload. It is reproducible all the same,
        // because version 1's own apply preserved it.
        $this->backup(1, self::ORIGINAL);

        $outcome = $this->reverter()->redo(self::TARGET, $ruleSet, self::environment());

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertSame(self::FIRST, $this->bytes());
    }

    public function testNeitherStepWritesABackupOfReproducibleBytes(): void
    {
        $ruleSet = $this->chain();
        $this->backupsForTheWholeChain();
        $this->tree->write(self::TARGET, self::THIRD);

        $before = $this->backupNames();

        $this->reverter()->revert(self::TARGET, $ruleSet);
        self::assertSame($before, $this->backupNames(), 'the revert wrote a backup');
        self::assertNull($this->reverter()->revert(self::TARGET, $ruleSet)->backup);

        $this->reverter()->redo(self::TARGET, $ruleSet, self::environment());
        self::assertSame($before, $this->backupNames(), 'the redo wrote a backup');
    }

    public function testRevertStepsBackOverARemovalFromAnAbsentTarget(): void
    {
        $ruleSet = $this->removalChain();
        $this->backup(2, self::FIRST);

        // The removal took the file, not the folder it sat in. A write never
        // creates a directory, so restoring into one that is gone is a different
        // case from this one.
        \mkdir(\dirname($this->tree->path(self::TARGET)), 0755, true);

        self::assertFileDoesNotExist($this->tree->path(self::TARGET));

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertSame(self::FIRST, $this->bytes());
        self::assertSame(EventRecord::REVERT, $this->records()[0]->kind);
        self::assertSame(EventRecord::ABSENT, $this->records()[0]->pre);
        self::assertStringStartsWith('the revert created the target again', $outcome->reason);
    }

    public function testRedoStepsForwardThroughARemovalWithoutABackup(): void
    {
        $ruleSet = $this->removalChain();
        $this->tree->write(self::TARGET, self::FIRST);

        $before  = $this->backupNames();
        $outcome = $this->reverter()->redo(self::TARGET, $ruleSet, self::environment());

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertFileDoesNotExist($this->tree->path(self::TARGET));
        self::assertSame($before, $this->backupNames(), 'the redo wrote a backup');
        self::assertSame(EventRecord::REDO, $this->records()[0]->kind);
        self::assertSame(EventRecord::REMOVAL, $this->records()[0]->operation);
        self::assertSame(EventRecord::ABSENT, $this->records()[0]->post);
    }

    public function testRevertStepsBackOverANoCopyRemovalWithoutABackup(): void
    {

        $ruleSet = $this->noCopyRemovalChain();
        \mkdir(\dirname($this->tree->path(self::TARGET)), 0755, true);

        self::assertFileDoesNotExist($this->tree->path(self::TARGET));

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertTrue($outcome->changed);
        self::assertSame(self::FIRST, $this->bytes());
        self::assertSame([], $this->backupNames(), 'the revert wrote a backup');
        self::assertSame(EventRecord::REVERT, $this->records()[0]->kind);
        self::assertSame(EventRecord::ABSENT, $this->records()[0]->pre);
        $this->assertRunClosed();
    }

    public function testRevertFaultsWhenANoCopyPredecessorPayloadIsMissing(): void
    {
        $ruleSet = $this->noCopyRemovalChain();
        \mkdir(\dirname($this->tree->path(self::TARGET)), 0755, true);
        \unlink($this->paths->payload() . '/vendor.css.v1');

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertStringContainsString('names predecessor', $outcome->reason);
        self::assertStringNotContainsString('start of its chain', $outcome->reason);
        self::assertFileDoesNotExist($this->tree->path(self::TARGET));
        self::assertSame([], $this->records());
        $this->assertRunClosed();
    }

    public function testRevertFaultsWhenANoCopyPredecessorPayloadIsCorrupt(): void
    {
        $ruleSet = $this->noCopyRemovalChain();
        \mkdir(\dirname($this->tree->path(self::TARGET)), 0755, true);
        \file_put_contents($this->paths->payload() . '/vendor.css.v1', self::FOREIGN);

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertStringContainsString('names predecessor', $outcome->reason);
        self::assertStringNotContainsString('start of its chain', $outcome->reason);
        self::assertFileDoesNotExist($this->tree->path(self::TARGET));
        self::assertSame([], $this->records());
        $this->assertRunClosed();
    }

    public function testRevertLeavesAPristineTargetAloneWhenAChainCreatesThenRemovesIt(): void
    {

        $ruleSet = $this->createThenNoCopyRemovalChain();
        \mkdir(\dirname($this->tree->path(self::TARGET)), 0755, true);

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertFalse($outcome->changed);
        self::assertStringContainsString('start of its chain', $outcome->reason);
        self::assertFileDoesNotExist($this->tree->path(self::TARGET));
        self::assertNull($outcome->warning);
        self::assertSame([], $this->records());
        $this->assertRunClosed();
    }

    public function testRevertRefusesAnAbsenceMoreThanOneRemovalCouldHaveWritten(): void
    {
        // v2 removed and kept a copy; v4 removed later and kept none. The backup
        // under version 2 says version 2 ran at some point, not that it is what the
        // target is absent from now. Restoring its predecessor would report success
        // and put back bytes that never preceded this state.
        $ruleSet = $this->twoRemovalChain();
        $this->backup(2, self::FIRST);
        \mkdir(\dirname($this->tree->path(self::TARGET)), 0755, true);

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertStringContainsString('which of them left it absent', $outcome->reason);
        self::assertStringNotContainsString('start of its chain', $outcome->reason);
        self::assertFileDoesNotExist($this->tree->path(self::TARGET));
        self::assertSame([], $this->records());
        $this->assertRunClosed();
    }

    public function testRevertWalksBackAPackageOwnedChainThatShipsTheSamePayloadTwice(): void
    {
        // v1 creates FIRST; v2 ships the same bytes and expects them. Neither left
        // a backup. Selecting v2 and treating "no backup" as the chain start would
        // never reach the absence before v1.
        $ruleSet = $this->repeatedPackageOwnedChain();
        $this->tree->write(self::TARGET, self::FIRST);

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertTrue($outcome->changed);
        self::assertFileDoesNotExist($this->tree->path(self::TARGET));
        self::assertSame(EventRecord::REMOVAL, $this->records()[0]->operation);
        $this->assertRunClosed();
    }

    public function testRedoRefusesBytesThatChangedAfterTheWalkJudgedThem(): void
    {
        $ruleSet = $this->chain();
        $this->backupsForTheWholeChain();
        $this->tree->write(self::TARGET, self::ORIGINAL);

        $policy = new PathPolicy($this->tree->root(), $this->paths->root());

        $reverter = new Reverter(
            $this->paths,
            $policy,
            new ObjectWalker($policy, new Hasher(), new ConditionEvaluator(), new FreezeMarkers($this->paths)),
            function (string $point): void {
                if ($point === TargetWriter::STAGED) {

                    $this->tree->write(self::TARGET, self::FOREIGN);
                }
            }
        );

        $outcome = $reverter->redo(self::TARGET, $ruleSet, self::environment());

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertSame(self::FOREIGN, $this->bytes());
        self::assertSame([], $this->records());
        $this->assertRunClosed();
    }

    public function testAFrozenPathAcceptsNeitherRevertNorRedo(): void
    {
        $ruleSet = $this->chain();
        $this->backupsForTheWholeChain();
        $this->tree->write(self::TARGET, self::SECOND);

        $marker = $this->paths->freezeMarker(self::TARGET);
        \file_put_contents($marker, '');

        foreach (
            [
                $this->reverter()->revert(self::TARGET, $ruleSet),
                $this->reverter()->redo(self::TARGET, $ruleSet, self::environment()),
            ] as $outcome
        ) {
            self::assertTrue($outcome->isFaulted(), $outcome->reason);
            self::assertStringContainsString('is frozen', $outcome->reason);
            self::assertFalse($outcome->changed);
        }

        self::assertSame(self::SECOND, $this->bytes());
        self::assertSame([], $this->records());
        $this->assertRunClosed();
    }

    public function testARevertThatMovedTheFileCarriesTheReapplyWarning(): void
    {
        $ruleSet = $this->chain();
        $this->backupsForTheWholeChain();
        $this->tree->write(self::TARGET, self::THIRD);

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertNotNull($outcome->warning);
        self::assertStringContainsString(self::TARGET, (string) $outcome->warning);
        self::assertStringContainsString('freeze the file to keep this revert', (string) $outcome->warning);
    }

    public function testARefusedRevertCarriesNoWarning(): void
    {
        $ruleSet = $this->chain();
        $this->tree->write(self::TARGET, self::FOREIGN);

        self::assertNull($this->reverter()->revert(self::TARGET, $ruleSet)->warning);
    }

    public function testARedoCarriesNoWarning(): void
    {
        $ruleSet = $this->chain();
        $this->backupsForTheWholeChain();
        $this->tree->write(self::TARGET, self::ORIGINAL);

        $outcome = $this->reverter()->redo(self::TARGET, $ruleSet, self::environment());

        self::assertTrue($outcome->changed, $outcome->reason);
        self::assertNull($outcome->warning);
    }

    public function testAStepRefusesWhileAnotherRunHoldsTheLock(): void
    {
        $ruleSet = $this->chain();
        $this->backupsForTheWholeChain();
        $this->tree->write(self::TARGET, self::THIRD);

        $held = new RunLock($this->paths);
        self::assertTrue($held->acquire());

        try {

            $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);
        } finally {
            $held->release();
        }

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertStringContainsString(RunLock::IN_PROGRESS, $outcome->reason);
        self::assertSame(self::THIRD, $this->bytes());
        self::assertFalse((new RunMarker($this->paths))->isPresent());
    }

    public function testAStepRefusesAnInterruptedRunsLeftovers(): void
    {
        $ruleSet = $this->chain();
        $this->backupsForTheWholeChain();
        $this->tree->write(self::TARGET, self::THIRD);

        \file_put_contents($this->paths->marker(), "2026-09-07T10:00:00Z\n");

        $outcome = $this->reverter()->revert(self::TARGET, $ruleSet);

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertStringContainsString('an earlier write was interrupted', $outcome->reason);
        self::assertSame(self::THIRD, $this->bytes());
        self::assertTrue((new RunMarker($this->paths))->isPresent(), 'the stale marker was replaced');
    }

    public function testAStepPublishesTheRunMarkerUnderTheLockAndRemovesItAfterwards(): void
    {
        $ruleSet = $this->chain();
        $this->backupsForTheWholeChain();
        $this->tree->write(self::TARGET, self::THIRD);

        $seen = [];

        $reverter = new Reverter(
            $this->paths,
            new PathPolicy($this->tree->root(), $this->paths->root()),
            new ObjectWalker(
                new PathPolicy($this->tree->root(), $this->paths->root()),
                new Hasher(),
                new ConditionEvaluator(),
                new FreezeMarkers($this->paths)
            ),
            function (string $point) use (&$seen): void {
                $seen[] = $point;

                if ($point === Reverter::LOCKED) {
                    self::assertTrue(
                        (new RunMarker($this->paths))->isPresent(),
                        'the marker was not published before the step'
                    );
                    self::assertFalse(
                        (new RunLock($this->paths))->acquire(),
                        'the lock was free while the step ran'
                    );
                }
            }
        );

        $outcome = $reverter->revert(self::TARGET, $ruleSet);

        self::assertSame(TargetStatus::OK, $outcome->status, $outcome->reason);
        self::assertContains(Reverter::LOCKED, $seen);
        self::assertContains(TargetWriter::INTENT_PUBLISHED, $seen);
        $this->assertRunClosed();
    }

    public function testATargetTheRuleSetDoesNotDeclareIsRefused(): void
    {
        $ruleSet = $this->chain();

        $outcome = $this->reverter()->revert('templates/et_tennis/css/other.css', $ruleSet);

        self::assertTrue($outcome->isFaulted(), $outcome->reason);
        self::assertStringContainsString('the package has no versions of', $outcome->reason);
    }

    public function testANonCanonicalTargetPathIsRejectedBeforeTheLock(): void
    {
        $ruleSet = $this->chain();

        $this->expectException(\InvalidArgumentException::class);

        $this->reverter()->revert('../etc/passwd', $ruleSet);
    }

    private static function digest(string $bytes): string
    {
        return \hash('sha256', $bytes);
    }

    private static function environment(): EnvironmentValues
    {
        return new EnvironmentValues([
            EnvironmentValues::SITE_URL       => 'https://ecskc.eu',
            EnvironmentValues::HOST           => 'ecskc.eu',
            EnvironmentValues::JOOMLA_VERSION => '5.4.7',
            EnvironmentValues::PHP_VERSION    => '8.3.6',
        ]);
    }

    /**
     * Two objects that ship one payload, the NTS KBee topic-edit shape.
     *
     * Version 2's expected original is a different file from version 1's payload,
     * so the chain is still two steps even though the bytes written are the same.
     */
    private function repeatedPayloadChain(): RuleSet
    {
        $this->tree->write(self::PACKAGE . '/payload/vendor.css.v1', self::FIRST);
        $this->tree->write(self::PACKAGE . '/payload/vendor.css.v2', self::FIRST);

        return $this->load(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: self::TARGET,
                source: 'vendor.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            ) . "\n" . RuleXml::object(
                version: 2,
                dest: self::TARGET,
                source: 'vendor.css.v2',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::FOREIGN)
            )
        ));
    }

    /**
     * One package-owned object that creates the file where the vendor ships none.
     */
    private function creationChain(): RuleSet
    {
        $this->tree->write(self::PACKAGE . '/payload/vendor.css.v1', self::FIRST);

        return $this->load(RuleXml::patch(RuleXml::object(
            version: 1,
            dest: self::TARGET,
            source: 'vendor.css.v1',
            sourceHash: self::digest(self::FIRST),
            create: true
        )));
    }

    /**
     * Three cumulative revisions of one vendor-owned file, with their payloads.
     */
    private function chain(): RuleSet
    {
        $objects = '';

        foreach (
            [
                1 => [self::ORIGINAL, self::FIRST],
                2 => [self::FIRST, self::SECOND],
                3 => [self::SECOND, self::THIRD],
            ] as $version => [$expected, $payload]
        ) {
            $this->tree->write(self::PACKAGE . '/payload/vendor.css.v' . $version, $payload);

            $objects .= RuleXml::object(
                version: $version,
                dest: self::TARGET,
                source: 'vendor.css.v' . $version,
                sourceHash: self::digest($payload),
                expectedHash: self::digest($expected)
            ) . "\n";
        }

        return $this->load(RuleXml::patch(\rtrim($objects)));
    }

    /**
     * Two objects: version 1 writes the payload, version 2 takes the file away.
     */
    private function removalChain(): RuleSet
    {
        $this->tree->write(self::PACKAGE . '/payload/vendor.css.v1', self::FIRST);

        return $this->load(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: self::TARGET,
                source: 'vendor.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            ) . "\n" . RuleXml::object(
                version: 2,
                dest: self::TARGET,
                expectedHash: self::digest(self::FIRST)
            )
        ));
    }

    /**
     * A removal told to keep no copy of what it takes away.
     */
    private function noCopyRemovalChain(): RuleSet
    {
        $this->tree->write(self::PACKAGE . '/payload/vendor.css.v1', self::FIRST);

        return $this->load(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: self::TARGET,
                source: 'vendor.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            ) . "\n" . RuleXml::object(
                version: 2,
                dest: self::TARGET,
                expectedHash: self::digest(self::FIRST),
                backup: false
            )
        ));
    }

    /**
     * A package-owned create, then a removal that keeps no copy of it.
     *
     * Absence is both the state before version 1 and the state after version 2.
     */
    private function createThenNoCopyRemovalChain(): RuleSet
    {
        $this->tree->write(self::PACKAGE . '/payload/vendor.css.v1', self::FIRST);

        return $this->load(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: self::TARGET,
                source: 'vendor.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: true
            ) . "\n" . RuleXml::object(
                version: 2,
                dest: self::TARGET,
                expectedHash: self::digest(self::FIRST),
                backup: false
            )
        ));
    }

    /**
     * Two removals at one vendor-owned target, with a write between them.
     *
     * Version 2 kept a copy of FIRST, version 4 kept none of SECOND. Both leave the
     * target absent and nothing in the tree says which of them did.
     */
    private function twoRemovalChain(): RuleSet
    {
        $this->tree->write(self::PACKAGE . '/payload/vendor.css.v1', self::FIRST);
        $this->tree->write(self::PACKAGE . '/payload/vendor.css.v3', self::SECOND);

        return $this->load(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: self::TARGET,
                source: 'vendor.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            ) . "\n" . RuleXml::object(
                version: 2,
                dest: self::TARGET,
                expectedHash: self::digest(self::FIRST)
            ) . "\n" . RuleXml::object(
                version: 3,
                dest: self::TARGET,
                source: 'vendor.css.v3',
                sourceHash: self::digest(self::SECOND),
                create: true
            ) . "\n" . RuleXml::object(
                version: 4,
                dest: self::TARGET,
                expectedHash: self::digest(self::SECOND),
                backup: false
            )
        ));
    }

    /**
     * Two package-owned revisions that ship one payload, with no backups left.
     */
    private function repeatedPackageOwnedChain(): RuleSet
    {
        $this->tree->write(self::PACKAGE . '/payload/vendor.css.v1', self::FIRST);
        $this->tree->write(self::PACKAGE . '/payload/vendor.css.v2', self::FIRST);

        return $this->load(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: self::TARGET,
                source: 'vendor.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: true
            ) . "\n" . RuleXml::object(
                version: 2,
                dest: self::TARGET,
                source: 'vendor.css.v2',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::FIRST),
                create: true
            )
        ));
    }

    private function load(string $xml): RuleSet
    {
        $this->tree->write(self::PACKAGE . '/rules/10-chain.xml', $xml);

        $loaded = (new RuleLoader(new SchemaValidator(
            \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/schema/patch.xsd'
        )))->load($this->paths->rules());

        self::assertSame([], $loaded->violations, 'the fixture rule file has to load cleanly');

        return $loaded->ruleSet;
    }

    private function reverter(): Reverter
    {
        $policy = new PathPolicy($this->tree->root(), $this->paths->root());

        return new Reverter(
            $this->paths,
            $policy,
            new ObjectWalker(
                $policy,
                new Hasher(),
                new ConditionEvaluator(),
                new FreezeMarkers($this->paths)
            )
        );
    }

    /**
     * A backup of $bytes at $version, named the way BackupStore names one.
     */
    private function backup(int $version, string $bytes, ?int $modified = null): string
    {
        $path      = $this->paths->backupFile(self::TARGET, $version, self::digest($bytes));
        $directory = \dirname($path);

        if (!\is_dir($directory)) {
            \mkdir($directory, 0755, true);
        }

        \file_put_contents($path, $bytes);

        if ($modified !== null) {
            \touch($path, $modified);
        }

        return $path;
    }

    /** The whole chain's backups, as three successive applies would have left them. */
    private function backupsForTheWholeChain(): void
    {
        $this->backup(1, self::ORIGINAL);
        $this->backup(2, self::FIRST);
        $this->backup(3, self::SECOND);
    }

    private function bytes(): string
    {
        return $this->tree->read(self::TARGET);
    }

    /**
     * @return list<EventRecord>
     */
    private function records(): array
    {
        return (new EventLog($this->paths))->read()->records();
    }

    /**
     * Every file beside this target's backups, sorted, so a new one shows up.
     *
     * @return list<string>
     */
    private function backupNames(): array
    {
        $directory = \dirname($this->paths->backupFile(self::TARGET, 1, \str_repeat('0', 64)));

        if (!\is_dir($directory)) {
            return [];
        }

        $names = \array_values(\array_diff(\scandir($directory) ?: [], ['.', '..']));
        \sort($names);

        return $names;
    }

    /**
     * The run left nothing behind: no marker, no intent, and a free lock.
     */
    private function assertRunClosed(): void
    {
        self::assertFalse((new RunMarker($this->paths))->isPresent(), 'the run marker is still there');
        self::assertFalse(
            (new WriteIntentStore($this->paths))->isPresent(),
            'the write intent is still there'
        );

        $lock = new RunLock($this->paths);
        self::assertTrue($lock->acquire(), 'the run lock was not released');
        $lock->release();
    }
}
