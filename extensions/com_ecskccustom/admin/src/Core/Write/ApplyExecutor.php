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
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\HashResult;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\Hasher;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path\PathPolicy;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\PlannedOperation;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\ReportedValue;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\FreezeMarkers;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ProposedOperation;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetState;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;

/**
 * Carries each target of an apply along its chain, inside a write run Runner holds.
 *
 * It takes no lock and publishes no marker: `WriteRun` does both around it. What it
 * owns is the order and the checks between steps. Targets go in walk order. Each
 * step reads the target again just before it writes. A payload already in place is
 * recognised rather than written again. A chain that stops says so on every step it
 * never reached. And once one target leaves a write intent outstanding, every later
 * target is refused, because a second intent would replace the only record from
 * which that mutation can be recovered.
 *
 * @internal Used only by Runner.
 */
final class ApplyExecutor
{
    private const NOT_REACHED = 'an earlier operation in this chain did not complete, so this '
        . 'one was not attempted';

    private const INTENT_OUTSTANDING = 'the write intent published for %s is still outstanding, '
        . 'so this run went no further: a second intent would replace the only record from which '
        . 'that mutation can be recovered';

    private readonly TargetWriter $writer;

    private readonly FreezeGuard $guard;

    private readonly Hasher $hasher;

    /**
     * @param Closure(string, string|null): void|null $checkpoint The test seam Runner
     *                                                            passes to the write layer.
     */
    public function __construct(
        private readonly PackagePaths $paths,
        private readonly PathPolicy $policy,
        private readonly WritePlanner $planner,
        ?Closure $checkpoint = null,
    ) {
        $this->writer = new TargetWriter($paths, $policy, $checkpoint);
        $this->guard  = new FreezeGuard(new FreezeMarkers($paths));
        $this->hasher = new Hasher();
    }

    /**
     * Perform every target's plan, in the order the walk produced them.
     *
     * @param list<TargetState> $states
     *
     * @return array<string, TargetResult> Keyed by target path, in the same order.
     */
    public function applyAll(array $states): array
    {
        $results = [];
        $halted  = null;

        foreach ($states as $state) {
            $result = $halted === null
                ? $this->apply($state)
                : $this->refuse($state, WriteOutcome::refused($halted));

            $results[$state->targetPath] = $result;

            if ($halted === null && $result->outcome->intentLeft) {
                $halted = \sprintf(self::INTENT_OUTSTANDING, $state->targetPath);
            }
        }

        return $results;
    }

    /**
     * One target: the freeze guard, the status gate, then the chain.
     */
    private function apply(TargetState $state): TargetResult
    {
        $refusal = $this->guard->refusal($state->targetPath);

        if ($refusal !== null) {
            return $this->refuse($state, WriteOutcome::refused($refusal));
        }

        if ($state->status === TargetStatus::BLOCKED) {
            return $this->refuse($state, WriteOutcome::refused(\sprintf(
                'the bytes at %s match neither a shipped payload nor an expected original, so '
                . 'nothing was written: %s',
                $state->targetPath,
                $state->reason
            )));
        }

        if ($state->status === TargetStatus::FAULTED) {
            return $this->refuse($state, WriteOutcome::refused($state->reason));
        }

        if ($state->operations() === []) {
            return new TargetResult(WriteOutcome::unchanged($state->reason), [], []);
        }

        return $this->applyChain($state);
    }

    /**
     * A target the run would not touch: the plan it did not execute, and why.
     *
     * The line is still shown. An operator reading a refused target needs to see what
     * would have been written there and what it would have displaced, or they cannot
     * tell a freeze they forgot about from a file the package has never proposed to
     * touch.
     */
    private function refuse(TargetState $state, WriteOutcome $outcome): TargetResult
    {
        $result = ReportedValue::unavailable($outcome->reason);
        $plan   = [];

        foreach ($this->planner->plan($state) as $planned) {
            $plan[] = $planned->withResult($result);
        }

        return new TargetResult($outcome, $plan, []);
    }

    /**
     * Each operation in turn, each one re-checked against the tree as it is now.
     *
     * The result's backups are the ones its operations preserved, which a refused
     * step's computed destination never is.
     */
    private function applyChain(TargetState $state): TargetResult
    {
        /** @var list<PlannedOperation> $plan */
        $plan    = [];
        /** @var list<string> $backups */
        $backups = [];
        $last       = null;
        $wrote      = false;
        $expected   = $state->actual->isAvailable() ? $state->actual->digest : null;
        $operations = $state->operations();
        $count      = \count($operations);

        for ($index = 0; $index < $count; $index++) {
            $operation = $operations[$index];
            $current   = $this->currentState($operation);

            // Bytes that moved after the walk to a payload this chain still has to
            // write were put there by another run, a recovery, or an outside copy of
            // the shipped file. Every step up to that payload is already represented,
            // so none of them writes, and the chain carries on from the step after it.
            // This is checked before the step's own preconditions because a step that
            // writes nothing needs none of them.
            if ($current->isAvailable() && $current->digest !== $expected) {
                $reached = self::chainPosition($operations, $index, (string) $current->digest);

                if ($reached !== null) {
                    for (; $index <= $reached; $index++) {
                        $plan[] = PlannedOperation::alreadyInPlace(
                            $operations[$index],
                            self::alreadyInPlaceReason($operations[$index], $operations[$reached])
                        );
                    }

                    if ($last === null || !$last->changed) {
                        $last = WriteOutcome::unchanged(
                            self::alreadyInPlaceReason($operations[$reached], $operations[$reached])
                        );
                    }

                    $expected = $current->digest;
                    $index    = $reached;

                    continue;
                }
            }

            $line = $this->planner->line($operation, $current);

            // A current state the package could not read is one of the planner's
            // refusals, so it is answered here rather than by the comparison below,
            // where an unreadable target and an absent one both carry no digest.
            if (!$line->isPerformable()) {
                return $this->stopChain($state, $index, $plan, $backups, $line, $wrote, WriteOutcome::refused(\sprintf(
                    '%s was not performed: %s',
                    $operation->describe(),
                    \implode('; ', $line->refusals)
                )));
            }

            $displaced = $current->digest;

            if ($displaced !== $expected) {
                return $this->stopChain($state, $index, $plan, $backups, $line, $wrote, WriteOutcome::refused(\sprintf(
                    '%s was not performed: %s',
                    $operation->describe(),
                    self::unrecognised($operation->targetPath, $displaced)
                )));
            }

            $last   = $this->performOne($operation, $displaced);
            $plan[] = $line->withResult(self::performedValue($last));

            if ($last->backup !== null && $last->backup->isPreserved()) {
                $backups[] = $last->backup->path;
            }

            if ($last->isFaulted()) {
                return $this->stopChain($state, $index, $plan, $backups, null, $wrote, $last);
            }

            $wrote = $wrote || $last->changed;

            $expected = $operation->isCopy() ? $operation->payloadHash : null;
        }

        return new TargetResult($last ?? WriteOutcome::unchanged($state->reason), $plan, $backups);
    }

    /**
     * The last step from `$from` on whose payload is the digest now at the target.
     *
     * The latest match wins, so a chain that reached its final payload is not
     * written again from an earlier step. Remove operations ship no payload and never
     * match.
     *
     * @param list<ProposedOperation> $operations
     */
    private static function chainPosition(array $operations, int $from, string $digest): ?int
    {
        for ($index = \count($operations) - 1; $index >= $from; $index--) {
            $operation = $operations[$index];

            if ($operation->isCopy() && $operation->payloadHash === $digest) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Why a step that found its payload, or a later one, already in place wrote nothing.
     */
    private static function alreadyInPlaceReason(ProposedOperation $operation, ProposedOperation $reached): string
    {
        if ($operation === $reached) {
            return \sprintf(
                '%s already holds the payload this operation writes, so nothing was written',
                $operation->targetPath
            );
        }

        return \sprintf(
            '%s already holds the version %d payload, which includes this step, so nothing was written',
            $operation->targetPath,
            $reached->version
        );
    }

    /**
     * Close a chain that stopped, and say so on every step it never reached.
     *
     * A plan that simply ended at the failure would read as a two-step chain with one
     * step, and an operator would have no way to see that version 2 is still owed.
     *
     * @param list<PlannedOperation> $plan    What the chain executed before it stopped.
     * @param list<string>           $backups The backups those steps preserved.
     * @param PlannedOperation|null  $stopped The line that refused, when the refusal
     *                                        is the line's own rather than a fault
     *                                        raised by the write.
     * @param bool                   $wrote   Whether an earlier step of this chain
     *                                        changed the target.
     */
    private function stopChain(
        TargetState $state,
        int $index,
        array $plan,
        array $backups,
        ?PlannedOperation $stopped,
        bool $wrote,
        WriteOutcome $outcome,
    ): TargetResult {
        if ($stopped !== null) {
            $plan[] = $stopped->withResult(ReportedValue::unavailable($outcome->reason));
        }

        $notReached = ReportedValue::none(self::NOT_REACHED);

        foreach (\array_slice($state->operations(), $index + 1) as $operation) {
            $plan[] = new PlannedOperation(
                $operation,
                $notReached,
                $notReached,
                [self::NOT_REACHED],
                $notReached
            );
        }

        return new TargetResult($wrote ? $outcome->withEarlierChange() : $outcome, $plan, $backups);
    }

    /**
     * Why bytes that are no longer the ones the walk recognised are not replaced.
     */
    private static function unrecognised(string $targetPath, ?string $displaced): string
    {
        return \sprintf(
            'the bytes at %s changed after the walk read them (%s), so they match neither a '
            . 'shipped payload nor an expected original and nothing was written',
            $targetPath,
            $displaced === null
                ? 'the target is no longer there'
                : 'they now hash to ' . $displaced
        );
    }

    /**
     * What one operation did, for its line of the plan, with its backup named.
     */
    private static function performedValue(WriteOutcome $outcome): ReportedValue
    {
        $reason = $outcome->backup === null
            ? $outcome->reason
            : \sprintf(
                '%s. Backup %s at %s: %s',
                $outcome->reason,
                $outcome->backup->status,
                $outcome->backup->path,
                $outcome->backup->reason
            );

        return $outcome->isFaulted()
            ? ReportedValue::unavailable($reason)
            : ReportedValue::known($reason);
    }

    private function performOne(ProposedOperation $operation, ?string $displaced): WriteOutcome
    {
        if (!$operation->isCopy()) {
            if ($displaced === null) {
                return WriteOutcome::unchanged(\sprintf(
                    '%s is already absent, so nothing was removed',
                    $operation->targetPath
                ));
            }

            return $this->writer->remove(
                $operation->targetPath,
                $operation->hashType,
                $displaced,
                $operation->backup ? $operation->version : null
            );
        }

        return $this->writer->write(
            $operation->targetPath,
            $this->paths->payload() . '/' . (string) $operation->payloadSource,
            $operation->hashType,
            (string) $operation->payloadHash,
            $displaced,
            $displaced === null ? null : $operation->version
        );
    }

    /**
     * What is at the target right now: its digest, its absence, or why neither.
     */
    private function currentState(ProposedOperation $operation): HashResult
    {
        $decision = $this->policy->decide($operation->targetPath);

        if (!$decision->allowed) {
            return HashResult::unavailable((string) $decision->reason);
        }

        $path = (string) $decision->path;
        \clearstatcache(true, $path);

        if (\is_link($path)) {
            return HashResult::unavailable(
                \sprintf('%s is a symbolic link, which is never a managed target', $path)
            );
        }

        if (!\file_exists($path)) {
            return HashResult::absent(\sprintf('%s does not exist', $path));
        }

        if (!\is_file($path)) {
            return HashResult::unavailable(
                \sprintf('%s is not a regular file this package may replace', $path)
            );
        }

        return $this->hasher->hashFile($operation->hashType, $path);
    }
}
