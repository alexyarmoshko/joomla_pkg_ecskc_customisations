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
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentValues;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\HashAlgorithm;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\Hasher;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path\PathPolicy;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleAction;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleObject;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleSet;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BackupCatalog;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BackupEntry;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventRecord;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\FreezeMarkers;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ObjectWalker;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ProposedOperation;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetState;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;

/**
 * Forwards is the same question apply asks, so redo asks it the same way: it takes
 * the first operation ObjectWalker proposes for the target and performs that one
 * alone. The enable flag, the conditions, the object permissions and the expected
 * original are then settled in one place rather than two, and a redo cannot
 * disagree with the apply that follows it.
 */
final class Reverter
{
    /**
     * The points a checkpoint is told about.
     *
     * A test seam, as in Freezer and TargetWriter. LOCKED is after the lock and the
     * run marker, before the freeze guard and the step itself. TargetWriter's own
     * points arrive through the same closure. Production passes none.
     */
    public const LOCKED = 'locked';

    public const REAPPLY_WARNING = 'the next Apply will change %s back; freeze the file to keep '
        . 'this revert';

    private readonly WriteRun $writeRun;

    private readonly FreezeGuard $guard;

    private readonly TargetWriter $writer;

    private readonly Hasher $hasher;

    private readonly BackupCatalog $catalog;

    /**
     * @param Closure(string, string|null): void|null $checkpoint Called with a point
     *                                                            name and the staged
     *                                                            file, if any.
     */
    public function __construct(
        private readonly PackagePaths $paths,
        private readonly PathPolicy $policy,
        private readonly ObjectWalker $walker,
        private readonly ?Closure $checkpoint = null,
        ?RunRecovery $recovery = null,
    ) {
        $this->writeRun = new WriteRun($paths, 'the file was not touched', $recovery);
        $this->guard    = new FreezeGuard(new FreezeMarkers($paths));
        $this->hasher   = new Hasher();
        $this->catalog  = new BackupCatalog($paths);
        $this->writer   = new TargetWriter(
            $paths,
            $policy,
            function (string $point, ?string $staged): void {
                $this->reach($point, $staged);
            }
        );
    }

    /**
     * Put the target back to what it held before the object that wrote it.
     *
     * @param string  $targetPath The canonical root-relative target path.
     * @param RuleSet $ruleSet    The merged set, for the objects at that target.
     *
     * @throws InvalidArgumentException On a target path no validated rule can produce.
     */
    public function revert(string $targetPath, RuleSet $ruleSet): WriteOutcome
    {
        $outcome = $this->run(
            $targetPath,
            fn (array $objects): WriteOutcome => $this->stepBack($targetPath, $objects),
            $ruleSet
        );

        // Only a step that moved the file is behind the head, so only that one
        // carries the warning. A refusal changed nothing and has nothing to warn
        // about.
        return $outcome->changed
            ? $outcome->withWarning(\sprintf(self::REAPPLY_WARNING, $targetPath))
            : $outcome;
    }

    /**
     * Carry the target through the next object the rule set can apply here.
     *
     * @param string  $targetPath The canonical root-relative target path.
     * @param RuleSet $ruleSet    The merged set, walked to find the next step.
     *
     * @throws InvalidArgumentException On a target path no validated rule can produce.
     */
    public function redo(string $targetPath, RuleSet $ruleSet, EnvironmentValues $values): WriteOutcome
    {
        return $this->run(
            $targetPath,
            fn (array $objects): WriteOutcome => $this->stepForward(
                $targetPath,
                $objects,
                $ruleSet,
                $values
            ),
            $ruleSet
        );
    }

    /**
     * Take the lock, publish the marker, run the step, and close the run.
     *
     * @param Closure(list<RuleObject>): WriteOutcome $step
     */
    private function run(string $targetPath, Closure $step, RuleSet $ruleSet): WriteOutcome
    {
        return $this->writeRun->execute(
            $targetPath,
            function () use ($targetPath, $step, $ruleSet): WriteOutcome {
                $this->reach(self::LOCKED, null);

                return $this->guarded($targetPath, $step, $ruleSet);
            }
        );
    }

    /**
     * The freeze guard and the objects at the target, then the step.
     *
     * @param Closure(list<RuleObject>): WriteOutcome $step
     */
    private function guarded(string $targetPath, Closure $step, RuleSet $ruleSet): WriteOutcome
    {
        $reason = $this->guard->refusal($targetPath);

        if ($reason !== null) {
            return WriteOutcome::refused($reason);
        }

        $objects = $ruleSet->byTargetPath()[$targetPath] ?? [];

        if ($objects === []) {
            return WriteOutcome::refused(\sprintf(
                'the package has no versions of %s, so there is nothing to revert or apply',
                $targetPath
            ));
        }

        return $step($objects);
    }

    /**
     * Restore the newest backup the object that wrote the current bytes displaced.
     *
     * @param list<RuleObject> $objects
     */
    private function stepBack(string $targetPath, array $objects): WriteOutcome
    {
        $hashType = $this->hashTypeOf($objects);

        if ($hashType === null) {
            return WriteOutcome::refused($this->mixedHashTypes($targetPath));
        }

        $reason  = null;
        $current = $this->currentState($targetPath, $hashType, $reason);

        if ($current === null) {
            return WriteOutcome::refused((string) $reason);
        }

        $backups = $this->catalog->newestFirst($targetPath, $hashType, $reason);

        if ($backups === null) {
            return WriteOutcome::refused((string) $reason);
        }

        $ambiguity = null;
        $version   = $this->objectThatWrote($targetPath, $objects, $current, $backups, $hashType, $ambiguity);

        if ($version === null) {
            // A digest the rule set declares but neither payload/ nor a backup can
            // put back is not an unrecorded edit; it is content the package cannot
            // reproduce, and a step that writes no backup must not displace it.
            if ($current !== EventRecord::ABSENT && $this->declaresPayload($objects, $current)) {
                $reason = null;
                $this->isReproducible($targetPath, $objects, $hashType, $current, $reason);

                return WriteOutcome::refused((string) $reason);
            }

            if ($ambiguity !== null) {
                return WriteOutcome::refused($ambiguity);
            }

            $start = $this->startOfChain($targetPath, $objects, $current);

            return $start === null
                ? WriteOutcome::refused($this->unrecorded($targetPath, $current))
                : WriteOutcome::unchanged($start);
        }

        return $this->stepBackAtVersion($targetPath, $objects, $backups, $version, $current, $hashType);
    }

    /**
     * One step back from a known version: its newest backup, or the authored predecessor.
     *
     * @param list<RuleObject>  $objects
     * @param list<BackupEntry> $backups
     */
    private function stepBackAtVersion(
        string $targetPath,
        array $objects,
        array $backups,
        int $version,
        string $current,
        string $hashType,
    ): WriteOutcome {
        $atVersion = \array_values(\array_filter(
            $backups,
            static fn (BackupEntry $backup): bool => $backup->version === $version
        ));

        if ($atVersion !== []) {

            $guard = $this->guardReproducible($targetPath, $objects, $hashType, $current);

            if ($guard !== null) {
                return $guard;
            }

            $restore = $atVersion[0];

            return $this->writer->write(
                $targetPath,
                $restore->path,
                $hashType,
                $restore->digest,
                $current === EventRecord::ABSENT ? null : $current,
                null,
                EventRecord::REVERT
            );
        }

        $object = $this->objectAt($objects, $version);

        if ($object === null) {
            return WriteOutcome::refused(\sprintf(
                'the package has no version before %d for %s, so there is nothing earlier to '
                . 'restore',
                $version,
                $targetPath
            ));
        }

        if ($current !== EventRecord::ABSENT
            && $object === $objects[0]
            && self::createsFromNothing($object)) {
            $guard = $this->guardReproducible($targetPath, $objects, $hashType, $current);

            if ($guard !== null) {
                return $guard;
            }

            return $this->writer->remove($targetPath, $hashType, $current, null, EventRecord::REVERT);
        }

        $expected = $object->expectedHash !== null ? \strtolower($object->expectedHash) : null;

        if ($current === EventRecord::ABSENT) {
            return $this->restoreExpected(
                $targetPath,
                $objects,
                $backups,
                $object,
                $expected,
                $current,
                $hashType
            );
        }

        if ($expected === null) {
            return WriteOutcome::unchanged(\sprintf(
                '%s is at the start of its chain: version %d displaced nothing that was kept under '
                . 'backup/, so there is no earlier content to restore',
                $targetPath,
                $version
            ));
        }

        if ($expected === $current) {
            $previous = $this->previousObject($objects, $version);

            if ($previous === null) {
                return WriteOutcome::unchanged(\sprintf(
                    '%s is at the start of its chain: version %d displaced nothing that was kept under '
                    . 'backup/, so there is no earlier content to restore',
                    $targetPath,
                    $version
                ));
            }

            return $this->stepBackAtVersion(
                $targetPath,
                $objects,
                $backups,
                $previous->version,
                $current,
                $hashType
            );
        }

        return $this->restoreExpected(
            $targetPath,
            $objects,
            $backups,
            $object,
            $expected,
            $current,
            $hashType
        );
    }

    /**
     * Put back an expected original from a verified payload, or from a verified backup.
     *
     * @param list<RuleObject>  $objects
     * @param list<BackupEntry> $backups
     */
    private function restoreExpected(
        string $targetPath,
        array $objects,
        array $backups,
        RuleObject $object,
        ?string $expected,
        string $current,
        string $hashType,
    ): WriteOutcome {
        if ($expected === null) {
            return WriteOutcome::unchanged(\sprintf(
                '%s is at the start of its chain: version %d displaced nothing that was kept under '
                . 'backup/, so there is no earlier content to restore',
                $targetPath,
                $object->version
            ));
        }

        $guard = $this->guardReproducible($targetPath, $objects, $hashType, $current);

        if ($guard !== null) {
            return $guard;
        }

        foreach ($objects as $candidate) {
            foreach ($candidate->actions as $action) {
                if (!$this->payloadHolds($action, $candidate->hashType, $expected)) {
                    continue;
                }

                return $this->writer->write(
                    $targetPath,
                    $this->paths->payload() . '/' . (string) $action->source,
                    $hashType,
                    $expected,
                    $current === EventRecord::ABSENT ? null : $current,
                    null,
                    EventRecord::REVERT
                );
            }
        }

        foreach ($backups as $backup) {
            if ($backup->digest !== $expected) {
                continue;
            }

            if (!$this->hasher->hashFile($hashType, $backup->path)->matches($expected)) {
                continue;
            }

            return $this->writer->write(
                $targetPath,
                $backup->path,
                $hashType,
                $expected,
                $current === EventRecord::ABSENT ? null : $current,
                null,
                EventRecord::REVERT
            );
        }

        // The object names a predecessor. Absence of a copy under backup/ only
        // means apply kept none; it does not mean the chain has nowhere to go.
        // Without a verified payload or backup holding those bytes, the step
        // cannot proceed and must not be reported as chain exhaustion.
        return WriteOutcome::refused(\sprintf(
            '%s names predecessor %s at version %d, but neither a verified payload nor a verified '
            . 'backup can supply those bytes, so the chain cannot step back',
            $targetPath,
            $expected,
            $object->version
        ));
    }

    /**
     * Refuse a step that would destroy the only intact copy of the live bytes.
     *
     * @param list<RuleObject> $objects
     */
    private function guardReproducible(
        string $targetPath,
        array $objects,
        string $hashType,
        string $current,
    ): ?WriteOutcome {
        if ($current === EventRecord::ABSENT) {
            return null;
        }

        $reason = null;

        if ($this->isReproducible($targetPath, $objects, $hashType, $current, $reason)) {
            return null;
        }

        return WriteOutcome::refused((string) $reason);
    }

    /**
     * Perform the first operation the walk proposes at this target, and no more.
     *
     * @param list<RuleObject> $objects
     */
    private function stepForward(
        string $targetPath,
        array $objects,
        RuleSet $ruleSet,
        EnvironmentValues $values,
    ): WriteOutcome {
        $hashType = $this->hashTypeOf($objects);

        if ($hashType === null) {
            return WriteOutcome::refused($this->mixedHashTypes($targetPath));
        }

        $state = $this->stateOf($targetPath, $ruleSet, $values);

        if ($state === null) {
            return WriteOutcome::refused(\sprintf(
                'the check returned nothing for %s, so there is nothing to revert or apply',
                $targetPath
            ));
        }

        if ($state->status === TargetStatus::FAULTED) {
            return WriteOutcome::refused(\sprintf(
                '%s cannot be stepped forward: %s',
                $targetPath,
                $state->reason
            ));
        }

        $operations = $state->operations();

        if ($operations === []) {
            return WriteOutcome::unchanged(\sprintf(
                '%s is at the head of its chain: %s',
                $targetPath,
                $state->reason
            ));
        }

        // The walk's own reading of the file, not a second one taken here. The walk
        // proposed this operation because those bytes matched an object's expected
        // original, and TargetWriter refuses the move unless the file still matches
        // them. Re-hashing here instead would hand the writer whatever the file
        // holds now, and a change since the walk would then be written over as
        // though the walk had judged it.
        $current = $state->actual->isAbsent()
            ? EventRecord::ABSENT
            : \strtolower((string) $state->actual->digest);

        return $this->perform($targetPath, $objects, $operations[0], $hashType, $current);
    }

    /**
     * One proposed operation, with the reproducibility guard redo needs.
     *
     * @param list<RuleObject> $objects
     */
    private function perform(
        string $targetPath,
        array $objects,
        ProposedOperation $operation,
        string $hashType,
        string $current,
    ): WriteOutcome {
        if ($current !== EventRecord::ABSENT) {
            $reason = null;

            if (!$this->isReproducible($targetPath, $objects, $hashType, $current, $reason)) {
                return WriteOutcome::refused((string) $reason);
            }
        }

        if (!$operation->isCopy()) {
            if ($current === EventRecord::ABSENT) {
                return WriteOutcome::unchanged(\sprintf(
                    '%s is already absent, so the removal at version %d has nothing to step through',
                    $targetPath,
                    $operation->version
                ));
            }

            return $this->writer->remove($targetPath, $hashType, $current, null, EventRecord::REDO);
        }

        return $this->writer->write(
            $targetPath,
            $this->paths->payload() . '/' . (string) $operation->payloadSource,
            $hashType,
            (string) $operation->payloadHash,
            $current === EventRecord::ABSENT ? null : $current,
            null,
            EventRecord::REDO
        );
    }

    /**
     * The target's state for this chain: absent, or its digest under $hashType.
     *
     * @return string|null The state, or null with $reason set.
     */
    private function currentState(string $targetPath, string $hashType, ?string &$reason): ?string
    {
        if (!HashAlgorithm::isSupported($hashType)) {
            $reason = HashAlgorithm::unsupportedReason($hashType);

            return null;
        }

        $decision = $this->policy->decide($targetPath);

        if (!$decision->allowed) {
            $reason = (string) $decision->reason;

            return null;
        }

        $result = $this->hasher->hashFile($hashType, (string) $decision->path);

        if ($result->isAbsent()) {
            return EventRecord::ABSENT;
        }

        if (!$result->isAvailable()) {
            $reason = \sprintf(
                'the bytes at %s could not be read (%s), so the package cannot say where the chain '
                . 'stands there',
                $targetPath,
                $result->describe()
            );

            return null;
        }

        return \strtolower((string) $result->digest);
    }

    /**
     * The version of the object that wrote the state the target now holds.
     *
     * A declared sourceHash alone is not a match: the payload file has to hash to
     * the live bytes, or a verified backup of this target already has to hold
     * them. Otherwise the live target is the only intact copy.
     *
     * @param list<RuleObject>  $objects
     * @param list<BackupEntry> $backups
     * @param string|null       $ambiguity Set when absence has more than one history.
     */
    private function objectThatWrote(
        string $targetPath,
        array $objects,
        string $current,
        array $backups,
        string $hashType,
        ?string &$ambiguity,
    ): ?int {
        if ($current === EventRecord::ABSENT) {
            return $this->removalThatWrote($targetPath, $objects, $ambiguity);
        }

        $matching = [];

        foreach ($objects as $object) {
            foreach ($object->actions as $action) {
                $wrote = $action->isCopy()
                    && \strtolower((string) $action->sourceHash) === $current
                    && ($this->payloadHolds($action, $hashType, $current)
                        || $this->verifiedBackupHolds($backups, $hashType, $current));

                if ($wrote) {
                    $matching[] = $object->version;
                    break;
                }
            }
        }

        if ($matching === []) {
            return null;
        }

        $wanted = \array_fill_keys($matching, true);

        foreach ($backups as $backup) {
            if (isset($wanted[$backup->version])) {
                return $backup->version;
            }
        }

        return $matching[\array_key_last($matching)];
    }

    /**
     * The removal that took the file away, when only one of them can have.
     *
     * Present bytes identify their writer, because a payload digest names the
     * object that shipped it and the backup rank settles the objects that share
     * one. Absence carries none of that. Every removal at a target leaves exactly
     * the same nothing, and so does a site the package has never written to, so
     * the tree alone cannot say which removal ran or whether any of them did.
     *
     * @param list<RuleObject> $objects
     */
    private function removalThatWrote(string $targetPath, array $objects, ?string &$ambiguity): ?int
    {
        $removals = [];

        foreach ($objects as $object) {
            foreach ($object->actions as $action) {
                if ($action->isRemove()) {
                    $removals[] = $object->version;
                    break;
                }
            }
        }

        if ($removals === [] || self::createsFromNothing($objects[0])) {
            return null;
        }

        if (\count($removals) === 1) {
            return $removals[0];
        }

        $ambiguity = \sprintf(
            'no file is at %s, and versions %s each remove one, so the package cannot say which of '
            . 'them left it absent or what preceded that; nothing was reverted',
            $targetPath,
            \implode(', ', $removals)
        );

        return null;
    }

    /**
     * @param list<RuleObject> $objects
     */
    private function objectAt(array $objects, int $version): ?RuleObject
    {
        foreach ($objects as $object) {
            if ($object->version === $version) {
                return $object;
            }
        }

        return null;
    }

    /**
     * @param list<RuleObject> $objects
     */
    private function previousObject(array $objects, int $version): ?RuleObject
    {
        $previous = null;

        foreach ($objects as $object) {
            if ($object->version === $version) {
                return $previous;
            }

            $previous = $object;
        }

        return null;
    }

    /**
     * The target sits where the chain begins, so there is nothing before it.
     *
     * @param list<RuleObject> $objects
     *
     * @return string|null The account for the report, or null when the bytes are
     *                     not a state the rule set names at all.
     */
    private function startOfChain(string $targetPath, array $objects, string $current): ?string
    {
        if ($current === EventRecord::ABSENT && self::createsFromNothing($objects[0])) {
            return \sprintf(
                '%s is at the start of its chain: no file is there, and version %d is the object '
                . 'that creates one, so the rule set records nothing before that',
                $targetPath,
                $objects[0]->version
            );
        }

        foreach ($objects as $object) {
            if ($object->expectedHash !== null && \strtolower($object->expectedHash) === $current) {
                return \sprintf(
                    '%s is at the start of its chain: it holds the expected original version %d '
                    . 'declares, and the rule set records nothing before that',
                    $targetPath,
                    $object->version
                );
            }
        }

        return null;
    }

    /**
     * Could this object only have written where the site had no file?
     *
     * Asked of the first object at a target, which is the only one whose predecessor
     * can be absence: every later object wrote over what the one before it left. An
     * object that declares an expected original is out too, because it wrote over
     * that original and preserved it, so it has somewhere to step back to.
     */
    private static function createsFromNothing(RuleObject $object): bool
    {
        return $object->create && $object->expectedHash === null;
    }

    /**
     * An edit somebody made on the server. A revert over it would be the only thing
     * that ever destroyed it, so it stops here.
     */
    private function unrecorded(string $targetPath, string $current): string
    {
        return \sprintf(
            '%s holds %s, which is neither a payload the rule set ships nor an expected original '
            . 'it declares, so it carries an unrecorded change and was not reverted',
            $targetPath,
            $current === EventRecord::ABSENT ? 'no file' : $current
        );
    }

    /**
     * Can the package put these bytes back after a redo displaces them?
     *
     * @param list<RuleObject> $objects
     */
    private function isReproducible(
        string $targetPath,
        array $objects,
        string $hashType,
        string $current,
        ?string &$reason,
    ): bool {
        foreach ($objects as $object) {
            foreach ($object->actions as $action) {
                if ($this->payloadHolds($action, $hashType, $current)) {
                    return true;
                }
            }
        }

        $backups = $this->catalog->newestFirst($targetPath, $hashType, $reason, true);

        if ($backups === null) {
            return false;
        }

        foreach ($backups as $backup) {
            if ($backup->digest === $current) {
                return true;
            }
        }

        $reason = \sprintf(
            '%s holds %s, which is neither a payload the rule set ships nor content a backup already '
            . 'holds, and a step that writes no backup cannot displace it. Apply preserves what it '
            . 'displaces',
            $targetPath,
            $current
        );

        return false;
    }

    /**
     * Does any object declare $digest as a payload hash, regardless of the file?
     *
     * @param list<RuleObject> $objects
     */
    private function declaresPayload(array $objects, string $digest): bool
    {
        foreach ($objects as $object) {
            foreach ($object->payloadHashes() as $hash) {
                if (\strtolower($hash) === $digest) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Does this copy action's payload file still hold $digest?
     */
    private function payloadHolds(RuleAction $action, string $hashType, string $digest): bool
    {
        if (!$action->isCopy() || $action->source === null || $action->sourceHash === null) {
            return false;
        }

        if (\strtolower($action->sourceHash) !== $digest) {
            return false;
        }

        return $this->hasher->hashFile(
            $hashType,
            $this->paths->payload() . '/' . $action->source
        )->matches($digest);
    }

    /**
     * Does a listed backup still hash to $digest?
     *
     * @param list<BackupEntry> $backups
     */
    private function verifiedBackupHolds(array $backups, string $hashType, string $digest): bool
    {
        foreach ($backups as $backup) {
            if ($backup->digest !== $digest) {
                continue;
            }

            if ($this->hasher->hashFile($hashType, $backup->path)->matches($digest)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The walk's state for one target, or null when it reported none.
     */
    private function stateOf(string $targetPath, RuleSet $ruleSet, EnvironmentValues $values): ?TargetState
    {
        foreach ($this->walker->walk($ruleSet, $values) as $state) {
            if ($state->targetPath === $targetPath) {
                return $state;
            }
        }

        return null;
    }

    /**
     * The one algorithm every object at the target declares, or null when they differ.
     *
     * @param list<RuleObject> $objects
     */
    private function hashTypeOf(array $objects): ?string
    {
        $hashType = $objects[0]->hashType;

        foreach ($objects as $object) {
            if ($object->hashType !== $hashType) {
                return null;
            }
        }

        return $hashType;
    }

    private function mixedHashTypes(string $targetPath): string
    {
        return \sprintf(
            'the objects at %s declare more than one hash type, so their hashes cannot be compared '
            . 'and the chain has no order to step along',
            $targetPath
        );
    }

    private function reach(string $point, ?string $staged): void
    {
        if ($this->checkpoint !== null) {
            ($this->checkpoint)($point, $staged);
        }
    }
}
