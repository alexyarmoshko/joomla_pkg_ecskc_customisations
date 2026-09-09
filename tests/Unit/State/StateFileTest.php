<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunLock;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\StateFile;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

#[CoversClass(StateFile::class)]
final class StateFileTest extends TestCase
{
    private TempTree $tree;

    private PackagePaths $paths;

    protected function setUp(): void
    {
        $this->tree  = TempTree::create('ecskc-statefile-');
        $this->paths = new PackagePaths($this->tree->path('com_ecskccustom'));

        \mkdir($this->paths->state(), 0755, true);
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    /**
     * @return list<string> Everything in state/, hidden entries included.
     */
    private function stateEntries(): array
    {
        return \array_values(\array_diff(\scandir($this->paths->state()) ?: [], ['.', '..']));
    }

    private function file(): StateFile
    {
        return new StateFile($this->paths->marker());
    }

    public function testAPublishedRecordIsCompleteAndLeavesNoTemporaryBehind(): void
    {
        $file = $this->file();

        self::assertTrue($file->publish("a record\n"));
        self::assertSame("a record\n", $file->read());
        self::assertSame(
            [PackagePaths::MARKER],
            $this->stateEntries(),
            'A completed publish leaves the record and nothing else.'
        );
    }

    public function testAPublishInterruptedBeforeTheRenameLeavesThePathUntouched(): void
    {
        $file = $this->file();
        self::assertTrue($file->publish("the earlier record\n"));

        // A crash between the write and the rename is exactly this: the temporary
        // exists and the rename never happened.
        $temporary = $file->beginPublication("the replacement\n");
        self::assertIsString($temporary);

        self::assertSame(
            "the earlier record\n",
            $file->read(),
            'An unfinished publish never shows at the record path, not even truncated.'
        );
        self::assertFileExists($temporary);
    }

    public function testAnInterruptedFirstPublishLeavesNoRecordAtAll(): void
    {
        $file      = $this->file();
        $temporary = $file->beginPublication("the first record\n");

        self::assertIsString($temporary);
        self::assertFalse($file->exists(), 'A publish that did not finish published nothing.');
    }

    public function testTheNextHolderSweepsAnAbandonedTemporaryAndKeepsALiveOne(): void
    {
        // What a dead process left behind, written by the same code path it used.
        $abandoned = (new StateFile($this->paths->marker()))->beginPublication("orphan\n");
        self::assertIsString($abandoned);

        // The next process holding the run lock, part-way through its own publish.
        $file = $this->file();
        $live = $file->beginPublication("mine\n");
        self::assertIsString($live);

        self::assertSame([], $file->sweepTemporaries(), 'Every abandoned temporary is removed.');

        self::assertFileDoesNotExist($abandoned);
        self::assertFileExists($live, 'A temporary the sweeping process is writing is left alone.');

        self::assertTrue($file->completePublication($live));
        self::assertSame("mine\n", $file->read());
    }

    public function testASecondStateFileUnderTheSameRunLockPreservesAnotherObjectsLiveTemporary(): void
    {
        // The lock holder, not the object, owns a live temporary. A second
        // StateFile for the same path is still that holder, and must not treat
        // the in-flight record as abandoned. The test above uses one object for
        // both operations, so it cannot catch that mistake.
        $lock = new RunLock($this->paths);
        self::assertTrue($lock->acquire());

        $publisher = new StateFile($this->paths->marker());
        $live      = $publisher->beginPublication("mine\n");
        self::assertIsString($live);
        self::assertFileExists($live);

        $sweeper = new StateFile($this->paths->marker());
        self::assertSame([], $sweeper->sweepTemporaries());

        self::assertFileExists(
            $live,
            'A live temporary belongs to the lock holder, not to the object that began it.'
        );
        self::assertTrue($publisher->completePublication($live));
        self::assertSame("mine\n", $publisher->read());

        $lock->release();
    }

    public function testASweepIgnoresRecordsThatAreNotPublicationTemporaries(): void
    {
        \file_put_contents($this->paths->state() . '/events.log', "kept\n");
        \file_put_contents($this->paths->state() . '/run.marker.notatemp', "kept\n");
        \file_put_contents($this->paths->state() . '/write.intent.abc123abc123.tmp', "other\n");

        self::assertSame([], $this->file()->sweepTemporaries());

        $entries = $this->stateEntries();
        \sort($entries);

        self::assertSame(
            ['events.log', 'run.marker.notatemp', 'write.intent.abc123abc123.tmp'],
            $entries,
            'A sweep of one record\'s temporaries touches nothing else in state/.'
        );
    }

    public function testASweepLeavesNamesOfADifferentTokenLengthAlone(): void
    {
        // The sweep unlinks what its pattern matches, so the pattern is the whole
        // of what keeps a file this package never wrote out of a delete. Twelve hex
        // characters is the shape a publish writes; nothing else is one of ours.
        $lookalikes = [
            'run.marker.a.tmp',
            'run.marker.abc123abc12.tmp',
            'run.marker.abc123abc1234.tmp',
            'run.marker.ABC123ABC123.tmp',
        ];

        foreach ($lookalikes as $lookalike) {
            \file_put_contents($this->paths->state() . '/' . $lookalike, "kept\n");
        }

        self::assertSame([], $this->file()->sweepTemporaries());

        $entries = $this->stateEntries();
        \sort($entries);
        \sort($lookalikes);

        self::assertSame($lookalikes, $entries, 'Only a name a publish could have written is swept.');
    }

    public function testOnlyATemporaryThisObjectBeganCanBePublished(): void
    {
        $planted = $this->paths->state() . '/run.marker.deadbeef1234.tmp';
        \file_put_contents($planted, "not mine\n");

        self::assertFalse(
            $this->file()->completePublication($planted),
            'A rename is a publish, so a name this object did not write is refused.'
        );
        self::assertFileExists($planted);
        self::assertFalse($this->file()->exists());
    }

    public function testAnAbandonedPublicationRemovesItsOwnTemporary(): void
    {
        $file      = $this->file();
        $temporary = $file->beginPublication("dropped\n");

        self::assertIsString($temporary);
        $file->abandonPublication($temporary);

        self::assertFileDoesNotExist($temporary);
        self::assertFalse($file->exists());
    }

    public function testAPublishIntoAMissingDirectoryChangesNothing(): void
    {
        $file = new StateFile($this->tree->path('com_ecskccustom') . '/missing/run.marker');

        self::assertFalse($file->publish("nowhere\n"));
        self::assertFalse($file->exists());
    }

    public function testRemoveClearsTheRecordAndSucceedsWhenThereIsNone(): void
    {
        $file = $this->file();

        self::assertTrue($file->remove(), 'Removing what is not there leaves the path clear.');
        self::assertTrue($file->publish("a record\n"));
        self::assertTrue($file->remove());
        self::assertFalse($file->exists());
    }

    public function testAPresentButUnreadableRecordIsNotAnAbsentOne(): void
    {
        $file = $this->file();
        self::assertTrue($file->publish("a record\n"));
        \chmod($this->paths->marker(), 0000);

        if (\is_readable($this->paths->marker())) {
            self::markTestSkipped('This process can read a mode 0000 file, so it is likely root.');
        }

        self::assertTrue($file->exists(), 'The record is there.');
        self::assertNull($file->read(), 'And it cannot be read, which is a different answer.');

        \chmod($this->paths->marker(), 0644);
    }
}
