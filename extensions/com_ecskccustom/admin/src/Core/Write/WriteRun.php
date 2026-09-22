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

use Closure;
use InvalidArgumentException;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunLock;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunMarker;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntentStore;

/**
 * The lock, run marker, interrupted-run recovery, and marker cleanup a write run
 * shares, decisions 30 and 35.
 *
 * Freezer and Reverter each inspect their own path, then hand the operation to
 * this class, and the runner's apply goes through it too. The settlement an
 * interrupted run needs is therefore written once rather than in three copies that
 * would drift.
 *
 * ## An interrupted run is settled, not refused
 *
 * Work item 7 and brief decision 16: the package resumes through the ordinary
 * process. So a leftover write intent or a stale run marker found under the lock is
 * handed to `RunRecovery`, and the operation goes ahead once that has settled. A
 * freeze, unfreeze, revert or redo settles and then performs its own operation; it
 * does not take over the dead run's remaining target writes, which only apply does.
 *
 * Recovery that could **not** settle refuses the operation. An unsettled intent is a
 * mutation whose event may still be missing, and publishing a new intent over that
 * record would destroy the only evidence of it.
 *
 * A run constructed without a recovery refuses instead, which is what every caller
 * did before ticket 34. It is the safe half of the same rule: refusing changes
 * nothing, where proceeding over an unsettled intent cannot be undone.
 *
 * ## The marker
 *
 * It is published by atomic rename, so a retained stale marker is replaced rather
 * than unlinked and re-created: an unlink followed by a mutation leaves a window in
 * which a death reads as a clean history. That replacement is what decision 35's
 * retained neither-state marker amounts to here: the marker is present for the whole
 * of this run, so a death before it finishes is still an interruption.
 *
 * It is removed only when the run completes, and a run does not complete while an
 * intent is left for recovery, while its own operation has left a staged file it
 * could not remove, or while recovery has left an abandoned staging file or
 * publication temporary behind.
 *
 * The caller still fires its own checkpoint after the lock and marker, because
 * Freezer and Reverter name that point on their own closures.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class WriteRun
{
    private readonly RunLock $lock;

    private readonly RunMarker $marker;

    private readonly WriteIntentStore $intents;

    /**
     * @param string           $untouched What a refused acquire, an unsettled
     *                                    interruption, or a marker publish left
     *                                    unchanged, for the outcome sentence.
     * @param RunRecovery|null $recovery  Settles what an interrupted run left. A run
     *                                    given none refuses an interrupted tree
     *                                    instead of settling it.
     */
    public function __construct(
        PackagePaths $paths,
        private readonly string $untouched,
        private readonly ?RunRecovery $recovery = null,
    ) {
        $this->lock    = new RunLock($paths);
        $this->marker  = new RunMarker($paths);
        $this->intents = new WriteIntentStore($paths);
    }

    /**
     * Take the lock, publish the run marker, do the operation, and close the run.
     *
     * @param Closure(): WriteOutcome $operation
     */
    public function execute(string $targetPath, Closure $operation): WriteOutcome
    {
        if (!PackagePaths::isCanonicalTargetPath($targetPath)) {
            throw new InvalidArgumentException(
                \sprintf('%s is not a canonical root-relative target path', $targetPath)
            );
        }

        return $this->run($operation);
    }

    /**
     * The same run, for an operation that is not about one target path.
     *
     * An apply walks the whole set, so there is no single path to validate and no
     * single outcome to return: the closure collects its own per-target results and
     * answers with a summary. Everything else - the lock, recovery, the marker and
     * when it is removed - is the same, which is why it is not written twice.
     *
     * @param Closure(): WriteOutcome $operation
     */
    public function run(Closure $operation): WriteOutcome
    {
        // Drop the previous call's recovery before this attempt. A lock we do not
        // acquire never reaches settle(), and lastOutcome() would otherwise still
        // name the interruption this runner already cleared.
        $this->recovery?->forget();

        if (!$this->lock->acquire()) {
            return WriteOutcome::refused(\sprintf(
                'the run lock was not acquired (%s), so %s',
                (string) $this->lock->refusal(),
                $this->untouched
            ));
        }

        try {
            $recovered = $this->recovery?->settle();

            if ($recovered !== null && !$recovered->settled) {
                return WriteOutcome::refused(\sprintf(
                    '%s (%s), so %s',
                    $recovered->reason,
                    \implode('; ', $recovered->faults),
                    $this->untouched
                ));
            }

            if ($recovered === null) {
                $reason = $this->interruption();

                if ($reason !== null) {
                    return WriteOutcome::refused($reason);
                }
            }

            if (!$this->marker->publish()) {
                return WriteOutcome::refused(\sprintf(
                    'the run marker at %s could not be published, so %s',
                    $this->marker->path(),
                    $this->untouched
                ));
            }

            $outcome = $operation();

            // The marker goes only when the run completed. An intent left for
            // recovery means it did not; neither did a staged file this run's own
            // operation could not remove, nor a recovery that could not sweep an
            // abandoned staging file or publication temporary. Ticket 34 counts an
            // empty `staging/` as part of a completed run whether the file was left
            // by the dead run recovery swept for or by this run's own operation.
            // Either way the marker is the only thing that says a later run has to
            // look again.
            if ($outcome->intentLeft
                || $outcome->leftover !== null
                || ($recovered?->incomplete ?? false)
                || $this->marker->remove()
            ) {
                return $outcome;
            }

            $reason = \sprintf(
                '%s. The run marker at %s could not be removed',
                $outcome->reason,
                $this->marker->path()
            );

            return $outcome->correlationId === null
                ? WriteOutcome::refused($reason)
                : WriteOutcome::faulted($outcome->correlationId, $outcome->changed, false, $reason, null);
        } finally {
            $this->lock->release();
        }
    }

    /**
     * Why an earlier run's leftovers stop a run that was given no recovery.
     *
     * Asked with the lock held, so a present intent or run marker is not a live
     * run's. A run with a recovery settles them instead of reaching this.
     */
    private function interruption(): ?string
    {
        if ($this->intents->isPresent()) {
            return \sprintf(
                'the write intent at %s was left by an interrupted run, and this run has no '
                . 'recovery to settle it, so %s',
                $this->intents->path(),
                $this->untouched
            );
        }

        if ($this->marker->isPresent()) {
            return \sprintf(
                'the run marker at %s was left by an interrupted run, and this run has no '
                . 'recovery to settle it, so %s',
                $this->marker->path(),
                $this->untouched
            );
        }

        return null;
    }
}
