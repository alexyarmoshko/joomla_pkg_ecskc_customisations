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

        $intentLeft = $intent->isPresent() || !$intent->isDetermined();

        if (!$markerLeft && !$intentLeft) {
            return RunState::clean($startedAt);
        }

        return RunState::interrupted($startedAt, $intentLeft, $faults);
    }
}
