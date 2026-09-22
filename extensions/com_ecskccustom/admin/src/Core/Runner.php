<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core;

use Closure;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentProviderInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentResolver;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentValues;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\HashResult;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\Hasher;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path\PathPolicy;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalChecks;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\PlannedOperation;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\ReportedValue;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\MergedSetValidator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleLoader;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleSet;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\FreezeMarkers;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteHistoryInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ObjectWalker;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ProposedOperation;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetState;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\FreezeGuard;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\Freezer;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\RecoveryOutcome;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\Reverter;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\RunRecovery;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\TargetWriter;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\WriteOutcome;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\WritePlanner;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\WriteRun;

/**
 * The one entry point into the core, and the only thing a Joomla adapter talks to.
 *
 * Each responsibility stays behind it and none of them knows about the others:
 * loading, validation, environment values, path policy, hashing, the walk, and the
 * write operations. The runner puts them in order and turns what they produce into
 * one report.
 *
 * It reads the rule set from the installed package directory and from nowhere else,
 * per brief 5.4. The directory comes from PackagePaths, so the installer that
 * creates these folders and the run that reads them cannot drift apart.
 *
 * ## What it does not do
 *
 * No HTML, no redirect, no Joomla message, no request input, and no database query
 * of its own. A controller calls one of these methods and renders what comes back;
 * the operator sees the report and nothing the runner decided about presenting it.
 * The one route by which a database record may reach a run is the operational-check
 * provider that Phase 4 adds, and even then the runner is handed the raw value
 * rather than issuing the query.
 *
 * ## The seven things it can be asked to do
 *
 * `check()` reads and reports. `dryRun()` adds the write preconditions and the plan.
 * `apply()` executes that plan. `freeze()`, `unfreeze()`, `revert()` and `redo()`
 * are one-target operations that take the run lock themselves, decision 27, so the
 * runner calls them rather than wrapping them: a second acquire in the same process
 * is a second handle and would fail.
 *
 * ## The plan is a preview, never a licence
 *
 * A dry run and an apply build the plan through the same `WritePlanner`, so what the
 * operator approves is what runs. The apply then checks every precondition again
 * immediately before each operation, and `TargetWriter` decides the path a third
 * time at the move. Bytes and paths move between a preview and an execution, and
 * ticket 31 requires the path decision to be taken at the move; a plan carried
 * forward as permission is how a payload ends up written through a symbolic link
 * somebody swapped in afterwards.
 *
 * The bytes are re-read the same way, and against the state the walk recognised
 * rather than against nothing: an apply that took whatever it found as the permitted
 * pre-state would back up and overwrite an edit made after the walk. The apply's own
 * report carries the plan it executed, line by line, with what each operation
 * displaced and where its backup went.
 *
 * ## Recovery is an ordinary run
 *
 * Brief decision 16: the package resumes through the ordinary check-and-apply
 * process. So there is no repair command here. Every write run settles what an
 * interrupted one left before it does its own work, through `WriteRun`, and the
 * outcome of that settlement is on the report.
 */
final class Runner
{
    /**
     * A check displaces nothing, so there is no backup to name.
     *
     * The field is carried all the same. The contract requires it wherever an
     * operation preserves displaced content, and a line that dropped it in a
     * report-only run would look like a line that had nothing to say.
     */
    private const NO_BACKUP = 'a check performs no operation, so nothing is displaced and no '
        . 'backup is written';

    private const NO_PLAN_BACKUP = 'no operation at this target preserves displaced content';

    private const NO_INTERRUPTION = 'no earlier write run was interrupted';

    private const NOT_REACHED = 'an earlier operation in this chain did not complete, so this '
        . 'one was not attempted';

    private const INTENT_OUTSTANDING = 'the write intent published for %s is still outstanding, '
        . 'so this run went no further: a second intent would replace the only record from which '
        . 'that mutation can be recovered';

    private const NOT_WALKED_AGAIN = 'the rule set could not be walked again after this run, so %s '
        . 'is reported by its outcome and the plan this run executed: %s';

    private const PLANNED_BEFORE_WALK = '%s, as this run planned it: %s';

    private readonly WritePlanner $planner;

    private readonly RunRecovery $recovery;

    private readonly TargetWriter $writer;

    private readonly Freezer $freezer;

    private readonly Reverter $reverter;

    private readonly FreezeGuard $guard;

    private readonly WriteRun $writeRun;

    private readonly Hasher $hasher;

    /**
     * @param Closure(string, string|null): void|null $checkpoint A test seam, passed
     *                                                            to the write layer so
     *                                                            a test can change the
     *                                                            tree or stop the run
     *                                                            at a named point.
     */
    public function __construct(
        private readonly PackagePaths $paths,
        private readonly RuleLoader $loader,
        private readonly MergedSetValidator $validator,
        private readonly EnvironmentProviderInterface $environment,
        private readonly EnvironmentResolver $resolver,
        private readonly ObjectWalker $walker,
        private readonly WriteHistoryInterface $history,
        private readonly PathPolicy $policy,
        private readonly ?Closure $checkpoint = null,
    ) {
        $this->hasher   = new Hasher();
        $this->planner  = new WritePlanner($paths, $policy);
        $this->recovery = new RunRecovery($paths, $policy);
        $this->guard    = new FreezeGuard(new FreezeMarkers($paths));
        $this->writeRun = new WriteRun($paths, 'no target was touched', $this->recovery);
        $this->writer   = new TargetWriter($paths, $policy, $this->checkpoint);
        $this->freezer  = new Freezer(
            $paths,
            $this->checkpoint === null ? null : fn (string $point): null => $this->reach($point),
            $this->recovery
        );
        $this->reverter = new Reverter($paths, $policy, $walker, $this->checkpoint, $this->recovery);
    }

    /**
     * Read the state of every managed path and report it. Writes nothing.
     *
     * The core owns the operational-checks section, not the adapter. Phase 1
     * defines no check, so the section is present and empty. Phase 4 injects the
     * raw-value provider and adds the single core-owned raw-value-to-outcome
     * mapping here, before the report is composed, and the report shape does not
     * move.
     */
    public function check(): RunReport
    {
        $faulted = null;
        $walk    = $this->walk(null, $faulted);

        if ($walk === null) {
            return $faulted;
        }

        $targets = [];

        foreach ($walk['states'] as $state) {
            $targets[] = new TargetReport(
                $state,
                $this->history->lastWriteFor($state->targetPath),
                ReportedValue::none(self::NO_BACKUP)
            );
        }

        // Damaged records are a fault about the package's own history, not about the
        // site, so they travel beside the targets the walk just reported. A faulted
        // return above does not ask: that run never reached the log.
        return RunReport::of(
            $targets,
            OperationalChecks::none(),
            $this->history->interruption(),
            $this->history->recordFaults()
        );
    }

    /**
     * Work out what an apply would do, and prove it could do it. Writes nothing.
     *
     * **No managed target, backup, freeze marker, run marker, write intent or event
     * log is changed, and the run lock is not taken.** Everything here is a read:
     * the payloads are hashed where they lie, the permissions are asked of
     * directories that already exist, and the backup destination is computed from
     * the displaced digest rather than created.
     *
     * @param string|null $targetPath One canonical target path, or null for the set.
     */
    public function dryRun(?string $targetPath = null): RunReport
    {
        $faulted = null;
        $walk    = $this->walk($targetPath, $faulted);

        if ($walk === null) {
            return $faulted;
        }

        $targets = [];

        foreach ($walk['states'] as $state) {
            $targets[] = $this->line($state, $this->planner->plan($state), null);
        }

        return RunReport::of(
            $targets,
            OperationalChecks::none(),
            $this->history->interruption(),
            $this->history->recordFaults()
        );
    }

    /**
     * Carry every target to the head of its chain, under the run lock.
     *
     * The plan is built the way `dryRun()` builds it, then executed, then the tree is
     * walked again so the report says what the site holds now rather than what the
     * run expected it to hold. The plan travels with it: a plan rebuilt from the tree
     * afterwards is empty at every target the run succeeded at, which is the one place
     * the operator most needs to read what was replaced and where its backup went.
     *
     * @param string|null $targetPath One canonical target path, or null for the set.
     */
    public function apply(?string $targetPath = null): RunReport
    {
        $faulted = null;
        $walk    = $this->walk($targetPath, $faulted);

        if ($walk === null) {
            return $faulted;
        }

        /** @var array<string, WriteOutcome> $outcomes */
        $outcomes = [];

        /** @var array<string, list<PlannedOperation>> $performed */
        $performed = [];

        /** @var array<string, list<string>> $preservedBackups */
        $preservedBackups = [];

        $summary = $this->writeRun->run(
            function () use ($walk, &$outcomes, &$performed, &$preservedBackups): WriteOutcome {
                $outcomes = $this->performAll($walk['states'], $performed, $preservedBackups);

                return self::summarise($outcomes);
            }
        );

        // A refusal that never reached a target is the lock, recovery or the marker,
        // and it is the whole of what happened, so it is a fault about the run. A
        // refusal at one target is that target's, and it stays on that line: a
        // blocked file is the site's state, not a fault in the package. A run that
        // changed targets and then could not remove its marker is the first kind
        // again: the extra sentence is not on any target, and dropping it would
        // leave the next run reporting an interruption this one never mentioned.
        return $this->reportAfterWriting(
            $targetPath,
            $outcomes,
            self::runFaults($summary, $outcomes),
            $performed,
            $preservedBackups
        );
    }

    /**
     * Hold one target against every write until it is unfrozen.
     */
    public function freeze(string $targetPath): RunReport
    {
        return $this->operate($targetPath, fn (): WriteOutcome => $this->freezer->freeze($targetPath));
    }

    /**
     * Release a freeze.
     */
    public function unfreeze(string $targetPath): RunReport
    {
        return $this->operate($targetPath, fn (): WriteOutcome => $this->freezer->unfreeze($targetPath));
    }

    /**
     * Step one target back one object along its chain.
     */
    public function revert(string $targetPath): RunReport
    {
        return $this->operate(
            $targetPath,
            fn (RuleSet $set, EnvironmentValues $values): WriteOutcome
                => $this->reverter->revert($targetPath, $set)
        );
    }

    /**
     * Step one target forward one object along its chain.
     */
    public function redo(string $targetPath): RunReport
    {
        return $this->operate(
            $targetPath,
            fn (RuleSet $set, EnvironmentValues $values): WriteOutcome
                => $this->reverter->redo($targetPath, $set, $values)
        );
    }

    /**
     * One single-target operation that takes the run lock itself, decision 27.
     *
     * The rule set is loaded first, because a set that does not load is a fault about
     * the package and not something to take a lock for. The report is composed from a
     * walk taken **after** the operation, so it shows the target as it now is.
     *
     * @param Closure(RuleSet, EnvironmentValues): WriteOutcome $operation
     */
    private function operate(string $targetPath, Closure $operation): RunReport
    {
        $faulted = null;
        $walk    = $this->walk($targetPath, $faulted);

        if ($walk === null) {
            return $faulted;
        }

        $outcome = $operation($walk['ruleSet'], $walk['values']);

        // The operator asked for this one thing. If it did not happen, that is the
        // run's answer and not a footnote on a line about the target's state.
        return $this->reportAfterWriting(
            $targetPath,
            [$targetPath => $outcome],
            $outcome->isFaulted() ? [$outcome->reason] : []
        );
    }

    /**
     * Walk the tree again and compose the report, after something has been written.
     *
     * The history is asked to forget first: it caches the event log, and this process
     * has just appended to it. The interruption comes from the recovery that ran
     * under the lock rather than from the history's own run-state read, which takes
     * the run lock and would contend with a run still holding it.
     *
     * @param array<string, WriteOutcome>            $outcomes
     * @param list<string>                            $runFaults Faults about the run
     *                                                           itself, rather than
     *                                                           about one target.
     * @param array<string, list<PlannedOperation>>   $performed The plan this run
     *                                                           executed, per target.
     *                                                           A target absent from
     *                                                           it is planned again
     *                                                           from the tree as it
     *                                                           is now.
     * @param array<string, list<string>>             $preservedBackups The backups this
     *                                                                  run left preserved,
     *                                                                  per target it
     *                                                                  performed.
     */
    private function reportAfterWriting(
        ?string $targetPath,
        array $outcomes,
        array $runFaults,
        array $performed = [],
        array $preservedBackups = [],
    ): RunReport {
        $this->history->refresh();

        $faulted   = null;
        $walk      = $this->walk($targetPath, $faulted);
        $faults    = [...$this->history->recordFaults(), ...$runFaults];
        $recovered = $this->recovery->lastOutcome();

        if ($recovered !== null) {
            $faults = [...$faults, ...$recovered->faults];
        }

        // The rule set changed under the run, after it wrote. There is no state to
        // put a line against, but the writes happened and recovery may have
        // replaced the only record of a fault, so neither can be dropped for the
        // new rule error. Nor can the plan: it holds what each write displaced and
        // where the backup went, which is what an operator needs to undo it.
        if ($walk === null) {
            $written = [];

            foreach ($outcomes as $path => $outcome) {
                $written[] = \sprintf(
                    self::NOT_WALKED_AGAIN,
                    $path,
                    self::outcomeValue($outcome)?->describe()
                );

                foreach ($performed[$path] ?? [] as $planned) {
                    $written[] = \sprintf(self::PLANNED_BEFORE_WALK, $path, $planned->describe());
                }
            }

            return RunReport::faulted(
                [...$faulted->faults(), ...$written, ...$faults],
                $faulted->checks,
                $this->interruption($recovered)
            );
        }

        $targets = [];

        foreach ($walk['states'] as $state) {
            $outcome = $outcomes[$state->targetPath] ?? null;
            $plan    = $performed[$state->targetPath] ?? $this->planner->plan($state);

            $targets[] = $this->line(
                $state,
                $plan,
                self::outcomeValue($outcome),
                isset($performed[$state->targetPath]) ? $preservedBackups[$state->targetPath] ?? [] : null
            );
        }

        return RunReport::of(
            $targets,
            OperationalChecks::none(),
            $this->interruption($recovered),
            $faults
        );
    }

    /**
     * Perform every target's plan, in the order the walk produced them.
     *
     * ## The run stops at the first target that leaves an intent outstanding
     *
     * `state/write.intent` holds one record, published by atomic rename, and
     * decision 32 lets it disappear only once its own mutation event, its
     * confirmed-failure event, or a recovery event for its correlation id is
     * committed. A later target that published its intent over an outstanding one
     * would destroy the only record from which recovery could reconstruct the
     * earlier mutation - which may already have happened on disk with no event to
     * name it. So the run goes no further, and the targets it did not reach say so.
     *
     * @param list<TargetState>                     $states
     * @param array<string, list<PlannedOperation>> $performed Filled with the plan
     *                                                         each target executed.
     * @param array<string, list<string>>           $preservedBackups Filled with the
     *                                                                backups each
     *                                                                target's operations
     *                                                                preserved.
     *
     * @return array<string, WriteOutcome>
     */
    private function performAll(array $states, array &$performed, array &$preservedBackups): array
    {
        $outcomes = [];
        $halted   = null;

        foreach ($states as $state) {
            $plan    = [];
            $backups = [];

            $outcome = $halted === null
                ? $this->perform($state, $plan, $backups)
                : $this->refuseTarget($state, $plan, WriteOutcome::refused($halted));

            $outcomes[$state->targetPath]         = $outcome;
            $performed[$state->targetPath]        = $plan;
            $preservedBackups[$state->targetPath] = $backups;

            if ($halted === null && $outcome->intentLeft) {
                $halted = \sprintf(self::INTENT_OUTSTANDING, $state->targetPath);
            }
        }

        return $outcomes;
    }

    /**
     * One target: the freeze guard, the status gate, then the chain.
     *
     * @param list<PlannedOperation> $plan    Filled with what this target executed.
     * @param list<string>           $backups Filled with the backups it preserved.
     */
    private function perform(TargetState $state, array &$plan, array &$backups): WriteOutcome
    {
        $refusal = $this->guard->refusal($state->targetPath);

        // Asked under the lock, and asked again here rather than trusted from the
        // walk: a freeze that returned between the two has to exclude this write,
        // decision 30, and the walk ran before the lock was taken.
        if ($refusal !== null) {
            return $this->refuseTarget($state, $plan, WriteOutcome::refused($refusal));
        }

        if ($state->status === TargetStatus::BLOCKED) {
            return $this->refuseTarget($state, $plan, WriteOutcome::refused(\sprintf(
                'the bytes at %s match neither a shipped payload nor an expected original, so '
                . 'nothing was written: %s',
                $state->targetPath,
                $state->reason
            )));
        }

        if ($state->status === TargetStatus::FAULTED) {
            return $this->refuseTarget($state, $plan, WriteOutcome::refused($state->reason));
        }

        if ($state->operations() === []) {
            return WriteOutcome::unchanged($state->reason);
        }

        return $this->performChain($state, $plan, $backups);
    }

    /**
     * A target the run would not touch: the plan it did not execute, and why.
     *
     * The line is still shown. An operator reading a refused target needs to see what
     * would have been written there and what it would have displaced, or they cannot
     * tell a freeze they forgot about from a file the package has never proposed to
     * touch.
     *
     * @param list<PlannedOperation> $plan
     */
    private function refuseTarget(TargetState $state, array &$plan, WriteOutcome $outcome): WriteOutcome
    {
        $result = ReportedValue::unavailable($outcome->reason);

        foreach ($this->planner->plan($state) as $planned) {
            $plan[] = $planned->withResult($result);
        }

        return $outcome;
    }

    /**
     * Each operation in turn, each one re-checked against the tree as it is now.
     *
     * The chain stops at the first operation that does not complete. Carrying on
     * would apply version 3 over bytes version 2 never reached, which is the one
     * thing a cumulative chain must not do.
     *
     * ## The pre-state is checked, not only the preconditions
     *
     * `$expected` is the state each operation is entitled to replace: for the first,
     * the bytes the walk read and recognised; after that, whatever its predecessor
     * left. The digest read here is compared with it before anything is written.
     * Without that comparison an apply would take whatever it found as permission,
     * and an edit somebody made between the walk and the lock would be backed up and
     * overwritten - which is the one thing ticket 34 refuses, and the walk cannot
     * refuse it on its own because it ran before the lock was taken.
     *
     * ## A step already in place does not undo a step this run wrote
     *
     * When the target advances under the chain after one of its steps wrote, the
     * outcome stays that write. Replaced by "nothing was written", it would deny a
     * backup this run made and count the target as unchanged in the run summary.
     *
     * @param list<PlannedOperation> $plan    Filled with what this chain executed.
     * @param list<string>           $backups Filled with the backups its operations
     *                                        preserved, which a refused step's
     *                                        computed destination never is.
     */
    private function performChain(TargetState $state, array &$plan, array &$backups): WriteOutcome
    {
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
                return $this->stopChain($state, $index, $plan, $line, $wrote, WriteOutcome::refused(\sprintf(
                    '%s was not performed: %s',
                    $operation->describe(),
                    \implode('; ', $line->refusals)
                )));
            }

            $displaced = $current->digest;

            if ($displaced !== $expected) {
                return $this->stopChain($state, $index, $plan, $line, $wrote, WriteOutcome::refused(\sprintf(
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
                return $this->stopChain($state, $index, $plan, null, $wrote, $last);
            }

            $wrote = $wrote || $last->changed;

            $expected = $operation->isCopy() ? $operation->payloadHash : null;
        }

        return $last ?? WriteOutcome::unchanged($state->reason);
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
     * An earlier step that wrote keeps the target changed. The refusal or fault
     * that stopped the chain is still what the outcome reports, but a run summary
     * that counted the target as unchanged would contradict the backup and the
     * event the earlier step left.
     *
     * @param list<PlannedOperation> $plan
     * @param PlannedOperation|null  $stopped The line that refused, when the refusal
     *                                        is the line's own rather than a fault
     *                                        raised by the write.
     * @param bool                   $wrote   Whether an earlier step of this chain
     *                                        changed the target.
     */
    private function stopChain(
        TargetState $state,
        int $index,
        array &$plan,
        ?PlannedOperation $stopped,
        bool $wrote,
        WriteOutcome $outcome,
    ): WriteOutcome {
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

        return $wrote ? $outcome->withEarlierChange() : $outcome;
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
     *
     * Read immediately before each operation, never carried from the walk. The
     * previous operation in this chain has just changed it, and an outside writer may
     * have changed it too.
     *
     * The three answers stay apart, the way `HashResult` keeps them apart everywhere
     * else. Collapsed into a nullable digest, "a file nobody can read" and "nothing
     * is there" land on the same plan line, and that line then says the operation
     * displaces nothing and needs no backup while the write layer refuses it for the
     * opposite reason.
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

    /**
     * One report line, with the backup field composed from the plan or from the run.
     *
     * A plan that was only computed names the destinations it would write. A plan an
     * apply executed names the backups the apply actually left, because a refused
     * step keeps its computed destination on its own line and that file was never
     * made.
     *
     * @param list<PlannedOperation> $plan
     * @param list<string>|null      $preservedBackups The backups an apply preserved
     *                                                 at this target, or null when
     *                                                 nothing was executed.
     */
    private function line(
        TargetState $state,
        array $plan,
        ?ReportedValue $outcome,
        ?array $preservedBackups = null,
    ): TargetReport {
        $destinations = $preservedBackups ?? [];

        if ($preservedBackups === null) {
            foreach ($plan as $planned) {
                if ($planned->backup->isKnown()) {
                    $destinations[] = (string) $planned->backup->value;
                }
            }
        }

        return new TargetReport(
            $state,
            $this->history->lastWriteFor($state->targetPath),
            $destinations === []
                ? ReportedValue::none(self::NO_PLAN_BACKUP)
                : ReportedValue::known(\implode(', ', $destinations)),
            $plan,
            $outcome
        );
    }

    /**
     * What recovery found, as the report's interruption field.
     *
     * Taken from recovery rather than from the write history, because recovery ran
     * with the run lock held and the history's own answer re-reads the run state,
     * which takes that lock again.
     */
    private function interruption(?RecoveryOutcome $recovered): ReportedValue
    {
        if ($recovered === null) {
            return $this->history->interruption();
        }

        return $recovered->interrupted
            ? ReportedValue::known($recovered->reason)
            : ReportedValue::none(self::NO_INTERRUPTION);
    }

    /**
     * Load, validate and walk, or hand back the faulted report that stopped it.
     *
     * @param RunReport|null $faulted Set when this returns null.
     *
     * @return array{ruleSet: RuleSet, values: EnvironmentValues, states: list<TargetState>}|null
     */
    private function walk(?string $targetPath, ?RunReport &$faulted): ?array
    {
        $checks       = OperationalChecks::none();
        $interruption = $this->history->interruption();
        $loaded       = $this->loader->load($this->paths->rules());

        // A set that did not load cleanly must not be validated as a merged set: a
        // file the schema rejected contributes no objects, so its payloads look
        // unreferenced and its half of a chain looks broken. Report the load
        // violations alone.
        if (!$loaded->isValid()) {
            $faulted = RunReport::faulted($loaded->violations, $checks, $interruption);

            return null;
        }

        $violations = $this->validator->validate($loaded->ruleSet);

        if ($violations !== []) {
            $faulted = RunReport::faulted($violations, $checks, $interruption);

            return null;
        }

        $values = $this->resolver->resolve($this->environment->raw());
        $states = $this->walker->walk($loaded->ruleSet, $values);

        // A single-target run is the whole walk, filtered. Selecting the objects
        // first would be faster and would answer a different question: a chain is
        // cumulative, and the state of one target is what the whole set says about
        // it. This is what makes a single-target run and a whole-set run agree.
        if ($targetPath !== null) {
            $states = \array_values(\array_filter(
                $states,
                static fn (TargetState $state): bool => $state->targetPath === $targetPath
            ));
        }

        return ['ruleSet' => $loaded->ruleSet, 'values' => $values, 'states' => $states];
    }

    /**
     * Run-level faults from the write-run summary, when they add something the
     * per-target outcomes do not already say.
     *
     * @param array<string, WriteOutcome> $outcomes
     *
     * @return list<string>
     */
    private static function runFaults(WriteOutcome $summary, array $outcomes): array
    {
        if (!$summary->isFaulted()) {
            return [];
        }

        if ($outcomes === []) {
            return [$summary->reason];
        }

        foreach ($outcomes as $outcome) {
            if ($outcome->reason === $summary->reason) {
                return [];
            }
        }

        if ($summary->reason === self::summarise($outcomes)->reason) {
            return [];
        }

        return [$summary->reason];
    }

    /**
     * One answer for the whole set, for the marker rules in `WriteRun`.
     *
     * @param array<string, WriteOutcome> $outcomes
     */
    private static function summarise(array $outcomes): WriteOutcome
    {
        $changed    = 0;
        $faulted    = 0;
        $intentLeft = false;
        $leftover   = null;

        foreach ($outcomes as $outcome) {
            $changed    += $outcome->changed ? 1 : 0;
            $faulted    += $outcome->isFaulted() ? 1 : 0;
            $intentLeft = $intentLeft || $outcome->intentLeft;
            $leftover ??= $outcome->leftover;
        }

        return WriteOutcome::summary($changed > 0, $intentLeft, $faulted > 0, \sprintf(
            '%d of %d targets were changed and %d faulted',
            $changed,
            \count($outcomes),
            $faulted
        ), $leftover);
    }

    /**
     * What one operation did, as the report's outcome field.
     *
     * A revert that succeeded carries decision 19's warning, and it belongs on the
     * line with the operation rather than among the run's faults: the operation was
     * not a fault, and a report that counted it as one would tell an operator their
     * revert had failed.
     */
    private static function outcomeValue(?WriteOutcome $outcome): ?ReportedValue
    {
        if ($outcome === null) {
            return null;
        }

        $reason = $outcome->warning === null
            ? $outcome->reason
            : \sprintf('%s. %s', $outcome->reason, $outcome->warning);

        return $outcome->isFaulted()
            ? ReportedValue::unavailable($reason)
            : ReportedValue::known($reason);
    }

    private function reach(string $point): ?bool
    {
        if ($this->checkpoint !== null) {
            ($this->checkpoint)($point, null);
        }

        return null;
    }
}
