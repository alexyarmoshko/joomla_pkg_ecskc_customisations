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

use Closure;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;

/**
 * Reads the run artefacts and says which of brief 5.6's three states holds.
 *
 * The order matters and is the whole of the logic: **the lock is observed first**. A
 * process that finds the lock held does not inspect the marker to decide, decision
 * 34, because a live run legitimately has no marker for the two windows between
 * acquiring the lock and publishing it, and between removing it and releasing the
 * lock. Reading the marker there would report a live apply as a clean tree.
 *
 * **The lock is held across both reads, not merely asked and dropped.** A run that
 * began in the gap between an answer of "free" and the read of the records would
 * put this reader in exactly the window it refuses to read in, and it would report
 * a live run as clean, or as interrupted once that run published its marker. The
 * three states are only distinguishable when the lock and the records are read at
 * one point in time, so this reader owns that point and releases it whatever
 * happens. The cost is that a write run starting in those few microseconds is
 * refused and says so, which is the safe direction: it is retried, whereas a misread
 * tree is acted on.
 *
 * **What it holds is a shared lock, and it creates nothing.** This is the read a
 * check and a dry run make, and ticket 34 says a dry run does not acquire the write
 * lock; `RunLock::acquire()` would also create `run.lock` in a tree that has never
 * been written to. `RunLock::observe()` takes a shared lock instead, which a live
 * run's exclusive lock still excludes, and it never makes the file. Where the file
 * does not exist there is nothing to hold and nothing can be outstanding, because a
 * run creates the lock before it publishes anything - so a record found that way
 * belongs to a run that has just begun, and the read is taken again against the lock
 * file that run made.
 *
 * **An unheld read is only finished once the lock file is still absent.** The records
 * of a tree that has no lock file are read while nothing excludes a first write run,
 * so that run can create the lock, take it, and mutate before this reader returns.
 * Asking for the file again after the records closes that window rather than
 * narrowing it: a lock file that is still not there means no run had begun by then,
 * which makes the records already read the truth of a moment nothing was writing in.
 * One that has appeared sends the read round again, under the lock this time, and
 * the answer is a live run or whatever that run left.
 *
 * With the lock held, the marker and the intent are records of a run that is over.
 * A marker means it did not finish. An intent means one mutation may have happened
 * with its event unrecorded. A record that is present and cannot be read is a fault
 * that comes with the interruption rather than instead of it: it is a claim nobody
 * can read, which is not the same as no claim.
 *
 * This reader is for a process deciding what it has walked into. A process that
 * holds the lock is that run, and asks the marker and the intent store directly:
 * anything it finds after acquiring, and before publishing its own, was left by the
 * previous run. Ticket 34 sequences that recovery.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class RunStateReader
{
    private readonly RunLock $lock;

    private readonly RunMarker $marker;

    private readonly WriteIntentStore $intents;

    /**
     * @param Closure(): void|null $checkpoint A test seam, fired once the records
     *                                          have been read by a pass that held
     *                                          nothing. It is the window a first
     *                                          write run starts in, and the only
     *                                          way to put a run there on purpose.
     */
    public function __construct(PackagePaths $paths, private readonly ?Closure $checkpoint = null)
    {
        $this->lock    = new RunLock($paths);
        $this->marker  = new RunMarker($paths);
        $this->intents = new WriteIntentStore($paths);
    }

    public function read(): RunState
    {
        $held  = false;
        $state = $this->readUnderLock($held);

        if ($state === null) {
            return $this->refused();
        }

        // Nothing was held, so the lock file was not there when it was asked for.
        // Two things can follow from that. A record found all the same belongs to a
        // run that started in between, and that run made the lock file. So does a
        // lock file that is there now and was not before the records were read: the
        // records belong to the moment before it, but a run may have created it,
        // taken it and mutated since. Either way the question is asked once more,
        // this time against a file that exists and can be held.
        if (!$held && (!$state->isClean() || $this->lockAppeared())) {
            return $this->readUnderLock($held) ?? $this->refused();
        }

        return $state;
    }

    /**
     * Is there a lock file now, where the read that just finished found none?
     */
    private function lockAppeared(): bool
    {
        $path = $this->lock->path();
        \clearstatcache(true, $path);

        return \file_exists($path) || \is_link($path);
    }

    /**
     * One read at one point in time, or null when this reader could not take it.
     *
     * @param bool $held Set to whether a shared lock was held across the read. False
     *                   means there was no lock file to hold, which is its own answer
     *                   only while the records agree that nothing has run.
     */
    private function readUnderLock(bool &$held): ?RunState
    {
        if (!$this->lock->observe()) {
            $held = false;

            return null;
        }

        $held = $this->lock->isHeld();

        try {
            $records = $this->readRecords();
        } finally {
            $this->lock->release();
        }

        if (!$held && $this->checkpoint !== null) {
            ($this->checkpoint)();
        }

        return $records;
    }

    /**
     * A lock this reader could not observe: held by a run, or a tree it cannot read.
     *
     * A lock held by a run is the answer, and carries no fault. Any other refusal is
     * a lock file this package cannot open: still not clean, and now with a fault
     * saying the package could not see for itself.
     */
    private function refused(): RunState
    {
        $refusal = $this->lock->refusal();

        return RunState::live($refusal === RunLock::IN_PROGRESS ? null : $refusal);
    }

    /**
     * The two records, read while this process holds the run lock.
     */
    private function readRecords(): RunState
    {
        $startedAt = $this->marker->read();
        $intent    = $this->intents->read();
        $faults    = [];

        if (!$startedAt->isDetermined()) {
            $faults[] = (string) $startedAt->reason;
        }

        if (!$intent->isDetermined()) {
            $faults[] = (string) $intent->reason;
        }

        // A present marker is the interruption. An unreadable one is the same claim
        // with its content lost, so it stays an interruption and carries a fault.
        $markerLeft = $startedAt->isKnown() || !$startedAt->isDetermined();

        // A leftover intent with no marker should not arise - a run publishes the
        // marker first - but it is still evidence that a mutation may have happened
        // and its event may not have been recorded, and that is not a clean tree.
        $intentLeft = $intent->isPresent() || !$intent->isDetermined();

        if (!$markerLeft && !$intentLeft) {
            return RunState::clean($startedAt);
        }

        return RunState::interrupted($startedAt, $intentLeft, $faults);
    }
}
