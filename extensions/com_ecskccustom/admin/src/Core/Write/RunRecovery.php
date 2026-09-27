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

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\Hasher;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path\PathPolicy;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventLog;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventRecord;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\FreezeMarkers;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunMarker;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntent;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntentStore;

/**
 * The intent holds one correlation id and the complete event payload the dead run
 * would have appended. Recovery matches on that id and on nothing else: target,
 * operation and the two states cannot tell a leftover intent from an older identical
 * transition at the same target, which is exactly the case a rule set with a
 * repeated payload produces.
 */
final class RunRecovery
{
    private const STATE_HASH = 'sha256';

    private const NOTHING = 'no earlier run left a marker or a write intent';

    private readonly RunMarker $marker;

    private readonly WriteIntentStore $intents;

    private readonly EventLog $log;

    private readonly FreezeMarkers $freezes;

    private readonly Hasher $hasher;

    private ?RecoveryOutcome $last = null;

    public function __construct(
        private readonly PackagePaths $paths,
        private readonly PathPolicy $policy,
    ) {
        $this->marker  = new RunMarker($paths);
        $this->intents = new WriteIntentStore($paths);
        $this->log     = new EventLog($paths);
        $this->freezes = new FreezeMarkers($paths);
        $this->hasher  = new Hasher();
    }

    /**
     * Drop what a previous settle() found, before this write-run attempt.
     *
     * lastOutcome() is process state. A second call on the same runner that never
     * reaches settle(), because it could not acquire the lock, must not report the
     * first call's recovery as its own interruption.
     */
    public function forget(): void
    {
        $this->last = null;
    }

    /**
     * Settle what an interrupted run left behind, with the run lock held.
     */
    public function settle(): RecoveryOutcome
    {
        $interrupted = $this->intents->isPresent() || $this->marker->isPresent();
        $outcome     = $interrupted
            ? $this->settleIntent()
            : RecoveryOutcome::clean(self::NOTHING);

        // A present marker that does not parse is a fault on this report. It is
        // not a reason to keep the marker: withNonBlockingFaults() leaves that to
        // a sweep that could not finish, and a completed run may replace the bad
        // record.
        return $this->last = $outcome
            ->withNonBlockingFaults($this->markerFault())
            ->withFaults([...$this->sweepStaging(), ...$this->sweepStateTemporaries()]);
    }

    /**
     * What the last settle() in this process found, for the report.
     *
     * Cleared at the start of each write-run attempt. The run that calls settle()
     * is inside `WriteRun`, and the runner that has to put the interruption on its
     * report is two calls above it. Passing the outcome back up through
     * `WriteOutcome`, `Freezer` and `Reverter` would put a field about recovery on
     * three classes that have nothing else to do with it. A call that never reached
     * settle() leaves this empty, and the report reads the live run state instead.
     */
    public function lastOutcome(): ?RecoveryOutcome
    {
        return $this->last;
    }

    /**
     * A present run marker whose timestamp could not be read.
     *
     * Absence is not a fault. A marker that is there and does not parse is,
     * because the package is about to replace the only copy of that claim.
     *
     * @return list<string>
     */
    private function markerFault(): array
    {
        if (!$this->marker->isPresent()) {
            return [];
        }

        $read = $this->marker->read();

        return $read->isKnown() ? [] : [$read->describe()];
    }

    private function settleIntent(): RecoveryOutcome
    {
        $read = $this->intents->read();

        if (!$read->isDetermined()) {
            return RecoveryOutcome::unsettled(
                'an interrupted run left a write intent that could not be read',
                [(string) $read->reason]
            );
        }

        if (!$read->isPresent()) {
            return RecoveryOutcome::settled(\sprintf(
                'an earlier run left %s behind, but no unfinished write needs recovery',
                $this->marker->path()
            ));
        }

        $intent = $read->intent;
        $record = EventRecord::fromPayload($intent->event);

        if ($record === null || $record->target === null || $record->operation === null
            || $record->pre === null || $record->post === null) {
            return RecoveryOutcome::unsettled(
                'an interrupted run left a write intent whose event payload is not a record',
                [\sprintf(
                    'the write intent at %s carries no readable event, so the mutation it '
                    . 'describes cannot be settled',
                    $this->intents->path()
                )]
            );
        }

        $history = $this->log->read();

        if (!$history->isDetermined()) {
            return RecoveryOutcome::unsettled(
                'an interrupted run left a write intent and the event log could not be read',
                [(string) $history->reason]
            );
        }

        foreach ($history->records() as $committed) {
            if ($committed->correlationId === $intent->id) {
                return $this->clearSettled(\sprintf(
                    'the mutation at %s was already recorded as %s, so only its write intent '
                    . 'was outstanding',
                    $record->target,
                    $committed->kind
                ));
            }
        }

        return $this->reconcile($intent, $record);
    }

    /**
     * No event carries the intent's id, so the target's own state decides.
     */
    private function reconcile(WriteIntent $intent, EventRecord $record): RecoveryOutcome
    {
        $observed = $this->observe((string) $record->target, (string) $record->operation);

        if ($observed === null) {
            return RecoveryOutcome::unsettled(
                'an interrupted run left a write intent whose target could not be read',
                [\sprintf(
                    '%s could not be read, so the mutation the write intent at %s describes '
                    . 'cannot be settled',
                    (string) $record->target,
                    $this->intents->path()
                )]
            );
        }

        if ($observed === $record->post) {
            return $this->commit(
                $record->asObserved(),
                \sprintf(
                    '%s is in the state the interrupted mutation was to leave, so that event '
                    . 'was recorded as observed',
                    (string) $record->target
                )
            );
        }

        if ($observed === $record->pre) {
            return $this->commit(
                EventRecord::mutation(
                    EventRecord::INTENT_ABANDONED,
                    (string) $record->operation,
                    (string) $record->target,
                    (string) $record->pre,
                    (string) $record->post,
                    $intent->id,
                    EventRecord::OBSERVED,
                    reason: 'the interrupted change was never made: the file is as it was before'
                ),
                \sprintf(
                    '%s is still in the state the interrupted mutation was to replace, so the '
                    . 'intent was recorded as abandoned',
                    (string) $record->target
                )
            );
        }

        return $this->commit(
            EventRecord::mutation(
                EventRecord::UNRESOLVED_INTENT,
                (string) $record->operation,
                (string) $record->target,
                (string) $record->pre,
                (string) $record->post,
                $intent->id,
                EventRecord::OBSERVED,
                observed: $observed,
                reason: 'the file matches neither the before nor the after of the interrupted '
                    . 'change'
            ),
            \sprintf(
                '%s matches neither state of the interrupted mutation, so an unresolved-intent '
                . 'fault was recorded and the run marker stays until this run completes',
                (string) $record->target
            )
        );
    }

    /**
     * Append the settling event, then clear the intent.
     *
     * In that order, and never the other way round: an intent removed before its
     * event is committed leaves a mutation with no record and nothing to rebuild it
     * from.
     */
    private function commit(EventRecord $record, string $reason): RecoveryOutcome
    {
        if (!$this->log->append($record)) {
            return RecoveryOutcome::unsettled(
                'an interrupted run left a write intent whose event could not be committed',
                [\sprintf(
                    'the recovery event for %s could not be appended (%s), so the write intent '
                    . 'at %s and the run marker stay',
                    (string) $record->target,
                    (string) $this->log->failure(),
                    $this->intents->path()
                )]
            );
        }

        if (!$this->intents->remove()) {
            return RecoveryOutcome::unsettled(
                'an interrupted run left a write intent that could not be removed',
                [\sprintf(
                    'the recovery event for %s is committed, but the write intent at %s could '
                    . 'not be removed',
                    (string) $record->target,
                    $this->intents->path()
                )]
            );
        }

        return RecoveryOutcome::settled($reason);
    }

    /**
     * The already-recorded case: nothing to append, only the intent to clear.
     */
    private function clearSettled(string $reason): RecoveryOutcome
    {
        if (!$this->intents->remove()) {
            return RecoveryOutcome::unsettled(
                'an interrupted run left a settled write intent that could not be removed',
                [\sprintf('the write intent at %s could not be removed', $this->intents->path())]
            );
        }

        return RecoveryOutcome::settled($reason);
    }

    /**
     * The current state of what the intent describes, in the intent's own vocabulary.
     *
     * Null when it cannot be read at all, which is not the same as absent: absence
     * is a state a mutation can have left, and unreadable is the package unable to
     * say.
     */
    private function observe(string $targetPath, string $operation): ?string
    {
        if ($operation === EventRecord::FREEZING || $operation === EventRecord::RELEASE) {
            return $this->freezes->isFrozen($targetPath)
                ? EventRecord::PRESENT
                : EventRecord::ABSENT;
        }

        $decision = $this->policy->decide($targetPath);

        if (!$decision->allowed) {
            return null;
        }

        $path = (string) $decision->path;
        \clearstatcache(true, $path);

        if (!\file_exists($path) && !\is_link($path)) {
            return EventRecord::ABSENT;
        }

        if (!\is_file($path) || \is_link($path)) {
            return null;
        }

        $hash = $this->hasher->hashFile(self::STATE_HASH, $path);

        return $hash->digest;
    }

    /**
     * Unlink every abandoned staged copy. Under the lock, none of them is live.
     *
     * @return list<string>
     */
    private function sweepStaging(): array
    {
        $directory = $this->paths->staging();
        \clearstatcache(true, $directory);

        if (\is_link($directory) || !\is_dir($directory)) {
            return [];
        }

        $entries = @\scandir($directory);

        if ($entries === false) {
            return [\sprintf('%s could not be read, so abandoned staged copies were left', $directory)];
        }

        $left = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || PackagePaths::isFolderGuard($entry)) {
                continue;
            }

            $path = $directory . '/' . $entry;

            // Never recurse and never follow. A directory here is not something this
            // package wrote, and a link's target is somewhere else entirely.
            if (!\is_link($path) && \is_dir($path)) {
                $left[] = \sprintf('%s is a directory in the staging folder and was left', $path);
                continue;
            }

            @\unlink($path);
            \clearstatcache(true, $path);

            if (\file_exists($path) || \is_link($path)) {
                $left[] = \sprintf('%s could not be removed from the staging folder', $path);
            }
        }

        return $left;
    }

    /**
     * @return list<string>
     */
    private function sweepStateTemporaries(): array
    {
        $left = [...$this->marker->sweepTemporaries(), ...$this->intents->sweepTemporaries()];

        return \array_map(
            static fn (string $path): string => \sprintf(
                '%s is an abandoned publication temporary and could not be removed',
                $path
            ),
            $left
        );
    }
}
