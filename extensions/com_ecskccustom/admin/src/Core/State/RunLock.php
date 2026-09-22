<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\State;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;

/**
 * The one advisory lock a write run holds, at `state/run.lock`.
 *
 * Apply, revert, redo, freeze and unfreeze are all write runs and all take this
 * lock, decision 30 of the execution plan. Freeze has to take the same lock as
 * apply, or apply can pass the freeze guard, freeze can return, and apply can still
 * move the target.
 *
 * **The lock file is created once and never deleted.** Unlinking a file another
 * process holds open leaves that process holding a lock on a name that no longer
 * exists while the next run creates and locks a different file, with mutual
 * exclusion silently gone. Brief 5.6 fixes that here rather than leaving it to be
 * discovered. Nothing in this class removes the file, and no caller may.
 *
 * The lock is advisory, non-blocking and held for the whole run. Waiting is never
 * right for it: the uninstall archive must not stall an uninstall on a live run,
 * and a second write run has nothing useful to do behind a first one.
 *
 * `acquire()` is the write lock. `observe()` is the read a report takes instead: a
 * shared lock on a file it will not create, so a check or a dry run can tell a live
 * run from an interrupted one without acquiring anything a write run wants and
 * without leaving a file behind in a tree it only read.
 *
 * Its one other property is what makes brief 5.6's three states distinguishable:
 * the operating system releases an advisory lock when the holding process dies,
 * however it dies, while an ordinary file persists. That is why the lock and the
 * marker are two artefacts. Verified on PHP 8.3, and the design assumes a local
 * filesystem, which is the case here.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class RunLock
{
    public const IN_PROGRESS = 'another write run is in progress';

    private const UNAVAILABLE = 'the run lock at %s could not be opened';

    /** @var resource|null */
    private $handle = null;

    private ?string $refusal = null;

    public function __construct(private readonly PackagePaths $paths)
    {
    }

    public function path(): string
    {
        return $this->paths->lock();
    }

    /**
     * Take the lock, or report that this process does not have it.
     *
     * Acquiring twice is the same as acquiring once: a caller that already holds the
     * lock still holds it, and re-locking the same handle would not tell it apart
     * from a second run anyway.
     *
     * A caller that gets false has not acquired the lock and must not create,
     * replace or remove the run marker, publish or remove a write intent, or sweep
     * publication temporaries. refusal() says which of the two reasons applied.
     */
    public function acquire(): bool
    {
        if ($this->handle !== null) {
            return true;
        }

        // 'c+' creates the file without truncating an existing one. The lock's
        // content is never read or written; the file exists to be locked.
        $handle = @\fopen($this->path(), 'c+');

        if ($handle === false) {
            $this->refusal = \sprintf(self::UNAVAILABLE, $this->path());

            return false;
        }

        if (!\flock($handle, \LOCK_EX | \LOCK_NB)) {
            \fclose($handle);
            $this->refusal = self::IN_PROGRESS;

            return false;
        }

        $this->handle  = $handle;
        $this->refusal = null;

        return true;
    }

    /**
     * Observe the lock for a report, without taking it and without creating the file.
     *
     * A check and a dry run have to tell a live write run from an interrupted one,
     * and that needs the lock state and the records read at one point in time. They
     * must not take the write lock to get it: ticket 34 says a dry run does not
     * acquire it, and `acquire()` opens with `c+`, so asking would also create the
     * lock file in a tree the run was asked only to describe.
     *
     * A shared lock answers the same question without either. It is refused exactly
     * when a write run holds the exclusive lock, and while it is held no write run
     * can begin, so the records read under it belong to one moment. A lock file that
     * is not there at all is its own answer: a write run creates it before it
     * publishes anything, so nothing can be outstanding behind one that was never
     * made. That case takes no handle, and `isHeld()` says so - the caller has read a
     * tree nothing was holding rather than a tree it locked.
     *
     * A lock file that exists and will not open is a tree the package cannot see
     * into, and is never reported as a clean one.
     */
    public function observe(): bool
    {
        if ($this->handle !== null) {
            return true;
        }

        // 'r' never creates.
        $handle = @\fopen($this->path(), 'r');

        if ($handle === false) {
            \clearstatcache(true, $this->path());

            // A missing state folder is a tree that cannot be read, not an unlocked
            // one, so the absent lock file only answers where the folder is there.
            if (!\file_exists($this->path()) && \is_dir(\dirname($this->path()))) {
                $this->refusal = null;

                return true;
            }

            $this->refusal = \sprintf(self::UNAVAILABLE, $this->path());

            return false;
        }

        if (!\flock($handle, \LOCK_SH | \LOCK_NB)) {
            \fclose($handle);
            $this->refusal = self::IN_PROGRESS;

            return false;
        }

        $this->handle  = $handle;
        $this->refusal = null;

        return true;
    }

    /**
     * Why the last acquire() or observe() failed, or null when it succeeded.
     *
     * Two reasons, and they send an operator somewhere different: a lock held by
     * another run is the design working, and a lock file that cannot be opened is a
     * fault in the package's own tree.
     */
    public function refusal(): ?string
    {
        return $this->refusal;
    }

    /**
     * Does this process hold a lock through this object, exclusive or shared?
     */
    public function isHeld(): bool
    {
        return $this->handle !== null;
    }

    /**
     * Release the lock, leaving the lock file in place.
     *
     * Closing the handle would release it too. Unlocking first says so in the code,
     * and costs nothing.
     */
    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        \flock($this->handle, \LOCK_UN);
        \fclose($this->handle);
        $this->handle = null;
    }
}
