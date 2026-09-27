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
                \sprintf('the package may not write to this path: %s', (string) $decision->reason)
            );
        }

        $algorithm = $objects[0]->hashType;
        $actual    = $this->hasher->hashFile($algorithm, (string) $decision->path);

        if (!$actual->isAvailable() && !$actual->isAbsent()) {
            $reason = \sprintf('the file could not be read: %s', (string) $actual->reason);

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
                    '%s declares hash type %s where an earlier version of this file declares %s, '
                    . 'so the two cannot be compared',
                    $object->label(),
                    $object->hashType,
                    $algorithm
                ), $ctx->outcomes, $actual);
            }

            if (!$object->enable) {
                $this->walkDisabledObject($object, $index, $ctx);

                continue;
            }

            $selection = $this->chooseAction($object, $index, $values, $ctx);

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
                'no version of this file was compared with it, so the package has no hash '
                . 'to expect here'
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
     */
    private function walkDisabledObject(RuleObject $object, int $index, WalkContext $ctx): void
    {
        $reachable = $this->couldAdvance($object, $ctx->state);

        $gates = $reachable && $this->gatesALaterChainStep($object, $ctx->objects, $index);

        if ($gates) {
            $reason = 'this version is switched off, and a later version expects the content it '
                . 'would have written';
        } else {
            $reason = 'this version is switched off';

            if ($reachable) {
                $ctx->skipQualifier = TargetState::DISABLED;
                $ctx->skipReason    = \sprintf(
                    'the file matches version %d, which is switched off, so nothing more is '
                    . 'written here',
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
        int $index,
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
            $reason          = \sprintf('a condition of this version could not be checked: %s', \implode('; ', $faults));
            $ctx->outcomes[] = ObjectOutcome::for($object, ObjectOutcome::FAULTED, $reason);

            return ActionSelection::faulted($reason);
        }

        if ($chosen === null) {
            $this->recordConditionsUnmet($object, $index, $ctx);

            return ActionSelection::unmet();
        }

        return ActionSelection::selected($chosen);
    }

    /**
     * An object whose conditions did not hold: record the outcome and decide whether
     * the skip gates a later chain step or simply qualifies the status.
     */
    private function recordConditionsUnmet(RuleObject $object, int $index, WalkContext $ctx): void
    {
        $reachable = $this->couldAdvance($object, $ctx->state);

        $gates = $reachable
            && $ctx->settled !== null
            && $this->gatesALaterChainStep($object, $ctx->objects, $index);

        $ctx->outcomes[] = ObjectOutcome::for(
            $object,
            ObjectOutcome::CONDITIONS_UNMET,
            'this version is not meant for this site setup',
            $gates
        );

        if ($gates) {
            $ctx->pendingGates[] = \array_key_last($ctx->outcomes);
        } elseif ($reachable) {
            $ctx->skipQualifier = TargetState::UNMET;
            $ctx->skipReason    = \sprintf(
                'version %d is meant for a different site setup, so nothing more is written '
                . 'here',
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
                    : HashResult::absent('this version removes the file, and no file is there');
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
                    : HashResult::absent('this version removes the file');
                $ctx->pendingGates  = $this->release($ctx->outcomes, $ctx->pendingGates);
                $ctx->skipQualifier = null;
                $ctx->skipReason    = null;

                break;

            case ObjectOutcome::NOT_MATCHED:
                $ctx->sawNotMatched = true;

                if ($ctx->settled === null && $object->expectedHash !== null) {
                    $ctx->expected = HashResult::digest($object->expectedHash);
                }

                if ($this->stopsTheChainShort($object, $index, $ctx)) {
                    $ctx->pendingGates[] = \array_key_last($ctx->outcomes);
                }

                break;

            default:
                $ctx->refusedByPermit = true;

                if ($this->stopsTheChainShort($object, $index, $ctx)) {
                    $ctx->pendingGates[] = \array_key_last($ctx->outcomes);
                }
        }
    }

    /**
     * The status the walk as a whole produced.
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
                        'the file can be updated to version %d, but not to the latest version',
                        $settled->version
                    ),
                    null,
                ];
            }

            if ($operations !== []) {
                return [
                    TargetStatus::ACTIONABLE,
                    \sprintf('the file can be updated to version %d', $settled->version),
                    null,
                ];
            }

            if ($skipQualifier !== null) {
                return [TargetStatus::OK, (string) $skipReason, $skipQualifier];
            }

            return [
                TargetStatus::OK,
                \sprintf('the file already has the version %d changes', $settled->version),
                null,
            ];
        }

        if ($gated) {
            return [
                TargetStatus::INCOMPLETE,
                'an earlier version is switched off or not meant for this site setup, and the '
                . 'versions after it build on it, so none of them can be applied',
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
                'no version of this file is allowed to make the change it would need',
                null,
            ];
        }

        if ($sawNotMatched) {
            return [
                TargetStatus::BLOCKED,
                'the file matches neither an original the package expects nor any version it ships',
                null,
            ];
        }

        return [TargetStatus::NOT_APPLICABLE, 'no version of this file applies to this site', null];
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
                return [ObjectOutcome::AT_PAYLOAD, 'the file already has this version\'s content'];
            }

            if ($object->expectedHash === null) {

                if ($state->isAbsent()) {
                    return $object->create
                        ? [ObjectOutcome::WOULD_APPLY, 'the file does not exist yet, and this version may create it']
                        : [
                            ObjectOutcome::NOT_APPLICABLE,
                            'the file does not exist, and this version is not allowed to create it',
                        ];
                }

                return $object->replace
                    ? [
                        ObjectOutcome::WOULD_APPLY,
                        'a file exists, and this version may replace whatever it holds',
                    ]
                    : [
                        ObjectOutcome::NOT_APPLICABLE,
                        'a file exists, and this version is not allowed to replace it',
                    ];
            }

            if (!$state->isAvailable()) {
                return [
                    ObjectOutcome::NOT_MATCHED,
                    'the file does not exist, so it is not the original this version expects',
                ];
            }

            if (!$state->matches($object->expectedHash)) {
                return [
                    ObjectOutcome::NOT_MATCHED,
                    'the file matches neither the original this version expects nor this version\'s content',
                ];
            }

            return $object->replace
                ? [
                    ObjectOutcome::WOULD_APPLY,
                    'the file is the original this version expects, and this version may replace it',
                ]
                : [
                    ObjectOutcome::NOT_APPLICABLE,
                    'the file is the original this version expects, but this version is not allowed to replace it',
                ];
        }

        if ($state->isAbsent()) {
            return [ObjectOutcome::AT_PAYLOAD, 'the file is already gone'];
        }

        if ($object->expectedHash !== null && !$state->matches($object->expectedHash)) {
            return [
                ObjectOutcome::NOT_MATCHED,
                'the file is not the one this version was written to remove',
            ];
        }

        return $object->replace
            ? [ObjectOutcome::WOULD_APPLY, 'the file exists, and this version removes it']
            : [ObjectOutcome::NOT_APPLICABLE, 'a file exists, and this version is not allowed to replace it'];
    }

    /**
     * Whether an object the walk could not use leaves the head out of reach.
     *
     * A package-owned set answers yes without that test. Its objects are revisions of
     * each other by construction, so a later one the walk cannot use has either forked
     * or broken, and the build's own chain check refuses a package-owned object whose
     * expectation no earlier object writes.
     */
    private function stopsTheChainShort(RuleObject $object, int $index, WalkContext $ctx): bool
    {
        if ($ctx->settled === null) {
            return false;
        }

        if ($ctx->packageOwned) {
            return true;
        }

        return $object->expectedHash !== null
            && $ctx->isWrittenBefore($object->expectedHash, $index);
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
            'this version names no expected original, so the removal goes by the path alone'
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
