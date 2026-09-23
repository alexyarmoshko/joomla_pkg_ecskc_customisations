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
