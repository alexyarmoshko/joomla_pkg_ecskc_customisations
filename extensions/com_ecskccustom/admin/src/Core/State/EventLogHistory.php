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
 *
 * It answers the three questions the walk cannot, and each from the record that owns
 * it. The last write at a target comes from the log. Whether an earlier run was
 * interrupted comes from the lock and the marker, through ticket 28's reader. Damaged
 * lines come from the same read of the log as the last write, so one run's report
 * shows both rather than one of them hiding the other.
 *
 * ## One read per object
 *
 * The log and the run artefacts are each read once, at the first question asked of
 * them, and the answers are kept. A report asks about every managed target, and
 * re-reading a file that is append-only for each of them would give later targets a
 * different history from earlier ones within one report. A run that appends while it
 * works therefore builds its report from a history object it has not yet asked
 * anything of. That is how a write runner uses it, since the mutation records a run
 * writes go in before the report is composed.
 *
 * ## What is a write here
 *
 * `lastWriteFor()` is the last record that changed the target's own bytes: a write, a
 * removal, a revert or a redo. Checks and dry runs are not durable history. A freeze
 * is durable but mutates the marker beside the target's backups, not the target.
 * Attribution travels on the record, so a write recovery observed rather than
 * performed is still the last write, and still says which it was.
 *
 * ## A log that cannot be read
 *
 * Then whether the package wrote at a path is unknown, and `lastWriteFor()` says so
 * rather than reporting a clean history. The reason is also a record fault, so the
 * report carries it where an operator will see it. Decision 33 keeps that fault on the
 * current report: it is not appended, because the log is what could not be used.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class EventLogHistory implements WriteHistoryInterface
{
    private const NO_WRITE = 'no package write is recorded at %s';

    private const UNKNOWN_WRITE = 'whether the package has written at %s is unknown: %s';

    private const INTENT_WITHOUT_MARKER = 'an earlier write run left a write intent behind and '
        . 'no run marker, so a mutation may have happened with its event unrecorded';

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
     * The marker's own answer is the field's answer in all three of ticket 28's
     * states, and that is not a coincidence: the marker records that a run began, so
     * its timestamp is the interruption, its absence is the determined negative, and
     * a marker present but unreadable is the fault. This reads them; the write path
     * publishes and removes them.
     *
     * The one case the marker cannot speak for is a leftover intent with no marker.
     * It should not arise, because a run publishes the marker first, but a mutation
     * may have happened there with its event unrecorded, and that is not a clean run.
     */
    public function interruption(): ReportedValue
    {
        // Read once, like the log, and for a second reason beside consistency: each
        // read acquires and releases the run lock, and a caller asking twice would
        // contend with write runs twice for one answer.
        $state = $this->runPhase ??= $this->runState->read();

        if ($state->wasInterrupted()
            && $state->hasLeftoverIntent()
            && $state->startedAt->isDetermined()
            && !$state->startedAt->isKnown()) {
            return ReportedValue::known(self::INTENT_WITHOUT_MARKER);
        }

        return $state->startedAt;
    }

    private function read(): EventLogRead
    {
        return $this->read ??= $this->log->read();
    }
}
