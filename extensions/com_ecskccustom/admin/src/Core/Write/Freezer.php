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
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BackupStore;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventLog;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventRecord;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\FreezeMarkers;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunLock;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunMarker;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntent;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntentStore;

/**
 * Freeze and unfreeze, the write half of `FreezeMarkers`, work item 13.
 *
 * ## A write run of its own
 *
 * Decision 30. Each call takes `run.lock` itself, publishes the run marker, and only
 * then looks at the freeze marker. A freeze that has returned has therefore excluded
 * every apply, revert or redo that had not already finished, and `FreezeGuard` stops
 * every one that starts later. The runner does not take the lock around these calls:
 * a second acquire in the same process is a second handle, and it would fail.
 *
 * A process that cannot take the lock returns without touching the run marker, the
 * write intent or the freeze marker. The run marker is removed when the call
 * completes, and kept when a write intent is left for recovery.
 *
 * ## An interrupted run is not settled here
 *
 * A leftover write intent, or a run marker found while holding the lock, belongs to a
 * run that died. Settling it is ticket 34's recovery. Publishing a new intent over the
 * old one would destroy the only evidence of that mutation, so both refuse the call
 * before the run marker is replaced.
 *
 * ## The marker mutation
 *
 * Decision 32. The freeze-marker state is `absent` or `present`, never a digest, and
 * `present` is anything `isFrozen()` treats as frozen, which is never a symbolic link,
 * decision 40. The shape is inspected before the intent: freeze on a frozen path and
 * unfreeze on an unfrozen one are not mutations, and a non-empty directory, or a
 * symbolic link where freeze would create the marker, is a fault. None of them
 * publishes an intent. An unreadable empty directory is still a freeze, so unfreeze
 * publishes intent and removes it with `rmdir`. Listing is how a non-empty directory
 * is known; an unlistable directory is not refused as one.
 *
 * Freeze creates a regular empty file with an exclusive create. Unfreeze removes an
 * empty directory with `rmdir` and any other shape with `unlink`. It never deletes
 * recursively.
 *
 * The parent directory is opened from `backup/` down before the intent, and the
 * create, unlink or rmdir uses that handle. PHP has no `openat` or `O_NOFOLLOW`, so
 * a pathname `fopen` after the intent can follow a symbolic link swapped into an
 * ancestor and land outside `backup/`. The handle cannot. A later swap of a
 * directory already walked does not redirect the mutation.
 *
 * Then: intent, mutation, event, clear intent. A mutation that fails is settled only
 * when the marker is still in its pre-state and `mutation-failed` commits with the
 * same correlation id, decision 32 case 2. Anything short of that leaves the intent.
 * Freeze-marker state has only two values, so neither-state recovery cannot arise.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class Freezer
{
    /**
     * The points a checkpoint is told about, in the order a call reaches them.
     *
     * A test seam, as in TargetWriter. LOCKED is after the lock and the run marker,
     * before the freeze marker is inspected. Production passes none.
     */
    public const LOCKED           = 'locked';
    public const INTENT_PUBLISHED = 'intent-published';
    public const MUTATED          = 'mutated';
    public const EVENT_COMMITTED  = 'event-committed';

    private readonly RunLock $lock;

    private readonly RunMarker $marker;

    private readonly WriteIntentStore $intents;

    private readonly FreezeMarkers $markers;

    private readonly BackupStore $backups;

    private readonly MutationTransaction $mutations;

    /**
     * @param Closure(string): void|null $checkpoint Called with a point name.
     */
    public function __construct(
        private readonly PackagePaths $paths,
        private readonly ?Closure $checkpoint = null,
    ) {
        $this->lock    = new RunLock($paths);
        $this->marker  = new RunMarker($paths);
        $this->intents = new WriteIntentStore($paths);
        $this->markers = new FreezeMarkers($paths);
        $this->backups = new BackupStore($paths);
        $this->mutations = new MutationTransaction(
            $this->intents,
            new EventLog($paths),
            function (string $point): void {
                $this->reach($point);
            },
        );
    }

    /**
     * Hold a target against every write until it is unfrozen.
     *
     * @param string $targetPath The canonical root-relative target path.
     *
     * @throws InvalidArgumentException On a target path no validated rule can produce.
     */
    public function freeze(string $targetPath): WriteOutcome
    {
        return $this->run($targetPath, fn (): WriteOutcome => $this->create($targetPath));
    }

    /**
     * Release a freeze, whatever shape its marker has.
     *
     * @param string $targetPath The canonical root-relative target path.
     *
     * @throws InvalidArgumentException On a target path no validated rule can produce.
     */
    public function unfreeze(string $targetPath): WriteOutcome
    {
        return $this->run($targetPath, fn (): WriteOutcome => $this->remove($targetPath));
    }

    /**
     * Take the lock, publish the run marker, do the operation, and close the run.
     *
     * @param Closure(): WriteOutcome $operation
     */
    private function run(string $targetPath, Closure $operation): WriteOutcome
    {
        if (!PackagePaths::isCanonicalTargetPath($targetPath)) {
            throw new InvalidArgumentException(
                \sprintf('%s is not a canonical root-relative target path', $targetPath)
            );
        }

        if (!$this->lock->acquire()) {
            return WriteOutcome::refused(\sprintf(
                'the run lock was not acquired (%s), so the freeze marker was not touched',
                (string) $this->lock->refusal()
            ));
        }

        try {
            $reason = $this->interruption();

            if ($reason !== null) {
                return WriteOutcome::refused($reason);
            }

            if (!$this->marker->publish()) {
                return WriteOutcome::refused(\sprintf(
                    'the run marker at %s could not be published, so the freeze marker was not touched',
                    $this->marker->path()
                ));
            }

            $this->reach(self::LOCKED);

            $outcome = $operation();

            // An intent left for recovery means this run did not complete. The run
            // marker stays beside it, as decision 35 keeps it for a stuck append.
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
     * Asked with the lock held, so a present intent or run marker is not a live run's.
     */
    private function interruption(): ?string
    {
        if ($this->intents->isPresent()) {
            return \sprintf(
                'the write intent at %s was left by an interrupted run, and recovery has to settle it '
                . 'first, so the freeze marker was not touched',
                $this->intents->path()
            );
        }

        if ($this->marker->isPresent()) {
            return \sprintf(
                'the run marker at %s was left by an interrupted run, and recovery has to settle it '
                . 'first, so the freeze marker was not touched',
                $this->marker->path()
            );
        }

        return null;
    }

    private function create(string $targetPath): WriteOutcome
    {
        if ($this->markers->isFrozen($targetPath)) {
            return WriteOutcome::unchanged(\sprintf('%s is already frozen, so nothing was written', $targetPath));
        }

        $reason = $this->backups->prepareDirectory($targetPath);

        if ($reason !== null) {
            return WriteOutcome::refused(\sprintf('%s was not frozen: %s', $targetPath, $reason));
        }

        $bound = $this->bindParent($targetPath);

        if (\is_string($bound)) {
            return WriteOutcome::refused(\sprintf('%s was not frozen: %s', $targetPath, $bound));
        }

        try {
            $path = $bound['proc'] . '/' . $this->markerName($targetPath);
            \clearstatcache(true, $path);

            if (\is_link($path)) {
                return WriteOutcome::refused(\sprintf(
                    'a symbolic link is at the freeze marker %s, which is not a freeze and is not written '
                    . 'through, so %s was not frozen',
                    $this->paths->freezeMarker($targetPath),
                    $targetPath
                ));
            }

            return $this->mutations->run(
                EventRecord::mutation(
                    EventRecord::FREEZE,
                    EventRecord::FREEZING,
                    $targetPath,
                    EventRecord::ABSENT,
                    EventRecord::PRESENT,
                    WriteIntent::mintId()
                ),
                static function () use ($path): bool {
                    $handle = @\fopen($path, 'x');

                    if ($handle === false) {
                        return false;
                    }

                    \fclose($handle);

                    return true;
                },
                $this->observer($path),
                \sprintf('the freeze marker %s could not be created', $this->paths->freezeMarker($targetPath)),
                \sprintf('%s was frozen', $targetPath),
                'the freeze marker was not touched',
                'the freeze marker'
            );
        } finally {
            \closedir($bound['handle']);
        }
    }

    private function remove(string $targetPath): WriteOutcome
    {
        if (!$this->markers->isFrozen($targetPath)) {
            return WriteOutcome::unchanged(\sprintf('%s is not frozen, so nothing was removed', $targetPath));
        }

        $bound = $this->bindParent($targetPath);

        if (\is_string($bound)) {
            return WriteOutcome::refused(\sprintf(
                '%s was not unfrozen: %s',
                $targetPath,
                $bound
            ));
        }

        try {
            $named = $this->paths->freezeMarker($targetPath);
            $path  = $bound['proc'] . '/' . $this->markerName($targetPath);
            \clearstatcache(true, $path);

            if (\is_dir($path) && !\is_link($path)) {
                $entries = @\scandir($path);

                if ($entries !== false && \array_diff($entries, ['.', '..']) !== []) {
                    return WriteOutcome::refused(\sprintf(
                        'the freeze marker %s is a directory that is not empty, and an unfreeze never '
                        . 'deletes recursively, so it was not removed',
                        $named
                    ));
                }

                $removal = static fn (): bool => @\rmdir($path);
            } else {
                $removal = static fn (): bool => @\unlink($path);
            }

            return $this->mutations->run(
                EventRecord::mutation(
                    EventRecord::UNFREEZE,
                    EventRecord::RELEASE,
                    $targetPath,
                    EventRecord::PRESENT,
                    EventRecord::ABSENT,
                    WriteIntent::mintId()
                ),
                $removal,
                $this->observer($path),
                \sprintf('the freeze marker %s could not be removed', $named),
                \sprintf('%s was unfrozen', $targetPath),
                'the freeze marker was not touched',
                'the freeze marker'
            );
        } finally {
            \closedir($bound['handle']);
        }
    }

    /**
     * Open the marker's parent from `backup/` down without following a swapped ancestor.
     *
     * Each level is opened through the handle of the one above it. A symbolic link
     * at a name is seen with `lstat` of that last component, and a follow between
     * that check and the open cannot match the inode that was verified.
     *
     * @return array{handle: resource, proc: string}|string The bound directory, or why not.
     */
    private function bindParent(string $targetPath): array|string
    {
        $logical = $this->paths->backup();
        $opened  = $this->openRealDirectory($logical);

        if (\is_string($opened)) {
            return $opened;
        }

        $parent = \dirname($targetPath);

        if ($parent === '.') {
            return $opened;
        }

        foreach (\explode('/', $parent) as $segment) {
            $logical  .= '/' . $segment;
            $childPath = $opened['proc'] . '/' . $segment;
            \clearstatcache(true, $childPath);

            if (\is_link($childPath)) {
                \closedir($opened['handle']);

                return \sprintf(
                    '%s is a symbolic link, and nothing under backup/ is written through one',
                    $logical
                );
            }

            if (!\is_dir($childPath)) {
                \closedir($opened['handle']);

                return \sprintf('the backup directory %s could not be opened', $logical);
            }

            $child = $this->openRealDirectory($childPath);
            \closedir($opened['handle']);

            if (\is_string($child)) {
                return $child;
            }

            $opened = $child;
        }

        return $opened;
    }

    /**
     * @return array{handle: resource, proc: string}|string
     */
    private function openRealDirectory(string $path): array|string
    {
        \clearstatcache(true, $path);

        if (\is_link($path)) {
            return \sprintf(
                '%s is a symbolic link, and nothing under backup/ is written through one',
                $path
            );
        }

        if (!\is_dir($path)) {
            return \sprintf('the backup folder %s is not a directory', $path);
        }

        $named  = @\lstat($path);
        $handle = @\opendir($path);

        if ($named === false || $handle === false) {
            return \sprintf('%s could not be opened', $path);
        }

        $proc = $this->procPathFor($named);

        if ($proc === null) {
            \closedir($handle);

            return \sprintf(
                '%s could not be bound, so nothing under backup/ is written through a path that '
                . 'might follow a later symbolic link',
                $path
            );
        }

        return ['handle' => $handle, 'proc' => $proc];
    }

    /**
     * `/proc/self/fd/N` for the directory whose inode was verified before `opendir`.
     *
     * If a symbolic link was followed in the window between `lstat` and `opendir`,
     * the new handle's inode is not this one and no descriptor matches.
     *
     * @param array<string, mixed> $named
     */
    private function procPathFor(array $named): ?string
    {
        $entries = @\scandir('/proc/self/fd');

        if ($entries === false) {
            return null;
        }

        foreach ($entries as $entry) {
            if (!\ctype_digit($entry)) {
                continue;
            }

            $candidate = '/proc/self/fd/' . $entry;
            $got       = @\stat($candidate);

            if ($got === false
                || $got['dev'] !== $named['dev']
                || $got['ino'] !== $named['ino']
            ) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @return Closure(): string
     */
    private function observer(string $path): Closure
    {
        return static function () use ($path): string {
            \clearstatcache(true, $path);

            if (\is_link($path)) {
                return EventRecord::ABSENT;
            }

            return \file_exists($path) ? EventRecord::PRESENT : EventRecord::ABSENT;
        };
    }

    private function markerName(string $targetPath): string
    {
        return \basename($this->paths->freezeMarker($targetPath));
    }

    private function reach(string $point): void
    {
        if ($this->checkpoint !== null) {
            ($this->checkpoint)($point);
        }
    }
}
