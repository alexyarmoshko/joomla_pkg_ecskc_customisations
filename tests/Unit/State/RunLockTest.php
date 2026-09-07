<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunLock;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;
use YakShaver\ECSKCCustomisation\Tests\Support\WriteRun;

#[CoversClass(RunLock::class)]
final class RunLockTest extends TestCase
{
    private TempTree $tree;

    private PackagePaths $paths;

    protected function setUp(): void
    {
        $this->tree  = TempTree::create('ecskc-lock-');
        $this->paths = new PackagePaths($this->tree->path('com_ecskccustom'));

        \mkdir($this->paths->state(), 0755, true);
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    public function testTheLockIsTakenAtItsExactPathUnderState(): void
    {
        $lock = new RunLock($this->paths);

        self::assertSame($this->paths->state() . '/run.lock', $lock->path());
        self::assertTrue($lock->acquire());
        self::assertFileExists($this->paths->lock());

        $lock->release();
    }

    public function testASecondRunCannotAcquireAndTheFirstKeepsTheLock(): void
    {
        $first  = new RunLock($this->paths);
        $second = new RunLock($this->paths);

        self::assertTrue($first->acquire());
        self::assertFalse($second->acquire(), 'A second run must not take a held lock.');
        self::assertSame(RunLock::IN_PROGRESS, $second->refusal());

        self::assertTrue($first->isHeld(), 'The first run keeps the lock it won.');
        self::assertFalse($second->isHeld());

        $first->release();

        self::assertTrue($second->acquire(), 'The lock is available once the winner releases it.');
        $second->release();
    }

    public function testAcquiringTwiceIsStillOneHold(): void
    {
        $lock = new RunLock($this->paths);

        self::assertTrue($lock->acquire());
        self::assertTrue($lock->acquire(), 'A holder that asks again still holds the lock.');

        $lock->release();

        self::assertFalse($lock->isHeld());

        $next = new RunLock($this->paths);
        self::assertTrue($next->acquire(), 'One release is enough after two acquires.');
        $next->release();
    }

    public function testAnUnopenableLockIsAFaultRatherThanAContendedRun(): void
    {
        $lock = new RunLock(new PackagePaths($this->tree->path('missing')));

        self::assertFalse($lock->acquire());
        self::assertNotSame(
            RunLock::IN_PROGRESS,
            $lock->refusal(),
            'A lock file that cannot be opened is a fault in the package tree, not a live run.'
        );
        self::assertStringContainsString('could not be opened', (string) $lock->refusal());
    }

    public function testReleasingLeavesTheLockFileInPlace(): void
    {
        $lock = new RunLock($this->paths);

        self::assertTrue($lock->acquire());
        $lock->release();

        self::assertFileExists(
            $this->paths->lock(),
            'The lock file is created once and never deleted: unlinking it would leave a '
            . 'holder locking a name that no longer exists.'
        );
    }

    public function testAnExistingLockFileIsNeverTruncated(): void
    {
        // Nothing writes to the lock, but a run must not destroy what it finds
        // there either: the file is only ever opened to be locked.
        \file_put_contents($this->paths->lock(), 'earlier bytes');

        $lock = new RunLock($this->paths);
        self::assertTrue($lock->acquire());
        $lock->release();

        self::assertSame('earlier bytes', \file_get_contents($this->paths->lock()));
    }

    public function testTheOperatingSystemReleasesTheLockWhenTheHolderDies(): void
    {
        $run = WriteRun::holding($this->paths->root(), 'no-marker');

        $waiting = new RunLock($this->paths);
        self::assertFalse($waiting->acquire(), 'A live run holds the lock.');
        self::assertSame(RunLock::IN_PROGRESS, $waiting->refusal());

        $run->kill();

        self::assertTrue(
            $waiting->acquire(),
            'A lock is released by the operating system when its holder dies, which is what '
            . 'makes a stale marker readable as a crash.'
        );

        $waiting->release();
    }
}
