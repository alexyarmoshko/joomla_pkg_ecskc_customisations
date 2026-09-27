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
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\ReportedValue;

/**
 * The write history a report carries, read from `state/events.log` and the run marker.
 */
final class EventLogHistory implements WriteHistoryInterface
{
    private const NO_WRITE = 'the package has no record of changing %s';

    private const UNKNOWN_WRITE = 'whether the package has written at %s is unknown: %s';

    private const INTENT_WITHOUT_MARKER = 'an earlier write stopped before finishing its record, so '
        . 'a file may have changed without being logged';

    private readonly EventLog $log;

    private readonly RunStateReader $runState;

    private ?EventLogRead $read = null;

    private ?RunState $runPhase = null;

    public function __construct(PackagePaths $paths)
    {
        $this->log      = new EventLog($paths);
        $this->runState = new RunStateReader($paths);
    }

    public function lastWriteFor(string $targetPath): ReportedValue
    {
        $read = $this->read();

        if (!$read->isDetermined()) {
            return ReportedValue::unavailable(
                \sprintf(self::UNKNOWN_WRITE, $targetPath, (string) $read->reason)
            );
        }

        $write = $read->lastWriteAt($targetPath);

        return $write === null
            ? ReportedValue::none(\sprintf(self::NO_WRITE, $targetPath))
            : ReportedValue::known($write->describe());
    }

    /**
     * @return list<string>
     */
    public function recordFaults(): array
    {
        $read = $this->read();

        if (!$read->isDetermined()) {
            return [(string) $read->reason];
        }

        return $read->recordFaults();
    }

    /**
     * What the lock and the marker say an earlier run left behind.
     *
     * The one case the marker cannot speak for is a leftover intent with no marker.
     * It should not arise, because a run publishes the marker first, but a mutation
     * may have happened there with its event unrecorded, and that is not a clean run.
     */
    public function interruption(): ReportedValue
    {
        // Read once, like the log, and for a second reason beside consistency: each
        // read observes the run lock, and a caller asking twice would contend with
        // write runs twice for one answer.
        $state = $this->runPhase ??= $this->runState->read();

        if ($state->wasInterrupted()
            && $state->hasLeftoverIntent()
            && $state->startedAt->isDetermined()
            && !$state->startedAt->isKnown()) {
            return ReportedValue::known(self::INTENT_WITHOUT_MARKER);
        }

        return $state->startedAt;
    }

    /**
     * Drop the cached log read and run state, after this process has written.
     */
    public function refresh(): void
    {
        $this->read     = null;
        $this->runPhase = null;
    }

    private function read(): EventLogRead
    {
        return $this->read ??= $this->log->read();
    }
}
