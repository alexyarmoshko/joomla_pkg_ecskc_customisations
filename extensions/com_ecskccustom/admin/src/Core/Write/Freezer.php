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
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\ProcDescriptor;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BoundBackupDirectory;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventLog;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventRecord;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\FreezeMarkers;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntent;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntentStore;

/**
 * Freeze and unfreeze, the write half of `FreezeMarkers`, work item 13.
 *
 * ## A write run of its own
 *
 * Decision 30. Each call takes `run.lock` itself and publishes the run marker
 * through WriteRun, then looks at the freeze marker. A freeze that has returned has
 * therefore excluded every apply, revert or redo that had not already finished, and
 * `FreezeGuard` stops every one that starts later. The runner does not take the
 * lock around these calls: a second acquire in the same process is a second handle,
 * and it would fail.
 *
 * A process that cannot take the lock returns without touching the run marker, the
 * write intent or the freeze marker. The run marker is removed when the call
 * completes, and kept when a write intent is left for recovery.
 *
 * ## An interrupted run is settled first
 *
 * A leftover write intent, or a run marker found while holding the lock, belongs to a
 * run that died. `WriteRun` hands it to `RunRecovery` - one place, so Freezer and
 * Reverter cannot come to settle it differently - and the marker change goes ahead
 * once that has settled. It does not take over the dead run's remaining target
 * writes; only apply does that. An intent recovery could not settle refuses the call,
 * because publishing a new intent over the old one would destroy the only evidence of
 * that mutation.
 *
 * ## The marker mutation
 *
 * Decision 32. The freeze-marker state is `absent` or `present`, never a digest, and
 * `present` is anything `isFrozen()` treats as frozen, which is never a symbolic link,
 * decision 40. The shape is inspected before the intent: freeze on a frozen path and
 * unfreeze on an unfrozen one are not mutations, and a non-empty directory, or a
 * symbolic link where freeze would create the marker, is a fault. None of them
 * publishes an intent. An unreadable empty directory is still a freeze, so unfreeze
 * publishes intent and removes it with `rmdir`. An unlistable directory is opened
 * by changing its mode long enough to read the entries, then the full saved mode,
 * special bits included, is put back on the opened inode. The listing opens the
 * last component and keeps the descriptor that open added. A follow of a
 * different inode cannot match. A follow of the recorded inode is refused
 * because `lstat` of the last component must still be that real directory. The
 * 0700 change still goes through the name, because PHP has no `fchmod` and a
 * mode-0000 directory cannot be opened first. Identity is checked immediately
 * before each pathname `chmod` and after it, so a replacement already visible
 * then is not changed. A rename that lands between that last `lstat` and the
 * pathname `chmod` can still change another inode; unfreeze then reports the
 * identity change and publishes no intent. A restore is not attempted once
 * identity has already moved. A restore that itself races is reported as that
 * identity change rather than as a listing failure. A directory whose entries
 * still cannot be determined, or whose mode cannot be put back, is not known to
 * be empty, and unfreeze refuses it before any intent. Unknown is not empty. A
 * non-empty directory whose entries become readable that way faults before
 * intent, the same as one the bound listing could read.
 *
 * Freeze creates a regular empty file with an exclusive create. Unfreeze removes an
 * empty directory with `rmdir` and any other shape with `unlink`. It never deletes
 * recursively.
 *
 * The parent directory is opened from `backup/` down before the intent, and a
 * missing mirrored directory is created through that handle. PHP has no `openat`
 * or `O_NOFOLLOW`, so a pathname `fopen` or `mkdir` after a path check can follow
 * a symbolic link swapped into an ancestor and land outside `backup/`. The handle
 * cannot. A later swap of a directory already walked does not redirect the
 * mutation.
 *
 * Both operations then check that the logical parent still names that bound inode
 * and that `isFrozen()` reads the post-state before they commit success. A swap
 * that left the freeze marker only under the renamed original tree is an orphan:
 * it is removed and the mutation reports failure, because a freeze that returned
 * while the guard still admits a write is not a freeze. An unfreeze whose removal
 * went into a renamed tree while the logical path still reads frozen reports
 * failure too. It does not delete the marker at the replacement path to make the
 * result true.
 *
 * Then: intent, mutation, event, clear intent. A mutation that fails is settled only
 * when the marker at the logical path is still in its pre-state and `mutation-failed`
 * commits with the same correlation id, decision 32 case 2. Anything short of that
 * leaves the intent, including a detached unfreeze whose logical path now reads
 * unfrozen for a reason other than its own removal. Freeze-marker state has only two
 * values, so neither-state recovery cannot arise.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class Freezer
{
    /**
     * The points a checkpoint is told about, in the order a call reaches them.
     *
     * A test seam, as in TargetWriter. LOCKED is after the lock and the run marker,
     * before the freeze marker is inspected. CREATING_DIRECTORY is immediately before
     * a missing mirrored directory is created. LISTING_MARKER is after `lstat` of the
     * bound last component, before that name is opened or pathname-`chmod`ed. It is
     * reached before each of those pathname operations. Production passes none.
     */
    public const LOCKED              = 'locked';
    public const CREATING_DIRECTORY  = 'creating-directory';
    public const LISTING_MARKER      = 'listing-marker';
    public const INTENT_PUBLISHED    = 'intent-published';
    public const MUTATED             = 'mutated';
    public const EVENT_COMMITTED     = 'event-committed';

    private readonly WriteRun $writeRun;

    private readonly WriteIntentStore $intents;

    private readonly FreezeMarkers $markers;

    private readonly MutationTransaction $mutations;

    /**
     * @param Closure(string): void|null $checkpoint Called with a point name.
     * @param RunRecovery|null           $recovery   Settles what an interrupted run
     *                                               left, before this one's own
     *                                               marker change. A freezer given
     *                                               none refuses an interrupted tree.
     */
    public function __construct(
        private readonly PackagePaths $paths,
        private readonly ?Closure $checkpoint = null,
        ?RunRecovery $recovery = null,
    ) {
        $this->writeRun  = new WriteRun($paths, 'the freeze marker was not touched', $recovery);
        $this->intents   = new WriteIntentStore($paths);
        $this->markers   = new FreezeMarkers($paths);
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
        return $this->writeRun->execute(
            $targetPath,
            function () use ($operation): WriteOutcome {
                $this->reach(self::LOCKED);

                return $operation();
            }
        );
    }

    private function create(string $targetPath): WriteOutcome
    {
        if ($this->markers->isFrozen($targetPath)) {
            return WriteOutcome::unchanged(\sprintf('%s is already frozen, so nothing was written', $targetPath));
        }

        $bound = $this->bindParent($targetPath, true);

        if (\is_string($bound)) {
            return WriteOutcome::refused(\sprintf('%s was not frozen: %s', $targetPath, $bound));
        }

        try {
            $path = $bound->child($this->markerName($targetPath));
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
                function () use ($path, $bound, $targetPath): bool {
                    $handle = @\fopen($path, 'x');

                    if ($handle === false) {
                        return false;
                    }

                    \fclose($handle);

                    if ($this->parentStillBound($bound, $targetPath) && $this->markers->isFrozen($targetPath)) {
                        return true;
                    }

                    @\unlink($path);

                    return false;
                },
                $this->observer($targetPath),
                \sprintf('the freeze marker %s could not be created', $this->paths->freezeMarker($targetPath)),
                \sprintf('%s was frozen', $targetPath),
                'the freeze marker was not touched',
                'the freeze marker'
            );
        } finally {
            $bound->close();
        }
    }

    private function remove(string $targetPath): WriteOutcome
    {
        if (!$this->markers->isFrozen($targetPath)) {
            return WriteOutcome::unchanged(\sprintf('%s is not frozen, so nothing was removed', $targetPath));
        }

        $bound = $this->bindParent($targetPath, false);

        if (\is_string($bound)) {
            return WriteOutcome::refused(\sprintf(
                '%s was not unfrozen: %s',
                $targetPath,
                $bound
            ));
        }

        try {
            $named = $this->paths->freezeMarker($targetPath);
            $path  = $bound->child($this->markerName($targetPath));
            \clearstatcache(true, $path);

            $directory = \is_dir($path) && !\is_link($path);

            if ($directory) {
                $entries = $this->entriesOf($path, $named);

                if (\is_string($entries)) {
                    return WriteOutcome::refused($entries);
                }

                if (\array_diff($entries, ['.', '..']) !== []) {
                    return WriteOutcome::refused(\sprintf(
                        'the freeze marker %s is a directory that is not empty, and an unfreeze never '
                        . 'deletes recursively, so it was not removed',
                        $named
                    ));
                }
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
                function () use ($path, $directory, $bound, $targetPath): bool {
                    $removed = $directory ? @\rmdir($path) : @\unlink($path);

                    return $removed
                        && $this->parentStillBound($bound, $targetPath)
                        && !$this->markers->isFrozen($targetPath);
                },
                $this->observer($targetPath),
                \sprintf('the freeze marker %s could not be removed', $named),
                \sprintf('%s was unfrozen', $targetPath),
                'the freeze marker was not touched',
                'the freeze marker'
            );
        } finally {
            $bound->close();
        }
    }

    /**
     * Open the marker's parent from `backup/` down without following a swapped ancestor.
     *
     * @return BoundBackupDirectory|string The bound directory, or why not.
     */
    private function bindParent(string $targetPath, bool $create): BoundBackupDirectory|string
    {
        return BoundBackupDirectory::open(
            $this->paths,
            $targetPath,
            $create,
            $create ? function (): void {
                $this->reach(self::CREATING_DIRECTORY);
            } : null,
            destination: $create ? $this->paths->freezeMarker($targetPath) : null,
        );
    }

    /**
     * The marker's logical parent still names the inode this mutation went through.
     *
     * Asked after the create or removal. A parent swapped for another real
     * directory, or for a symbolic link, leaves the mutation detached from the
     * path the guard reads, and neither freeze nor unfreeze may report success.
     */
    private function parentStillBound(BoundBackupDirectory $bound, string $targetPath): bool
    {
        $logical = \dirname($this->paths->freezeMarker($targetPath));
        \clearstatcache(true, $logical);
        \clearstatcache(true, $bound->proc);

        $want = @\stat($bound->proc);
        $got  = @\stat($logical);

        return $want !== false
            && $got !== false
            && $want['dev'] === $got['dev']
            && $want['ino'] === $got['ino'];
    }

    /**
     * Directory entries, including those of a directory whose mode forbids listing.
     *
     * The last component is identified with `lstat` through the bound parent, which
     * does not follow a symbolic link there. The listing then opens that name and
     * keeps the descriptor that open added. `opendir` follows, so a replacement
     * at a different inode cannot match. A symbolic link to the recorded inode is
     * refused because `lstat` of the last component must still be that real
     * directory. PHP has no `fchmod`, and a mode-0000 directory cannot be opened
     * first, so the 0700 change still goes through the name. Identity is checked
     * immediately before each pathname `chmod` and after it. The saved mode is
     * put back on the opened inode through `/proc/self/fd` when that open
     * succeeded, with every permission and special bit it had. A pathname restore
     * after a failed open is checked the same way as the 0700 change. A directory
     * whose entries cannot be determined, whose identity moved, or whose mode
     * cannot be put back, is not known to be empty, and the caller refuses it
     * before any intent.
     *
     * @param string $named The marker's logical path, for the reason.
     *
     * @return list<string>|string The entries, or why they could not be determined.
     */
    private function entriesOf(string $path, string $named): array|string
    {
        $inode = $this->directoryInode($path);

        if ($inode === null) {
            return $this->undeterminedEntries($named, $path);
        }

        $this->reach(self::LISTING_MARKER);

        $bound = $this->openBoundDirectory($path, $inode);

        if ($bound !== null) {
            [$handle] = $bound;

            try {
                return $this->readEntries($handle);
            } finally {
                \closedir($handle);
            }
        }

        if (!$this->isStillTheDirectory($inode, $path)) {
            return $this->undeterminedEntries($named, $path);
        }

        $mode = $inode['mode'] & 0o7777;

        $this->reach(self::LISTING_MARKER);
        if (!@\chmod($path, 0o700)) {
            return \sprintf(
                'the freeze marker %s is a directory whose entries could not be listed, and its mode could '
                . 'not be changed to list them, so it is not known to be empty and was not removed',
                $named
            );
        }

        if (!$this->isStillTheDirectory($inode, $path)) {
            return $this->identityMovedAfterModeChange($named);
        }

        $this->reach(self::LISTING_MARKER);
        $bound = $this->openBoundDirectory($path, $inode);

        if ($bound === null) {
            return $this->unreadableAfterModeChange($path, $named, $inode, $mode);
        }

        [$handle, $opened] = $bound;

        try {
            $entries  = $this->readEntries($handle);
            $restored = @\chmod($opened, $mode);

            if (!$restored) {
                return \sprintf(
                    'the freeze marker %s is a directory that was listed after its mode was changed, but the mode '
                    . '%04o could not be put back, so it was not removed',
                    $named,
                    $mode
                );
            }

            return $entries;
        } finally {
            \closedir($handle);
        }
    }

    /**
     * Open `$path` and the `/proc/self/fd/N` that open added for `$inode`.
     *
     * `opendir` follows a symbolic link. A follow of a different inode cannot
     * match `$inode`. A follow of `$inode` is still refused unless `lstat` of
     * `$path` remains that real directory, so a symbolic link to the recorded
     * inode is not listed.
     *
     * @param array<string, mixed> $inode
     *
     * @return array{0: resource, 1: string}|null
     */
    private function openBoundDirectory(string $path, array $inode): ?array
    {
        $before = ProcDescriptor::pathsFor($inode);
        $handle = @\opendir($path);

        if ($handle === false) {
            return null;
        }

        $opened = ProcDescriptor::pathOpenedSince($inode, $before);

        if ($opened === null || !$this->isStillTheDirectory($inode, $path)) {
            \closedir($handle);

            return null;
        }

        return [$handle, $opened];
    }

    /**
     * Why the marker at `$path` cannot be listed, when it is no longer the directory
     * unfreeze bound.
     */
    private function undeterminedEntries(string $named, string $path): string
    {
        \clearstatcache(true, $path);
        $got = @\lstat($path);

        if ($got !== false && ($got['mode'] & 0o170000) === 0o120000) {
            return \sprintf(
                'the freeze marker %s is a symbolic link, which is not a freeze, so its target was not listed '
                . 'or changed, it is not known to be empty and was not removed',
                $named
            );
        }

        return \sprintf(
            'the freeze marker %s is a directory whose entries could not be listed, and it is no longer a '
            . 'directory, so it is not known to be empty and was not removed',
            $named
        );
    }

    /**
     * The listing still failed after a successful 0700 change of the same inode.
     *
     * A restore that also fails is the reason: the directory is left at 0700. A
     * replacement between the restore `lstat` and `chmod` is reported as an
     * identity change, not as this listing failure.
     *
     * @param array<string, mixed> $inode `lstat()` of the directory before the change.
     */
    private function unreadableAfterModeChange(string $path, string $named, array $inode, int $mode): string
    {
        if (!$this->isStillTheDirectory($inode, $path)) {
            return $this->identityMovedAfterModeChange($named);
        }

        $this->reach(self::LISTING_MARKER);
        $restored = @\chmod($path, $mode);

        if (!$this->isStillTheDirectory($inode, $path)) {
            return $this->identityMovedAfterModeChange($named);
        }

        if (!$restored) {
            return \sprintf(
                'the freeze marker %s is a directory whose entries could not be listed even after its mode '
                . 'was changed, and the mode %04o could not be put back, so it was not removed',
                $named,
                $mode
            );
        }

        return \sprintf(
            'the freeze marker %s is a directory whose entries could not be listed even after its mode '
            . 'was changed, so it is not known to be empty and was not removed',
            $named
        );
    }

    /**
     * The last component no longer names the directory whose mode was changed to 0700.
     * That change is not undone: a pathname restore would hit whatever sits there now.
     */
    private function identityMovedAfterModeChange(string $named): string
    {
        return \sprintf(
            'the freeze marker %s is a directory whose identity changed after its mode was changed to 0700, '
            . 'so that mode was not put back, it is not known to be empty and was not removed',
            $named
        );
    }

    /**
     * `lstat()` of a real directory at the last component, or null when the name
     * is missing, a symbolic link, or any other non-directory.
     *
     * @return array<string, mixed>|null
     */
    private function directoryInode(string $path): ?array
    {
        \clearstatcache(true, $path);
        $named = @\lstat($path);

        if ($named === false || ($named['mode'] & 0o170000) !== 0o040000) {
            return null;
        }

        return $named;
    }

    /**
     * Is the last component still the directory `$inode` describes?
     *
     * @param array<string, mixed> $inode
     */
    private function isStillTheDirectory(array $inode, string $path): bool
    {
        \clearstatcache(true, $path);
        $got = @\lstat($path);

        return $got !== false
            && ($got['mode'] & 0o170000) === 0o040000
            && $got['dev'] === $inode['dev']
            && $got['ino'] === $inode['ino'];
    }

    /**
     * @param resource $handle
     *
     * @return list<string>
     */
    private function readEntries($handle): array
    {
        $entries = [];

        while (($entry = \readdir($handle)) !== false) {
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * The marker state at the target's logical path, in the intent's vocabulary.
     *
     * The logical state, not the bound path's. It is what the guard reads and what
     * ticket 34's recovery will observe, so a settlement compares the same thing.
     *
     * @return Closure(): string
     */
    private function observer(string $targetPath): Closure
    {
        return fn (): string => $this->markers->isFrozen($targetPath)
            ? EventRecord::PRESENT
            : EventRecord::ABSENT;
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
