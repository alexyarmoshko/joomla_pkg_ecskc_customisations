<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition\ConditionEvaluator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentValues;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\HashResult;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\Hasher;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path\PathPolicy;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleAction;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleObject;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleSet;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\FreezeMarkers;

/**
 * One forward pass over the cumulative objects at each target, producing the state
 * of that target and the operations that would move it forward.
 *
 * **Nothing here writes.** It resolves, hashes and reads a freeze marker. Phase 1
 * has no execution at all, and the operations this proposes are a description of
 * work, not a queue.
 *
 * ## The walk
 *
 * Brief 5.2: a run makes one forward pass, reads each object's expectations in
 * order, and never revisits an object. So the walk carries one simulated state per
 * target - the digest the file holds, or its absence - and each object either moves
 * that state on or explains why it could not. The state starts as the bytes actually
 * on disk, which is what makes decision 13 work: a customisation somebody applied by
 * hand years before this package existed is recognised the same as one it wrote.
 *
 * The rule set does not say whether a target is vendor-owned or package-owned, and
 * this walk does not need to know. Several objects at one path are a chain when a
 * later one expects what an earlier one writes, and alternatives when they expect
 * different originals. Both fall out of comparing the state against the two hashes
 * each object declares.
 *
 * ## Why a status can be `incomplete` rather than `blocked`
 *
 * Both mean the target is not at the head, and they send an operator to different
 * places: `blocked` means somebody changed the file, `incomplete` means the rule set
 * could not carry it. The walk separates them by whether an object was **skipped
 * while it could have advanced**.
 *
 * - A disabled object gates when a later object expects the state it would have
 *   produced. That is the shape that strands a fresh install, which walks as far as
 *   the gap and stops, and the only shape the build warns about. Nothing else does:
 *   brief 5.2 calls disabling the head of a chain, or one of several vendor
 *   alternatives, exactly the intended use, so those switch a customisation off and
 *   report `ok` or `not applicable` rather than a gap in the rule set.
 * - Conditions that do not hold gate on the same test, and only once the walk has
 *   advanced. On the first object they mean the customisation does not apply on this
 *   host, which is the ordinary reading of a `HOST` exclusion. Brief section 6
 *   records the Community Builder customisation excluded from production; it should
 *   read as `not applicable` there, every run, forever.
 * - An object the walk judged and could not use gates once the walk has advanced and
 *   the object is a step of the same chain, which `stopsTheChainShort()` decides.
 *   Reaching an old payload and finding the next revision out of reach is exactly
 *   what `incomplete` describes, and reporting it as `ok` would call a target done
 *   at a revision the rule set has already moved past.
 *
 * Every one of those is a barrier only while it still holds. A rule set may link an
 * object to any earlier payload, so a later object can reach the head over a step the
 * walk could not use, and a gate the walk gets past is cleared from the status and
 * from the object's own line in the report.
 *
 * A skip that gates nothing is not a barrier at all. It is the answer: the newest
 * revision is switched off, or written for somewhere else, and the file already holds
 * what the rule set writes here. The status is then `ok`, and `settle()` explains how
 * that stays readable as `ok (disabled)` and `ok (unmet)`.
 *
 * A target reaches `blocked` only when nothing was skipped that could have advanced
 * and an object's declared expected original matched neither the file nor a payload.
 * That keeps `blocked` meaning what section 7.1 needs it to mean: rebuild the
 * payload against the vendor's new file.
 *
 * ## A fault stops the target and proposes nothing
 *
 * A condition that could not be judged, a target that cannot be read, a path policy
 * refusal: each halts that target and clears whatever earlier objects had proposed.
 * Ticket 13's third acceptance clause is the reason the clearing is explicit. The
 * evaluator has no filesystem and can only answer that the outcome faulted; that the
 * file is then left alone has to be guaranteed from here, and is asserted against a
 * temporary tree in this class's tests.
 *
 * One target's fault says nothing about the next, so the walk carries on to the
 * other paths.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class ObjectWalker
{
    public function __construct(
        private readonly PathPolicy $pathPolicy,
        private readonly Hasher $hasher,
        private readonly ConditionEvaluator $conditions,
        private readonly FreezeMarkers $freezeMarkers,
    ) {
    }

    /**
     * One state per target path, in the order the merged rule set first names each.
     *
     * @return list<TargetState>
     */
    public function walk(RuleSet $ruleSet, EnvironmentValues $values): array
    {
        $states = [];

        foreach ($ruleSet->byTargetPath() as $targetPath => $objects) {
            $state = $this->walkTarget((string) $targetPath, $objects, $values);

            if ($this->freezeMarkers->isFrozen((string) $targetPath)) {
                // Decision 19: the freeze skips the write and keeps the report, so
                // the state it would otherwise have is computed first and kept.
                $state = TargetState::frozen($state);
            }

            $states[] = $state;
        }

        return $states;
    }

    /**
     * @param list<RuleObject> $objects
     */
    private function walkTarget(string $targetPath, array $objects, EnvironmentValues $values): TargetState
    {
        $decision = $this->pathPolicy->decide($targetPath);

        if (!$decision->allowed) {
            return $this->faultedTarget(
                $targetPath,
                \sprintf('path policy refused this target: %s', (string) $decision->reason)
            );
        }

        $algorithm = $objects[0]->hashType;
        $actual    = $this->hasher->hashFile($algorithm, (string) $decision->path);

        if (!$actual->isAvailable() && !$actual->isAbsent()) {
            $reason = \sprintf('the target could not be read: %s', (string) $actual->reason);

            return TargetState::of(
                $targetPath,
                TargetStatus::FAULTED,
                $reason,
                HashResult::unavailable($reason),
                $actual
            );
        }

        $ctx = new WalkContext($actual, $objects);

        foreach ($objects as $index => $object) {
            if ($object->hashType !== $algorithm) {
                return $this->faultedTarget($targetPath, \sprintf(
                    '%s declares hash type %s where an earlier object at this target declares %s, '
                    . 'so the two cannot be compared',
                    $object->label(),
                    $object->hashType,
                    $algorithm
                ), $ctx->outcomes, $actual);
            }

            if (!$object->enable) {
                $this->walkDisabledObject($object, $objects, $index, $ctx);

                continue;
            }

            $selection = $this->chooseAction($object, $values, $ctx);

            if ($selection->isFaulted()) {
                return $this->faultedTarget(
                    $targetPath,
                    (string) $selection->faultReason,
                    $ctx->outcomes,
                    $actual
                );
            }

            if ($selection->isUnmet()) {
                continue;
            }

            /** @var RuleAction $chosen */
            $chosen = $selection->action;

            [$result, $reason] = $this->judge($object, $chosen, $ctx->state);

            $ctx->outcomes[] = ObjectOutcome::for($object, $result, $reason);

            $this->advanceWalk($object, $chosen, $result, $reason, $index, $ctx);
        }

        [$status, $reason, $qualifier] = $this->settle(
            $ctx->settled,
            $ctx->operations,
            $ctx->pendingGates !== [],
            $ctx->skipQualifier,
            $ctx->skipReason,
            $ctx->refusedByPermit,
            $ctx->sawNotMatched
        );

        return TargetState::of(
            $targetPath,
            $status,
            $reason,
            $ctx->expected ?? HashResult::unavailable(
                'no object at this target was measured against the file, so the rule set '
                . 'declares no hash to expect here'
            ),
            $actual,
            $ctx->outcomes,
            $ctx->operations,
            $qualifier
        );
    }

    /**
     * Handle a disabled object: record the outcome, decide whether it gates later
     * objects, and update the skip qualifier when the object could have advanced.
     *
     * @param list<RuleObject> $objects
     */
    private function walkDisabledObject(
        RuleObject $object,
        array $objects,
        int $index,
        WalkContext $ctx,
    ): void {
        $reachable = $this->couldAdvance($object, $ctx->state);

        $gates = $reachable && $this->gatesALaterChainStep($object, $objects, $index);

        if ($gates) {
            $reason = 'the object is disabled, and a later object at this target expects '
                . 'a state it would have produced';
        } else {
            $reason = 'the object is disabled';

            if ($reachable) {
                $ctx->skipQualifier = TargetState::DISABLED;
                $ctx->skipReason    = \sprintf(
                    'version %d matches this target and is switched off, so the rule set '
                    . 'writes nothing further here',
                    $object->version
                );
            }
        }

        $ctx->outcomes[] = ObjectOutcome::for($object, ObjectOutcome::DISABLED, $reason, $gates);

        if ($gates) {
            $ctx->pendingGates[] = \array_key_last($ctx->outcomes);
        }
    }

    /**
     * Evaluate conditions across the object's actions and select the first matching
     * one, or handle the faulted / conditions-unmet cases in place.
     */
    private function chooseAction(
        RuleObject $object,
        EnvironmentValues $values,
        WalkContext $ctx,
    ): ActionSelection {
        $chosen = null;
        $faults = [];

        foreach ($object->actions as $action) {
            $outcome = $this->conditions->evaluate($action->conditions, $values);

            if ($outcome->isFaulted()) {
                $faults = [...$faults, ...$outcome->reasons];

                continue;
            }

            if ($chosen === null && $outcome->isMatched()) {
                $chosen = $action;
            }
        }

        if ($faults !== []) {
            $reason          = \sprintf('a condition could not be judged: %s', \implode('; ', $faults));
            $ctx->outcomes[] = ObjectOutcome::for($object, ObjectOutcome::FAULTED, $reason);

            return ActionSelection::faulted($reason);
        }

        if ($chosen === null) {
            $this->recordConditionsUnmet($object, $ctx);

            return ActionSelection::unmet();
        }

        return ActionSelection::selected($chosen);
    }

    /**
     * An object whose conditions did not hold: record the outcome and decide whether
     * the skip gates a later chain step or simply qualifies the status.
     */
    private function recordConditionsUnmet(RuleObject $object, WalkContext $ctx): void
    {
        $reachable = $this->couldAdvance($object, $ctx->state);

        $gates = $reachable
            && $ctx->settled !== null
            && $this->gatesALaterChainStep($object, $ctx->objects, $ctx->indexOf($object));

        $ctx->outcomes[] = ObjectOutcome::for(
            $object,
            ObjectOutcome::CONDITIONS_UNMET,
            'no action of this object has conditions that hold in this environment',
            $gates
        );

        if ($gates) {
            $ctx->pendingGates[] = \array_key_last($ctx->outcomes);
        } elseif ($reachable) {
            $ctx->skipQualifier = TargetState::UNMET;
            $ctx->skipReason    = \sprintf(
                'version %d is written for another environment, so the rule set writes '
                . 'nothing further here',
                $object->version
            );
        }
    }

    /**
     * Dispatch on the judge result: advance the simulated state, propose an operation,
     * or record that the object could not match or was refused.
     */
    private function advanceWalk(
        RuleObject $object,
        RuleAction $chosen,
        string $result,
        string $reason,
        int $index,
        WalkContext $ctx,
    ): void {
        switch ($result) {
            case ObjectOutcome::AT_PAYLOAD:
                $ctx->settled      = $object;
                $ctx->expected     = $chosen->isCopy()
                    ? HashResult::digest((string) $chosen->sourceHash)
                    : HashResult::absent('this object removes the target, and nothing is there');
                $ctx->pendingGates  = $this->release($ctx->outcomes, $ctx->pendingGates);
                $ctx->skipQualifier = null;
                $ctx->skipReason    = null;

                break;

            case ObjectOutcome::WOULD_APPLY:
                $ctx->operations[] = ProposedOperation::fromAction($object, $chosen, $reason);
                $ctx->settled      = $object;
                $ctx->expected     = $this->expectationOf($object, $chosen);
                $ctx->state        = $chosen->isCopy()
                    ? HashResult::digest((string) $chosen->sourceHash)
                    : HashResult::absent('this object removes the target');
                $ctx->pendingGates  = $this->release($ctx->outcomes, $ctx->pendingGates);
                $ctx->skipQualifier = null;
                $ctx->skipReason    = null;

                break;

            case ObjectOutcome::NOT_MATCHED:
                $ctx->sawNotMatched = true;

                if ($ctx->settled === null && $object->expectedHash !== null) {
                    $ctx->expected = HashResult::digest($object->expectedHash);
                }

                if ($this->stopsTheChainShort($object, $ctx->settled, $ctx->packageOwned, $ctx->writtenBefore[$index])) {
                    $ctx->pendingGates[] = \array_key_last($ctx->outcomes);
                }

                break;

            default:
                $ctx->refusedByPermit = true;

                if ($this->stopsTheChainShort($object, $ctx->settled, $ctx->packageOwned, $ctx->writtenBefore[$index])) {
                    $ctx->pendingGates[] = \array_key_last($ctx->outcomes);
                }
        }
    }

    /**
     * The status the walk as a whole produced.
     *
     * `incomplete` is tested before `actionable` on purpose. A walk that can move one
     * step and is then gated has work to do and still cannot reach the head, and of
     * the two facts the second is the one the operator has to act on: the proposed
     * operations stay on the state for a later phase to apply, and the status says
     * the rule set stopped short. Reporting `actionable` there would let a run apply
     * part of a chain and call the target done.
     *
     * `$gated` is what the walk was still held by when it ended, not what it was ever
     * held by. A barrier a later object got past is not a barrier.
     *
     * ## A skipped head is `ok`, and says which kind of skip
     *
     * Where the walk advanced and the objects beyond it were skipped without stranding
     * anything, the file holds what the rule set writes here, which is what `ok` means
     * in brief 5.3. The rule set has nothing further to do and nothing is wrong, so an
     * `incomplete` there would notify on every run about a rule set that is working.
     *
     * The reason still has to reach the operator, so `ok` carries a qualifier and the
     * report reads `ok (disabled)` or `ok (unmet)`. That is the shape decision 19
     * already uses for `frozen (would be actionable)`: one status from the closed set,
     * with the reason beside it. A forgotten switch and a condition that stopped
     * matching both stay readable, and neither is dressed up as a fault.
     *
     * @param list<ProposedOperation> $operations
     *
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function settle(
        ?RuleObject $settled,
        array $operations,
        bool $gated,
        ?string $skipQualifier,
        ?string $skipReason,
        bool $refusedByPermit,
        bool $sawNotMatched,
    ): array {
        if ($settled !== null) {
            if ($gated) {
                return [
                    TargetStatus::INCOMPLETE,
                    \sprintf(
                        'the walk reached version %d and stopped there, short of the head',
                        $settled->version
                    ),
                    null,
                ];
            }

            if ($operations !== []) {
                return [
                    TargetStatus::ACTIONABLE,
                    \sprintf('the target can be carried to version %d', $settled->version),
                    null,
                ];
            }

            if ($skipQualifier !== null) {
                return [TargetStatus::OK, (string) $skipReason, $skipQualifier];
            }

            return [
                TargetStatus::OK,
                \sprintf('the target already holds what version %d writes', $settled->version),
                null,
            ];
        }

        if ($gated) {
            return [
                TargetStatus::INCOMPLETE,
                'an object gates the objects after it, so the walk could not start',
                null,
            ];
        }

        if ($skipReason !== null) {
            // An object that matched the file and was skipped anyway is the whole
            // account of this target. It comes before the blocked test below, because
            // a later object failing to match is a consequence of that skip and not
            // evidence that anybody changed the file.
            return [TargetStatus::NOT_APPLICABLE, $skipReason, $skipQualifier];
        }

        if ($refusedByPermit) {
            return [
                TargetStatus::NOT_APPLICABLE,
                'the objects at this target do not permit the write the file would need',
                null,
            ];
        }

        if ($sawNotMatched) {
            return [
                TargetStatus::BLOCKED,
                'the target matches no expected original and no payload the rule set declares',
                null,
            ];
        }

        return [TargetStatus::NOT_APPLICABLE, 'no object at this target applies here', null];
    }

    /**
     * What one action makes of the state in front of it.
     *
     * The whole of the status model reduces to this, because state is read as a hash:
     * the file is compared against the two values the object declares, and everything
     * follows from which of them it matches. Conditions and the enable flag are
     * settled before an action reaches here.
     *
     * @return array{0: string, 1: string} An ObjectOutcome result and its reason.
     */
    private function judge(RuleObject $object, RuleAction $action, HashResult $state): array
    {
        if ($action->isCopy()) {
            if ($state->matches((string) $action->sourceHash)) {
                return [ObjectOutcome::AT_PAYLOAD, 'the target already holds this object\'s payload'];
            }

            if ($object->expectedHash === null) {
                // Provisioning. The target is matched on path alone, so there is
                // nothing to recognise the file by and the permissions carry the
                // whole decision.
                if ($state->isAbsent()) {
                    return $object->create
                        ? [ObjectOutcome::WOULD_APPLY, 'the file is absent and this object may create it']
                        : [
                            ObjectOutcome::NOT_APPLICABLE,
                            'the file is absent and this object may not create it',
                        ];
                }

                return $object->replace
                    ? [
                        ObjectOutcome::WOULD_APPLY,
                        'a file is present, this object may replace it, and no expected original '
                            . 'narrows that',
                    ]
                    : [
                        ObjectOutcome::NOT_APPLICABLE,
                        'a file is present and this object may not replace it',
                    ];
            }

            if (!$state->isAvailable()) {
                return [
                    ObjectOutcome::NOT_MATCHED,
                    'the file is absent, so it cannot be the expected original this object declares',
                ];
            }

            if (!$state->matches($object->expectedHash)) {
                return [
                    ObjectOutcome::NOT_MATCHED,
                    'the target matches neither this object\'s expected original nor its payload',
                ];
            }

            return $object->replace
                ? [
                    ObjectOutcome::WOULD_APPLY,
                    'the target is the expected original and this object may replace it',
                ]
                : [
                    ObjectOutcome::NOT_APPLICABLE,
                    'the target is the expected original but this object may not replace it',
                ];
        }

        // A removal's end state is absence, so an absent file is already there.
        if ($state->isAbsent()) {
            return [ObjectOutcome::AT_PAYLOAD, 'the target is already absent'];
        }

        if ($object->expectedHash !== null && !$state->matches($object->expectedHash)) {
            return [
                ObjectOutcome::NOT_MATCHED,
                'the target is not the file this object was written to remove',
            ];
        }

        // Removing a file displaces its content exactly as overwriting it does, so the
        // permission that governs is the one that says an existing file may be written
        // over. An object that may not replace may not delete either.
        return $object->replace
            ? [ObjectOutcome::WOULD_APPLY, 'the file is present and this object removes it']
            : [ObjectOutcome::NOT_APPLICABLE, 'a file is present and this object may not replace it'];
    }

    /**
     * Whether an object the walk could not use leaves the head out of reach.
     *
     * Asked only of an object the walk judged and could not advance through, and only
     * once the walk has advanced. What separates a broken chain from an alternative
     * that was never meant to match is where the object's expected original comes
     * from: a chain step expects what an earlier object at this path writes, while a
     * vendor alternative expects an original from a vendor build, which no payload
     * hash can equal.
     *
     * A package-owned set answers yes without that test. Its objects are revisions of
     * each other by construction, so a later one the walk cannot use has either forked
     * or broken, and the build's own chain check refuses a package-owned object whose
     * expectation no earlier object writes.
     *
     * @param array<string, true> $writtenBefore Payload hashes written by the objects
     *                                           ahead of this one, lower case.
     */
    private function stopsTheChainShort(
        RuleObject $object,
        ?RuleObject $settled,
        bool $packageOwned,
        array $writtenBefore,
    ): bool {
        if ($settled === null) {
            return false;
        }

        if ($packageOwned) {
            return true;
        }

        return $object->expectedHash !== null
            && isset($writtenBefore[\strtolower($object->expectedHash)]);
    }

    /**
     * Whether anything after this object expects a state only this object produces.
     *
     * The test for a disabled object on a walk that has not started. Position alone
     * would answer for a disabled vendor alternative too, and disabling one of those
     * is an intended use rather than a gap in a chain.
     *
     * @param list<RuleObject> $objects
     */
    private function gatesALaterChainStep(RuleObject $object, array $objects, int $index): bool
    {
        $written = [];

        foreach ($object->payloadHashes() as $hash) {
            $written[\strtolower($hash)] = true;
        }

        if ($written === []) {
            return false;
        }

        foreach (\array_slice($objects, $index + 1) as $later) {
            if ($later->expectedHash !== null && isset($written[\strtolower($later->expectedHash)])) {
                return true;
            }
        }

        return false;
    }


    /**
     * Clear the barriers a later object has got past, in the report as well.
     *
     * The outcome lines are what an operator reads, so an object still marked as
     * gating the objects after it, on a walk that demonstrably carried on past it,
     * would be the same defect one layer up.
     *
     * @param list<ObjectOutcome> $outcomes    Amended in place through the reference.
     * @param list<int>           $pendingGates
     *
     * @return list<int> Always empty; returned so the caller reads as an assignment.
     */
    private function release(array &$outcomes, array $pendingGates): array
    {
        foreach ($pendingGates as $index) {
            $outcomes[$index] = $outcomes[$index]->released();
        }

        return [];
    }

    /**
     * Whether this object would have moved the walk on, had it been judged at all.
     *
     * Asked about an object the walk skipped, so it ignores both the enable flag and
     * the conditions and asks only what the state in front of it would have produced.
     * Any of the object's actions counts, because which one a run would have chosen
     * is a question about conditions that did not hold or were never evaluated.
     */
    private function couldAdvance(RuleObject $object, HashResult $state): bool
    {
        foreach ($object->actions as $action) {
            [$result] = $this->judge($object, $action, $state);

            if ($result === ObjectOutcome::AT_PAYLOAD || $result === ObjectOutcome::WOULD_APPLY) {
                return true;
            }
        }

        return false;
    }

    /**
     * The hash the walk measured this object against, for the report's expected field.
     *
     * A declared expected original where there is one. Failing that, a copy is
     * measured against the payload it writes, and a removal against nothing at all:
     * matched on path alone, it has no hash to name, and the field carries that
     * reason rather than a digest borrowed from somewhere else.
     */
    private function expectationOf(RuleObject $object, RuleAction $action): HashResult
    {
        if ($object->expectedHash !== null) {
            return HashResult::digest($object->expectedHash);
        }

        if ($action->isCopy()) {
            return HashResult::digest((string) $action->sourceHash);
        }

        return HashResult::unavailable(
            'this object declares no expected original, so the removal is matched on path alone'
        );
    }

    /**
     * @param list<ObjectOutcome> $outcomes
     */
    private function faultedTarget(
        string $targetPath,
        string $reason,
        array $outcomes = [],
        ?HashResult $actual = null,
    ): TargetState {
        // The proposed operations of every earlier object are dropped rather than
        // carried: a target that faulted part way changes no file, and the surest way
        // to guarantee that is to hand a later phase nothing to execute.
        return TargetState::of(
            $targetPath,
            TargetStatus::FAULTED,
            $reason,
            HashResult::unavailable($reason),
            $actual ?? HashResult::unavailable($reason),
            $outcomes,
            []
        );
    }
}
