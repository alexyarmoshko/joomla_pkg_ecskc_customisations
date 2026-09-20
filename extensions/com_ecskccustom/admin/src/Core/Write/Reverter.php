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
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BoundBackupDirectory;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventRecord;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\FreezeMarkers;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ObjectWalker;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ProposedOperation;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetState;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;

/**
 * One step back or one step forward along a target's chain, work items 15 and 16.
 *
 * Brief decision 4: revert moves back one object, redo moves forward one object,
 * and neither writes a backup. Both restore through TargetWriter, so a step is the
 * same staged, verified, atomic move an apply makes, under decision 28's mode rule.
 *
 * ## The chain has two different shapes, so the two directions read it differently
 *
 * Backwards is a question about bytes that already exist. The file holds a payload
 * the package shipped; the object that shipped it left the displaced content under
 * `backup/<target>.v<version>.<digest>`; restoring that file is the step. Nothing
 * about the environment comes into it, because the bytes were written under
 * whatever conditions held at the time and the digest is what identifies them now.
 *
 * Forwards is the same question apply asks, so redo asks it the same way: it takes
 * the first operation ObjectWalker proposes for the target and performs that one
 * alone. The enable flag, the conditions, the object permissions and the expected
 * original are then settled in one place rather than two, and a redo cannot
 * disagree with the apply that follows it.
 *
 * ## No step proceeds from content the package cannot name
 *
 * Work item 16. Revert refuses when the file matches neither a payload the rule set
 * ships nor an expected original it declares, because bytes nothing recorded are an
 * edit somebody made on the server, and restoring over them would destroy the only
 * copy.
 *
 * Redo needs the same guarantee from the other side. It writes no backup, so the
 * content it displaces has to be reproducible: a shipped payload, or bytes already
 * preserved under one of this target's backup names. Anything else is refused and
 * sent to apply, which backs up what it displaces.
 *
 * ## A write run of its own
 *
 * Decision 30. Each call takes `run.lock` itself and publishes the run marker
 * through WriteRun, the way Freezer does, and the runner does not wrap it in a
 * second acquire. Revert is the more dangerous of the two, since it moves a file
 * backwards while another run may be reading it as current, and the lock is what
 * keeps freeze and unfreeze from returning while it holds.
 *
 * A leftover write intent or a run marker found under the lock belongs to a run that
 * died, and settling it is ticket 34's recovery. Both refuse before this run
 * replaces the marker, because publishing a new intent over the old one would
 * destroy the only evidence of that mutation.
 *
 * FreezeGuard is consulted under the lock, so a freeze that has returned excludes a
 * step that had not yet started.
 *
 * ## Backwards is read from bytes, then from backups
 *
 * Several objects may ship one payload, as NTS KBee topic-edit v1 and v2 do. The
 * file's digest then names more than one version. Decision 43 ranks every backup
 * of a target, so among the objects whose payload the file holds, the version of
 * the newest matching backup is the step, and the newest backup at that version
 * is what is restored. Rule order alone is the wrong step when a later write of
 * the same bytes left the newer backup under an earlier version.
 *
 * A declared `sourceHash` is not enough to treat the live bytes as reproducible.
 * The matching payload file under `payload/` has to hash to them, or a verified
 * backup of this target already has to hold them. Otherwise the live target is
 * the only intact copy, and a step that writes no backup would destroy it.
 *
 * The other end of a chain is absence. A package-owned customisation starts with
 * an object that creates the file where the vendor ships none, and that object
 * displaced nothing, so it left no backup: putting it back means taking the file
 * away again. Decision 4 is what makes that readable from the tree, because an
 * apply preserves everything it overwrites, so a version with no backup at its
 * target overwrote nothing. An absent target whose chain begins that way has run
 * out of chain rather than lost its file.
 *
 * A version can also leave no backup when the mutation displaced nothing kept:
 * a no-copy removal, or a later package-owned revision that ships the same bytes
 * it expected. The authored predecessor is then the step: restore the expected
 * original from a verified payload, or walk back through a content-identical
 * revision to the create that can still be taken away. Naming a predecessor and
 * finding neither a verified payload nor a verified backup that holds it is a
 * fault, not the start of the chain: the object still has somewhere to step
 * back to, and the package cannot put those bytes back.
 *
 * Absence is the one state that does not identify what wrote it. Present bytes
 * carry a digest, and the digest names the object that shipped them; every
 * removal at a target leaves the same nothing, and so does a site the package
 * has never touched. Decision 46: absence is attributed to a removal only where
 * one removal is the only history it can have. A chain that begins by creating
 * the file, and a target with more than one removal, are both read without
 * guessing - the first as decision 42's start of the chain, the second as a
 * refusal - because the alternative is creating a file the vendor ships none of,
 * or restoring a predecessor this state never had.
 *
 * A restored backup is held to the digest in its own name, because TargetWriter
 * hashes the staged copy before it moves it. Ticket 30 makes that name a claim
 * rather than proof, and this is where the claim is tested. Redo hashes each
 * candidate through the bound backup path before treating it as content the
 * package already holds: a corrupt file under a matching name is not a copy it
 * can put back.
 *
 * ## The re-apply warning belongs to the report
 *
 * Decision 19 and brief section 8. A revert leaves the target behind the head the
 * rule set declares, and the next apply carries it forward again unless the operator
 * freezes the path. Version 1 does not freeze on its own, so the outcome carries the
 * sentence and the report shows it. The write does nothing about it.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
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

    /** What a successful revert tells the operator, decision 19. */
    public const REAPPLY_WARNING = 'the next apply carries %s forward to the head again; '
        . 'freeze the path to hold this revert';

    private readonly WriteRun $writeRun;

    private readonly FreezeGuard $guard;

    private readonly TargetWriter $writer;

    private readonly Hasher $hasher;

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
    ) {
        $this->writeRun = new WriteRun($paths, 'the target was not touched');
        $this->guard    = new FreezeGuard(new FreezeMarkers($paths));
        $this->hasher   = new Hasher();
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
                'the rule set declares no object at %s, so it has no chain to step along',
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

        $backups = $this->backupsAt($targetPath, $hashType, $reason);

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

            // Absence several removals could each have written names a state the
            // rule set declares, so it is not an unrecorded edit and not the start
            // of a chain either. Neither account fits, and guessing one mutates.
            if ($ambiguity !== null) {
                return WriteOutcome::refused($ambiguity);
            }

            $start = $this->startOfChain($targetPath, $objects, $current);

            // Two different answers. A file sitting at a declared expected original
            // has simply run out of chain, and an operator has nothing to fix. Bytes
            // nothing declares are a fault about the site, work item 16.
            return $start === null
                ? WriteOutcome::refused($this->unrecorded($targetPath, $current))
                : WriteOutcome::unchanged($start);
        }

        return $this->stepBackAtVersion($targetPath, $objects, $backups, $version, $current, $hashType);
    }

    /**
     * One step back from a known version: its newest backup, or the authored predecessor.
     *
     * @param list<RuleObject>                                        $objects
     * @param list<array{path: string, digest: string, version: int}> $backups
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
            static fn (array $backup): bool => $backup['version'] === $version
        ));

        if ($atVersion !== []) {
            // Decision 4: the newest of several backups at one version. BackupStore
            // ranks the ones it publishes, so a tie is only reachable through files an
            // operator put there by hand, and name order settles those the same way on
            // every run.
            $guard = $this->guardReproducible($targetPath, $objects, $hashType, $current);

            if ($guard !== null) {
                return $guard;
            }

            $restore = $atVersion[0];

            return $this->writer->write(
                $targetPath,
                $restore['path'],
                $hashType,
                $restore['digest'],
                $current === EventRecord::ABSENT ? null : $current,
                null,
                EventRecord::REVERT
            );
        }

        $object = $this->objectAt($objects, $version);

        if ($object === null) {
            return WriteOutcome::refused(\sprintf(
                'the rule set declares no object at version %d for %s, so the chain has no '
                . 'predecessor to restore',
                $version,
                $targetPath
            ));
        }

        // A create put these bytes where there was no file, so putting the target
        // back means taking the file away.
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

        // A no-copy removal left absence and kept no backup. The expected original is
        // the authored predecessor; restore it from a verified payload when one ships it.
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

        // A later package-owned revision that ships the same bytes it expected left no
        // distinct backup of its own. Undoing it is a content no-op; the previous object
        // is the real step.
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
     * @param list<RuleObject>                                        $objects
     * @param list<array{path: string, digest: string, version: int}> $backups
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
            if ($backup['digest'] !== $expected) {
                continue;
            }

            if (!$this->hasher->hashFile($hashType, $backup['path'])->matches($expected)) {
                continue;
            }

            return $this->writer->write(
                $targetPath,
                $backup['path'],
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
                'the walk reported nothing about %s, so there is no step to take there',
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
     * Several objects may ship one payload. The backups of this target already
     * carry a strict newest-first rank, so among the objects whose verified
     * payload the file holds, the version of the newest matching backup is the
     * one that actually wrote last. When none of them left a backup, the last
     * matching object is kept so a payload with no backup is still a named state
     * rather than an unrecorded edit, and stepBackAtVersion reads the authored
     * predecessor from there.
     *
     * A declared sourceHash alone is not a match: the payload file has to hash to
     * the live bytes, or a verified backup of this target already has to hold
     * them. Otherwise the live target is the only intact copy.
     *
     * Absence is a state an object writes too, which is how a removal is stepped
     * back through, but it is read by removalThatWrote rather than here: absent
     * bytes carry nothing that tells the removals at a target apart.
     *
     * @param list<RuleObject>                                        $objects
     * @param list<array{path: string, digest: string, version: int}> $backups
     * @param string|null                                             $ambiguity Set when absence
     *                                                                           has more than one
     *                                                                           history.
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
            if (isset($wanted[$backup['version']])) {
                return $backup['version'];
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
     * Two shapes are therefore not attributed at all:
     *
     * - A chain that begins by creating the file. Its pristine state is absence
     *   too, and decision 42 reads an absent target there as the start of the
     *   chain rather than as a removal's work. Stepping back would put a file
     *   where the vendor ships none and the package never wrote one.
     * - More than one removal at the target. A backup under one of their versions
     *   proves only that that version ran at some point, not that it wrote the
     *   absence the target holds now, and a later no-copy removal leaves nothing
     *   to weigh against it. Restoring the wrong one's predecessor would report
     *   success while putting back bytes that never preceded this state.
     *
     * The second is a refusal, because absence is a state those removals do
     * declare; the first falls through to startOfChain, which has the decision 42
     * account for it. Only an unambiguous single removal is a step.
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
     * An expected original is a state the rule set names but no object of it wrote,
     * which is what the start of a chain looks like from the bytes alone. Absence is
     * the same answer for a chain whose first object creates the file: the site had
     * none, the package made one, and a target back at none has run out of chain.
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
     *
     * What is left is `create="true"` with nothing expected, which is how every
     * package-owned customisation in `data/rules/` is written: a file in a folder
     * the vendor ships nothing in. Without this the five of them can be carried
     * forward from absence and never walked back to it.
     *
     * `replace` is not consulted. It defaults to true, so the objects this has to
     * recognise all carry it, and what rules the replacement out is decision 4: an
     * apply preserves whatever it overwrites, so a version with no backup at this
     * target overwrote nothing.
     */
    private static function createsFromNothing(RuleObject $object): bool
    {
        return $object->create && $object->expectedHash === null;
    }

    /**
     * Bytes nothing in the rule set names, work item 16.
     *
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
     * Redo writes no backup, decision 4, so it may only displace content that is
     * already reproducible. A shipped payload whose file still hashes to the live
     * digest is, and so is content a backup of this target already holds. The
     * backup is re-hashed through the bound path rather than trusted for its name,
     * on ticket 30's grounds. A declared sourceHash alone is not enough: a missing
     * or damaged payload file would leave the live target as the only intact copy.
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

        $backups = $this->backupsAt($targetPath, $hashType, $reason, true);

        if ($backups === null) {
            return false;
        }

        foreach ($backups as $backup) {
            if ($backup['digest'] === $current) {
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
     * @param list<array{path: string, digest: string, version: int}> $backups
     */
    private function verifiedBackupHolds(array $backups, string $hashType, string $digest): bool
    {
        foreach ($backups as $backup) {
            if ($backup['digest'] !== $digest) {
                continue;
            }

            if ($this->hasher->hashFile($hashType, $backup['path'])->matches($digest)) {
                return true;
            }
        }

        return false;
    }

    /**
     * This target's backups, newest first.
     *
     * The directory is opened from `backup/` down, so a symbolic link swapped into
     * an ancestor does not decide which names a revert can see, and each entry is
     * checked at its last component, because a link there points at bytes outside
     * the package tree. Names go through PackagePaths::parseBackupName(), so a name
     * BackupStore could not have written is not a backup.
     *
     * A target with no mirrored directory has no backups, and that is an answer. Not
     * being able to look is not: a symbolic link at a segment, a file where the
     * directory should be, or a directory that cannot be listed or bound all refuse
     * the step rather than report a chain with nothing behind it.
     *
     * The paths returned are logical ones all the same. A descriptor in
     * `/proc/self/fd` dies with the handle, and the handle closes here while the
     * restore happens in TargetWriter, so carrying one out of this method would hand
     * the writer a path that no longer resolves. What protects the restore is not
     * the descriptor: TargetWriter stages a copy and hashes it against the digest in
     * the name before it moves anything, so a backup swapped or edited after this
     * listing stops the revert there. Ticket 30 makes that name a claim, and this is
     * where the claim is tested.
     *
     * Redo asks for $requireHashMatch. It writes no backup of what it displaces, so
     * a name whose bytes do not hash to it is not content the package already holds.
     *
     * @return list<array{path: string, digest: string, version: int}>|null
     *                          Null with $reason set when they could not be listed.
     */
    private function backupsAt(
        string $targetPath,
        string $hashType,
        ?string &$reason,
        bool $requireHashMatch = false,
    ): ?array {
        $length = HashAlgorithm::digestLength($hashType);

        if ($length === null) {
            $reason = HashAlgorithm::unsupportedReason($hashType);

            return null;
        }

        $missing = false;
        $bound   = BoundBackupDirectory::open($this->paths, $targetPath, false, missing: $missing);

        if (\is_string($bound)) {
            if ($missing) {
                // No mirrored directory is no backup, which is an answer rather than
                // a fault: a target nothing has ever displaced has none.
                return [];
            }

            // Anything else means the backups could not be looked at. Reading that
            // as an empty listing would report a clean start of chain over state
            // the package never saw, and a step back is not taken on a guess.
            $reason = \sprintf(
                'the backups of %s could not be inspected: %s',
                $targetPath,
                $bound
            );

            return null;
        }

        try {
            $entries = @\scandir($bound->proc);

            if ($entries === false) {
                $reason = \sprintf(
                    'the backups of %s could not be listed, so the package cannot say what to '
                    . 'restore there',
                    $targetPath
                );

                return null;
            }

            $directory = \dirname($this->paths->backup() . '/' . $targetPath);
            $found     = [];

            foreach ($entries as $entry) {
                $parsed = PackagePaths::parseBackupName($entry, $targetPath);

                if ($parsed === null || \strlen($parsed['digest']) !== $length) {
                    continue;
                }

                $path = $bound->child($entry);
                \clearstatcache(true, $path);

                if (\is_link($path) || !\is_file($path)) {
                    continue;
                }

                if ($requireHashMatch && !$this->hasher->hashFile($hashType, $path)->matches($parsed['digest'])) {
                    continue;
                }

                $found[] = [
                    'path'     => $directory . '/' . $entry,
                    'digest'   => $parsed['digest'],
                    'version'  => $parsed['version'],
                    'modified' => (int) @\filemtime($path),
                    'name'     => $entry,
                ];
            }
        } finally {
            $bound->close();
        }

        \usort(
            $found,
            static fn (array $left, array $right): int => [$right['modified'], $right['name']]
                <=> [$left['modified'], $left['name']]
        );

        return \array_map(
            static fn (array $backup): array => [
                'path'    => $backup['path'],
                'digest'  => $backup['digest'],
                'version' => $backup['version'],
            ],
            $found
        );
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
