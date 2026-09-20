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
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\HashAlgorithm;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\Hasher;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\ExclusiveCopy;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path\PathPolicy;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BackupOutcome;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BackupStore;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventLog;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventRecord;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntent;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntentStore;

/**
 * Staged atomic payload writes and removals, work items 10, 11 and 12.
 *
 * The only route by which a payload reaches a target or a target is removed. A
 * target is always the complete old file or the complete new file.
 *
 * ## A write
 *
 * Stage, verify, decide, set-mode, intent, move, event, clear intent, decision 32.
 *
 * The payload is copied to an exclusive `staging/<basename>.<12 hex>.tmp` and hashed
 * there, so the bytes that are moved are the bytes that were verified. Nothing
 * normalises them on the way, decision 14. Path policy then decides the target. A
 * target that is not a regular file, a missing parent directory, or a parent on a
 * different filesystem from `staging/` is refused: a cross-device rename is a copy,
 * and a copy is not atomic. The current bytes must still be what the caller planned
 * to displace, absent for a create. A required backup is published next (ticket 30).
 * The staged file takes the target's mode, or `0644` for a create, decision 28, and a
 * failed `chmod` stops the write.
 *
 * ## A removal
 *
 * Backup, intent, unlink, event, clear intent. A removal told to keep no copy uses
 * the same protocol, because its event is the only record it leaves. An absent
 * target is already removed and writes nothing.
 *
 * ## Deciding at the move
 *
 * `PathPolicy::decide()` resolves a path and the mutation happens later, so a link
 * swapped in between defeats a decision taken earlier. PHP has no `openat` or
 * `O_NOFOLLOW`, so the window cannot be closed. It is narrowed instead: path policy
 * is asked again immediately before the intent is published, the answer must be the
 * same resolved path, and the target must still be in its pre-state. The deny list
 * therefore applies at the move and not only at the plan.
 *
 * ## After the intent
 *
 * The intent carries a new correlation id and the complete event payload. A
 * completed mutation appends that event, then removes the intent. A mutation that
 * reports failure is settled only when the target still matches its pre-state and a
 * `mutation-failed` event with the same id is committed. Anything short of that
 * leaves the intent for ticket 34's recovery. Nothing here logs before the mutation.
 *
 * The caller holds the run lock. This class does not take it, and it does not sweep
 * `staging/`: a completed call leaves none of its own temporaries there, and a crash
 * leaves one for recovery to remove under the lock. If a returned call cannot remove
 * its own temporary, the outcome names that leftover so the run cannot claim the
 * completed-run guarantee.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class TargetWriter
{
    /**
     * The points a checkpoint is told about, in the order a write reaches them.
     *
     * The checkpoint is a test seam. A test uses it to change the tree at a named
     * point or to stop the call there the way a crash would. Production passes none.
     * PREPARED is after the first path decision and any backup, before chmod and
     * the decision taken at the move.
     */
    public const STAGED           = 'staged';
    public const PREPARED         = 'prepared';
    public const INTENT_PUBLISHED = 'intent-published';
    public const MUTATED          = 'mutated';
    public const EVENT_COMMITTED  = 'event-committed';

    /** The mode a created file gets, decision 28. */
    public const CREATE_MODE = 0o644;

    /**
     * The event kinds a caller may record a payload write or a removal as.
     *
     * Apply records a `write` or a `remove`. Ticket 33's revert and redo move the
     * same bytes through the same staged, verified move, and the log has to say
     * which of the three moved them: decision 39 lists reverts and redos as
     * durable history in their own right, and an operator reading `write` where a
     * revert happened would be told the package carried the target forward when it
     * stepped it back. The kind is the caller's because only the caller knows.
     */
    public const WRITE_KINDS = [EventRecord::WRITE, EventRecord::REVERT, EventRecord::REDO];

    public const REMOVE_KINDS = [EventRecord::REMOVE, EventRecord::REVERT, EventRecord::REDO];

    /** Event states are sha256 digests whatever the rule declared, decision 32. */
    private const STATE_HASH = 'sha256';

    private readonly Hasher $hasher;

    private readonly BackupStore $backups;

    private readonly MutationTransaction $mutations;

    /**
     * @param Closure(string, string|null): void|null $checkpoint Called with a point name
     *                                                           and the staged file, if any.
     */
    public function __construct(
        private readonly PackagePaths $paths,
        private readonly PathPolicy $policy,
        private readonly ?Closure $checkpoint = null,
    ) {
        $this->hasher    = new Hasher();
        $this->backups   = new BackupStore($paths);
        $this->mutations = new MutationTransaction(
            new WriteIntentStore($paths),
            new EventLog($paths),
            function (string $point): void {
                $this->reach($point, null);
            },
        );
    }

    /**
     * Put a payload at a target, whole or not at all.
     *
     * @param string      $targetPath      The canonical root-relative target path.
     * @param string      $payloadPath     The payload file's absolute path.
     * @param string      $hashType        The algorithm the rule declared.
     * @param string      $payloadDigest   The payload's digest under $hashType.
     * @param string|null $displacedDigest The digest of the bytes the caller planned to
     *                                     replace, or null for a create.
     * @param int|null    $backupVersion   The version to back the displaced bytes up
     *                                     under, or null when they are reproducible.
     * @param string      $kind            The event kind this write is recorded as,
     *                                     one of WRITE_KINDS.
     *
     * @throws InvalidArgumentException On a target path, backup request or event kind
     *                                  no validated rule and no caller here can produce.
     */
    public function write(
        string $targetPath,
        string $payloadPath,
        string $hashType,
        string $payloadDigest,
        ?string $displacedDigest,
        ?int $backupVersion,
        string $kind = EventRecord::WRITE,
    ): WriteOutcome {
        self::requireTarget($targetPath);
        self::requireKind($kind, self::WRITE_KINDS);

        if ($displacedDigest === null && $backupVersion !== null) {
            throw new InvalidArgumentException('A create displaces nothing, so it has no backup');
        }

        if (!HashAlgorithm::isSupported($hashType)) {
            return WriteOutcome::refused(HashAlgorithm::unsupportedReason($hashType));
        }

        $reason    = null;
        $leftover  = null;
        $temporary = $this->stage($targetPath, $payloadPath, $reason, $leftover);

        if ($temporary === null) {
            $outcome = WriteOutcome::refused((string) $reason);

            return $leftover === null ? $outcome : $outcome->withLeftover($leftover);
        }

        $this->reach(self::STAGED, $temporary);

        $outcome = $this->writeStaged(
            $targetPath,
            $temporary,
            $hashType,
            $payloadDigest,
            $displacedDigest,
            $backupVersion,
            $kind
        );

        // Only reached when the call returned. A successful move has consumed the
        // temporary, and every other return leaves it for this to remove.
        $left = ExclusiveCopy::discard($temporary);

        return $left === null ? $outcome : $outcome->withLeftover($temporary);
    }

    /**
     * Remove a target, after its backup when one is required.
     *
     * @param string   $targetPath    The canonical root-relative target path.
     * @param string   $hashType      The algorithm the rule declared.
     * @param string   $digest        The digest of the bytes the caller planned to remove.
     * @param int|null $backupVersion The version to back those bytes up under, or null
     *                                for a removal told to keep no copy.
     * @param string   $kind          The event kind this removal is recorded as, one
     *                                of REMOVE_KINDS.
     *
     * @throws InvalidArgumentException On a target path or event kind no validated rule
     *                                  and no caller here can produce.
     */
    public function remove(
        string $targetPath,
        string $hashType,
        string $digest,
        ?int $backupVersion,
        string $kind = EventRecord::REMOVE,
    ): WriteOutcome {
        self::requireTarget($targetPath);
        self::requireKind($kind, self::REMOVE_KINDS);

        if (!HashAlgorithm::isSupported($hashType)) {
            return WriteOutcome::refused(HashAlgorithm::unsupportedReason($hashType));
        }

        $decision = $this->policy->decide($targetPath);

        if (!$decision->allowed) {
            return WriteOutcome::refused((string) $decision->reason);
        }

        $resolved = (string) $decision->path;
        $reason   = null;
        $pre      = $this->state($resolved, $reason);

        if ($pre === EventRecord::ABSENT) {
            return WriteOutcome::unchanged('the target is already absent, so nothing was removed');
        }

        if ($pre === null) {
            return WriteOutcome::refused((string) $reason);
        }

        $reason = $this->mismatch($resolved, $hashType, $digest, $pre);

        if ($reason !== null) {
            return WriteOutcome::refused($reason);
        }

        $backup = null;

        if ($backupVersion !== null) {
            $backup = $this->backups->preserve($targetPath, $backupVersion, $hashType, $digest, $resolved);

            if (!$backup->isPreserved()) {
                return WriteOutcome::refused(
                    \sprintf('the target was not removed because its backup failed: %s', $backup->reason),
                    $backup
                );
            }
        }

        $this->reach(self::PREPARED, null);

        $record = EventRecord::mutation(
            $kind,
            EventRecord::REMOVAL,
            $targetPath,
            $pre,
            EventRecord::ABSENT,
            WriteIntent::mintId()
        );

        return $this->mutate(
            $record,
            $resolved,
            $backup,
            static fn (): bool => @\unlink($resolved),
            \sprintf('%s could not be unlinked', $resolved),
            'the target was removed'
        );
    }

    /**
     * Everything between a staged payload and its move.
     */
    private function writeStaged(
        string $targetPath,
        string $temporary,
        string $hashType,
        string $payloadDigest,
        ?string $displacedDigest,
        ?int $backupVersion,
        string $kind,
    ): WriteOutcome {
        $staged = $this->hasher->hashFile($hashType, $temporary);

        if (!$staged->matches($payloadDigest)) {
            return WriteOutcome::refused(\sprintf(
                'the staged payload hashes to %s, not %s, so nothing was written',
                $staged->describe(),
                \strtolower($payloadDigest)
            ));
        }

        $decision = $this->policy->decide($targetPath);

        if (!$decision->allowed) {
            return WriteOutcome::refused((string) $decision->reason);
        }

        $resolved = (string) $decision->path;
        $reason   = $this->placementRefusal($resolved);

        if ($reason !== null) {
            return WriteOutcome::refused($reason);
        }

        $pre = $this->state($resolved, $reason);

        if ($pre === null) {
            return WriteOutcome::refused((string) $reason);
        }

        if ($displacedDigest === null) {
            if ($pre !== EventRecord::ABSENT) {
                return WriteOutcome::refused(
                    'a file is at the target, which was planned as a create, so nothing was written'
                );
            }
        } else {
            if ($pre === EventRecord::ABSENT) {
                return WriteOutcome::refused(
                    'the target is absent, and was planned as a replacement, so nothing was written'
                );
            }

            $reason = $this->mismatch($resolved, $hashType, $displacedDigest, $pre);

            if ($reason !== null) {
                return WriteOutcome::refused($reason);
            }
        }

        $post = $this->stateDigest($temporary, $hashType, $staged->digest);

        if ($post === null) {
            return WriteOutcome::refused(\sprintf('the staged payload %s could not be hashed', $temporary));
        }

        if ($post === $pre) {
            return WriteOutcome::unchanged('the target already holds the payload, so nothing was written');
        }

        $backup = null;

        if ($backupVersion !== null) {
            $backup = $this->backups->preserve(
                $targetPath,
                $backupVersion,
                $hashType,
                (string) $displacedDigest,
                $resolved
            );

            if (!$backup->isPreserved()) {
                return WriteOutcome::refused(
                    \sprintf('nothing was written because the backup failed: %s', $backup->reason),
                    $backup
                );
            }
        }

        $this->reach(self::PREPARED, $temporary);

        $mode = $pre === EventRecord::ABSENT ? self::CREATE_MODE : $this->modeOf($resolved);

        if ($mode === null || !@\chmod($temporary, $mode)) {
            return WriteOutcome::refused(
                \sprintf('the mode of the staged payload %s could not be set, so nothing was written', $temporary),
                $backup
            );
        }

        $record = EventRecord::mutation(
            $kind,
            EventRecord::COPY,
            $targetPath,
            $pre,
            $post,
            WriteIntent::mintId()
        );

        return $this->mutate(
            $record,
            $resolved,
            $backup,
            static fn (): bool => @\rename($temporary, $resolved),
            \sprintf('the staged payload could not be moved onto %s', $resolved),
            $pre === EventRecord::ABSENT ? 'the payload was created' : 'the payload replaced the target'
        );
    }

    /**
     * Decide again, publish the intent, mutate, and settle.
     *
     * @param Closure(): bool $mutation The move or unlink, reporting success.
     */
    private function mutate(
        EventRecord $record,
        string $resolved,
        ?BackupOutcome $backup,
        Closure $mutation,
        string $failure,
        string $done,
    ): WriteOutcome {
        $target   = (string) $record->target;
        $decision = $this->policy->decide($target);

        if (!$decision->allowed) {
            return WriteOutcome::refused((string) $decision->reason, $backup);
        }

        if ($decision->path !== $resolved) {
            return WriteOutcome::refused(\sprintf(
                '%s now resolves to %s, not to %s where it was checked, so nothing was written',
                $target,
                (string) $decision->path,
                $resolved
            ), $backup);
        }

        $reason  = null;
        $current = $this->state($resolved, $reason);

        if ($current !== $record->pre) {
            return WriteOutcome::refused(\sprintf(
                'the target changed after it was checked: it is %s, not %s, so nothing was written',
                $current ?? (string) $reason,
                (string) $record->pre
            ), $backup);
        }

        return $this->mutations->run(
            $record,
            static function () use ($mutation, $resolved): bool {
                $mutated = $mutation();
                \clearstatcache(true, $resolved);

                return $mutated;
            },
            function () use ($resolved): string {
                $reason  = null;
                $current = $this->state($resolved, $reason);

                return $current ?? (string) $reason;
            },
            $failure,
            $done,
            'nothing was written',
            'the target',
            $backup
        );
    }

    /**
     * Copy the payload into an exclusive temporary under `staging/`.
     *
     * @param string|null $leftover Set when an incomplete copy could not be removed.
     *
     * @return string|null The temporary, or null with $reason set.
     */
    private function stage(
        string $targetPath,
        string $payloadPath,
        ?string &$reason,
        ?string &$leftover,
    ): ?string {
        $leftover  = null;
        $directory = $this->paths->staging();
        \clearstatcache(true, $directory);

        if (\is_link($directory) || !\is_dir($directory)) {
            $reason = \sprintf('the staging folder %s is not a directory', $directory);

            return null;
        }

        $temporary = $directory . '/' . PackagePaths::publicationTemporaryName(
            \basename($targetPath),
            \bin2hex(\random_bytes(PackagePaths::PUBLICATION_TEMPORARY_BYTES))
        );

        $kind = ExclusiveCopy::copy($payloadPath, $temporary, $leftover);

        if ($kind !== null) {
            $reason = match ($kind) {
                ExclusiveCopy::NOT_A_FILE  => \sprintf('the payload %s is not a regular file', $payloadPath),
                ExclusiveCopy::UNREADABLE  => \sprintf(
                    'the payload %s could not be opened for reading',
                    $payloadPath
                ),
                ExclusiveCopy::UNCREATABLE => \sprintf(
                    'the staged payload %s could not be created',
                    $temporary
                ),
                ExclusiveCopy::INCOMPLETE  => \sprintf('the payload could not be staged at %s', $temporary),
            };

            return null;
        }

        return $temporary;
    }

    /**
     * Why the payload cannot be moved onto this resolved path, or null when it can.
     */
    private function placementRefusal(string $resolved): ?string
    {
        \clearstatcache(true, $resolved);

        if (\is_link($resolved)) {
            return \sprintf('%s is a symbolic link, and a payload is never moved onto one', $resolved);
        }

        if (\file_exists($resolved) && !\is_file($resolved)) {
            return \sprintf('%s is not a regular file, so no payload can replace it', $resolved);
        }

        $parent = \dirname($resolved);
        \clearstatcache(true, $parent);

        if (!\is_dir($parent)) {
            return \sprintf('the directory %s does not exist, and a write does not create one', $parent);
        }

        $staging = @\stat($this->paths->staging());
        $place   = @\stat($parent);

        if ($staging === false || $place === false) {
            return \sprintf(
                'whether %s and %s share a filesystem could not be established',
                $this->paths->staging(),
                $parent
            );
        }

        if ($staging['dev'] !== $place['dev']) {
            return \sprintf(
                '%s and %s are on different filesystems, and a move between them is a copy, not '
                . 'an atomic replacement, so nothing was staged there',
                $this->paths->staging(),
                $parent
            );
        }

        return null;
    }

    /**
     * The target's state for an event: absent, or the sha256 of a regular file.
     *
     * @return string|null The state, or null with $reason set.
     */
    private function state(string $resolved, ?string &$reason): ?string
    {
        \clearstatcache(true, $resolved);

        if (!\file_exists($resolved) && !\is_link($resolved)) {
            return EventRecord::ABSENT;
        }

        if (\is_link($resolved) || !\is_file($resolved)) {
            $reason = \sprintf('%s is not a regular file', $resolved);

            return null;
        }

        $digest = $this->stateDigest($resolved, null, null);

        if ($digest === null) {
            $reason = \sprintf('%s could not be hashed', $resolved);
        }

        return $digest;
    }

    /**
     * The sha256 of a file, reusing a digest already taken under that algorithm.
     */
    private function stateDigest(string $path, ?string $hashType, ?string $digest): ?string
    {
        if ($hashType === self::STATE_HASH && $digest !== null) {
            return \strtolower($digest);
        }

        $result = $this->hasher->hashFile(self::STATE_HASH, $path);

        return $result->isAvailable() ? $result->digest : null;
    }

    /**
     * Why the file at $resolved is not the bytes the caller planned for, or null.
     */
    private function mismatch(string $resolved, string $hashType, string $digest, string $pre): ?string
    {
        $matches = $hashType === self::STATE_HASH
            ? $pre === \strtolower($digest)
            : $this->hasher->hashFile($hashType, $resolved)->matches($digest);

        if ($matches) {
            return null;
        }

        return \sprintf(
            'the target does not hash to %s, the bytes this operation was planned for, so nothing '
            . 'was changed',
            \strtolower($digest)
        );
    }

    private function modeOf(string $resolved): ?int
    {
        \clearstatcache(true, $resolved);
        $permissions = @\fileperms($resolved);

        return $permissions === false ? null : $permissions & 0o7777;
    }

    private function reach(string $point, ?string $staged): void
    {
        if ($this->checkpoint !== null) {
            ($this->checkpoint)($point, $staged);
        }
    }

    /**
     * @param list<string> $kinds
     */
    private static function requireKind(string $kind, array $kinds): void
    {
        if (!\in_array($kind, $kinds, true)) {
            throw new InvalidArgumentException(\sprintf(
                '%s is not an event kind this operation may be recorded as',
                $kind
            ));
        }
    }

    private static function requireTarget(string $targetPath): void
    {
        if (!PackagePaths::isCanonicalTargetPath($targetPath)) {
            throw new InvalidArgumentException(
                \sprintf('%s is not a canonical root-relative target path', $targetPath)
            );
        }
    }
}
