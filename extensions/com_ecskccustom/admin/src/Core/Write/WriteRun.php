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
 * The lock, run marker, interrupted-run refusal, and marker cleanup a write run
 * shares, decision 30.
 *
 * Freezer and Reverter each inspect their own path, then hand the operation to
 * this class. Ticket 34's recovery has to teach both the same settlement, so the
 * refusal that currently stops an interrupted run lives here rather than in two
 * copies that would drift.
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
     * @param string $untouched What a refused acquire, interruption, or marker
     *                          publish left unchanged, for the outcome sentence.
     */
    public function __construct(
        PackagePaths $paths,
        private readonly string $untouched,
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

        if (!$this->lock->acquire()) {
            return WriteOutcome::refused(\sprintf(
                'the run lock was not acquired (%s), so %s',
                (string) $this->lock->refusal(),
                $this->untouched
            ));
        }

        try {
            $reason = $this->interruption();

            if ($reason !== null) {
                return WriteOutcome::refused($reason);
            }

            if (!$this->marker->publish()) {
                return WriteOutcome::refused(\sprintf(
                    'the run marker at %s could not be published, so %s',
                    $this->marker->path(),
                    $this->untouched
                ));
            }

            $outcome = $operation();

            // An intent left for recovery means this run did not complete, and
            // decision 35 keeps the marker beside it.
            if ($outcome->intentLeft || $this->marker->remove()) {
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
     * Why an earlier run's leftovers stop this one, or null when there are none.
     *
     * Asked with the lock held, so a present intent or run marker is not a live
     * run's. Settling them is ticket 34's recovery.
     */
    private function interruption(): ?string
    {
        if ($this->intents->isPresent()) {
            return \sprintf(
                'the write intent at %s was left by an interrupted run, and recovery has to settle it '
                . 'first, so %s',
                $this->intents->path(),
                $this->untouched
            );
        }

        if ($this->marker->isPresent()) {
            return \sprintf(
                'the run marker at %s was left by an interrupted run, and recovery has to settle it '
                . 'first, so %s',
                $this->marker->path(),
                $this->untouched
            );
        }

        return null;
    }
}
