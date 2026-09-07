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
 * Reads the run artefacts and says which of brief 5.6's three states holds.
 *
 * The order matters and is the whole of the logic: **the lock is taken first**. A
 * process that cannot acquire the lock does not inspect the marker to decide,
 * decision 34, because a live run legitimately has no marker for the two windows
 * between acquiring the lock and publishing it, and between removing it and
 * releasing the lock. Reading the marker there would report a live apply as a clean
 * tree.
 *
 * **The lock is held across both reads, not merely asked and dropped.** A run that
 * began in the gap between an answer of "free" and the read of the records would
 * put this reader in exactly the window it refuses to read in, and it would report
 * a live run as clean, or as interrupted once that run published its marker. The
 * three states are only distinguishable when the lock and the records are read at
 * one point in time, so this reader owns the lock for that point and releases it
 * whatever happens. The cost is that a write run starting in those few microseconds
 * is refused and says so, which is the safe direction: it is retried, whereas a
 * misread tree is acted on.
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

    public function __construct(PackagePaths $paths)
    {
        $this->lock    = new RunLock($paths);
        $this->marker  = new RunMarker($paths);
        $this->intents = new WriteIntentStore($paths);
    }

    public function read(): RunState
    {
        if (!$this->lock->acquire()) {
            $refusal = $this->lock->refusal();

            // A lock held by a run is the answer, and carries no fault. Any other
            // refusal is a lock file this package cannot open: still not clean, and
            // now with a fault saying the package could not see for itself.
            return RunState::live($refusal === RunLock::IN_PROGRESS ? null : $refusal);
        }

        try {
            return $this->readRecords();
        } finally {
            $this->lock->release();
        }
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
