<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write;

/**
 * What recovery found an interrupted run had left, and what it did about it.
 *
 * Three facts, and each sends the run that called it somewhere different.
 *
 * `interrupted` is whether there was anything to settle at all. It is what the
 * report tells the operator: a run that silently cleaned up after a crash and said
 * nothing would hide the crash.
 *
 * `settled` says the intent is no longer outstanding. Only then may a run publish an
 * intent of its own, because publishing over the old record would destroy the only
 * evidence of the mutation it describes.
 *
 * `incomplete` says recovery did not finish its own work, so the run that called it
 * cannot claim to have completed either and must leave the run marker where it is.
 * Two things produce it: an intent that could not be settled, and a sweep that left
 * an abandoned staging file or publication temporary behind. Ticket 34 makes an
 * empty `staging/` part of what a completed recovery means, so a run that leaves one
 * there has to stay reported as an interruption until a later run clears it.
 *
 * Decision 35's retained marker is not this. An unresolved neither-state settles its
 * intent and the run goes on to publish its own marker over the stale one by atomic
 * rename, so a death before the run completes is still an interruption; a run that
 * does complete removes that marker like any other.
 *
 * `faults` are the reasons, for the report. They are not appended to the event log:
 * decision 39 keeps event-log I/O failures on the current report, and a fault about
 * a log that will not accept a record cannot be recorded in that log.
 */
final class RecoveryOutcome
{
    /**
     * @param list<string> $faults
     */
    private function __construct(
        public readonly bool $interrupted,
        public readonly bool $settled,
        public readonly bool $incomplete,
        public readonly string $reason,
        public readonly array $faults,
    ) {
    }

    /**
     * Nothing was outstanding. Whatever sweeping happened left no trace worth naming.
     */
    public static function clean(string $reason): self
    {
        return new self(false, true, false, $reason, []);
    }

    /**
     * An interrupted run was found and settled.
     *
     * @param list<string> $faults
     */
    public static function settled(string $reason, array $faults = []): self
    {
        return new self(true, true, false, $reason, $faults);
    }

    /**
     * An interrupted run was found and could not be settled.
     *
     * The marker stays by construction: an unsettled intent is a mutation whose
     * event may still be missing, and the next run has to be told to look again.
     *
     * @param list<string> $faults
     */
    public static function unsettled(string $reason, array $faults): self
    {
        return new self(true, false, true, $reason, $faults);
    }

    /**
     * One more reason on the same outcome, for a sweep that could not finish.
     *
     * A sweep that left something behind makes the recovery incomplete, whatever it
     * managed to settle: the abandoned file is still there, and a run that removed
     * its marker over it would report the tree as clean history.
     *
     * @param list<string> $faults
     */
    public function withFaults(array $faults): self
    {
        if ($faults === []) {
            return $this;
        }

        return new self(
            $this->interrupted,
            $this->settled,
            true,
            $this->reason,
            [...$this->faults, ...$faults]
        );
    }

    /**
     * One more reason that does not, by itself, keep the run marker.
     *
     * A sweep that left a file behind is incomplete: the next run has to look
     * again. A present marker that could not be parsed is not. The run may
     * replace that record and still complete, and the report has to keep the
     * reason after the bytes are gone.
     *
     * @param list<string> $faults
     */
    public function withNonBlockingFaults(array $faults): self
    {
        if ($faults === []) {
            return $this;
        }

        return new self(
            $this->interrupted,
            $this->settled,
            $this->incomplete,
            $this->reason,
            [...$this->faults, ...$faults]
        );
    }
}
