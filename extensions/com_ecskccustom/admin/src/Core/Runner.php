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
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Operational\OperationalCheckProviderInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Operational\SystemPluginCheck;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path\PathPolicy;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalCheck;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalChecks;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\PlannedOperation;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\ReportedValue;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\MergedSetValidator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleLoader;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleSet;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteHistoryInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ObjectWalker;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetState;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\ApplyExecutor;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\Freezer;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\RecoveryOutcome;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\Reverter;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\RunRecovery;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\TargetResult;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\WriteOutcome;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\WritePlanner;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\WriteRun;

/**
 * The one entry point into the core, and the only thing a Joomla adapter talks to.
 */
final class Runner implements ReadOnlyRunnerInterface, WriteRunnerInterface
{
    /**
     * A check displaces nothing, so there is no backup to name.
     *
     * The field is carried all the same. The contract requires it wherever an
     * operation preserves displaced content, and a line that dropped it in a
     * report-only run would look like a line that had nothing to say.
     */
    private const NO_BACKUP = 'no backup: a check changes nothing';

    private const NO_PLAN_BACKUP = 'no backup: nothing at this path would be overwritten';

    /**
     * A plan can overwrite and still keep no copy, as a removal with `backup="false"` does,
     * or when the current file could not be read. Its lines say why.
     */
    private const NO_PLAN_BACKUP_KEPT = 'no backup: the planned change keeps no copy of this file';

    /**
     * A run that wrote and kept no copy. Its line may carry the next apply's plan, whose
     * destinations stay on the operation lines and say nothing about this run.
     */
    private const NO_RUN_BACKUP = 'no backup: this run kept no copy of this file';

    private const NO_INTERRUPTION = 'no earlier write run was interrupted';

    private const NOT_WALKED_AGAIN = 'the files could not be checked again after this run, so %s is '
        . 'shown with this run\'s result and plan: %s';

    private const PLANNED_BEFORE_WALK = '%s, as this run planned it: %s';

    private const UNDECLARED = 'the package does not manage %s, so nothing was done';

    private readonly WritePlanner $planner;

    private readonly RunRecovery $recovery;

    private readonly Freezer $freezer;

    private readonly Reverter $reverter;

    private readonly WriteRun $writeRun;

    private readonly ApplyExecutor $executor;

    private readonly SystemPluginCheck $systemPlugin;

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
        private readonly OperationalCheckProviderInterface $operational,
        private readonly EnvironmentResolver $resolver,
        private readonly ObjectWalker $walker,
        private readonly WriteHistoryInterface $history,
        private readonly PathPolicy $policy,
        private readonly ?Closure $checkpoint = null,
    ) {
        $this->planner  = new WritePlanner($paths, $policy);
        $this->recovery = new RunRecovery($paths, $policy);
        $this->writeRun = new WriteRun($paths, 'no file was changed', $this->recovery);
        $this->executor = new ApplyExecutor($paths, $policy, $this->planner, $this->checkpoint);
        $this->freezer  = new Freezer(
            $paths,
            $this->checkpoint === null ? null : fn (string $point): null => $this->reach($point),
            $this->recovery
        );
        $this->reverter = new Reverter($paths, $policy, $walker, $this->checkpoint, $this->recovery);

        $this->systemPlugin = new SystemPluginCheck();
    }

    /**
     * Read the state of every managed path, or of one, and report it. Writes nothing.
     *
     * @param string|null $targetPath One canonical target path, or null for the set.
     */
    public function check(?string $targetPath = null): RunReport
    {
        $checks  = $this->operationalChecks();
        $faulted = null;
        $walk    = $this->walk($targetPath, $faulted, $checks);

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
            $checks,
            $this->history->interruption(),
            $this->history->recordFaults()
        );
    }

    /**
     * Work out what an apply would do, and prove it could do it. Writes nothing.
     *
     * **No managed target, backup, freeze marker, run marker, write intent or event
     * log is changed, and the exclusive write-run lock is not taken.** Reading the
     * run state may hold a shared lock on `run.lock` for that read alone. Everything
     * here is a read: the payloads are hashed where they lie, the permissions are
     * asked of directories that already exist, and the backup destination is
     * computed from the displaced digest rather than created.
     *
     * @param string|null $targetPath One canonical target path, or null for the set.
     */
    public function dryRun(?string $targetPath = null): RunReport
    {
        $checks  = $this->operationalChecks();
        $faulted = null;
        $walk    = $this->walk($targetPath, $faulted, $checks);

        if ($walk === null) {
            return $faulted;
        }

        $targets = [];

        foreach ($walk['states'] as $state) {
            $targets[] = $this->line($state, $this->planner->plan($state), null);
        }

        return RunReport::of(
            $targets,
            $checks,
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

        // WriteRun answers with the summary alone, so the per-target results leave
        // the closure through this one variable. It stays empty when the run never
        // started: a refused lock, or an interruption recovery could not settle.
        /** @var array<string, TargetResult> $results */
        $results = [];

        $summary = $this->writeRun->run(
            function () use ($walk, &$results): WriteOutcome {
                $results = $this->executor->applyAll($walk['states']);

                return self::summarise(self::outcomes($results));
            }
        );

        // Lock, recovery, and marker failures have no target line. Keep them in
        // run faults so the report does not hide an outstanding interruption.
        return $this->reportAfterWriting(
            $targetPath,
            $results,
            self::runFaults($summary, self::outcomes($results))
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
        // None of these operations writes a backup. The empty list says so, and keeps
        // the line from borrowing the destination the next apply would write.
        return $this->reportAfterWriting(
            $targetPath,
            [$targetPath => new TargetResult($outcome, null, [])],
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
     * A target with no result, or whose result carries no plan, is planned again from
     * the tree as it is now. A target with no result reports that plan's backup
     * destinations; one with a result reports the backups its run preserved.
     *
     * @param array<string, TargetResult> $results   What this run did, per target.
     * @param list<string>                $runFaults Faults about the run itself, rather
     *                                               than about one target.
     */
    private function reportAfterWriting(?string $targetPath, array $results, array $runFaults): RunReport
    {
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

            foreach ($results as $path => $result) {
                $outcome = $result->outcome;

                // No line carries the warning here, so the fault text does.
                $written[] = \sprintf(
                    self::NOT_WALKED_AGAIN,
                    $path,
                    $outcome->warning === null
                        ? $outcome->reason
                        : \sprintf('%s. %s', $outcome->reason, $outcome->warning)
                );

                foreach ($result->plan ?? [] as $planned) {
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
            $result = $results[$state->targetPath] ?? null;

            $targets[] = $this->line(
                $state,
                $result?->plan ?? $this->planner->plan($state),
                self::outcomeValue($result?->outcome),
                $result?->backups,
                $result?->outcome->warning
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
     * @param string|null            $warning          What the operation here has
     *                                                 to tell the operator, beside
     *                                                 its outcome.
     */
    private function line(
        TargetState $state,
        array $plan,
        ?ReportedValue $outcome,
        ?array $preservedBackups = null,
        ?string $warning = null,
    ): TargetReport {
        $destinations = $preservedBackups ?? [];
        $overwrites   = false;

        foreach ($plan as $planned) {
            $overwrites = $overwrites || (!$planned->backup->isKnown()
                && ($planned->displaced->isKnown() || !$planned->displaced->isDetermined()));

            if ($preservedBackups === null && $planned->backup->isKnown()) {
                $destinations[] = (string) $planned->backup->value;
            }
        }

        $noBackup = match (true) {
            $plan === []               => self::NO_PLAN_BACKUP,
            $preservedBackups !== null => self::NO_RUN_BACKUP,
            $overwrites                => self::NO_PLAN_BACKUP_KEPT,
            default                    => self::NO_PLAN_BACKUP,
        };

        return new TargetReport(
            $state,
            $this->history->lastWriteFor($state->targetPath),
            $destinations === []
                ? ReportedValue::none($noBackup)
                : ReportedValue::known(\implode(', ', $destinations)),
            $plan,
            $outcome,
            $warning
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
     * The operational-checks section a check or a dry run reports.
     *
     * It is read before the rule set is loaded and does not depend on it, so a run
     * that faults on its rules still says whether the site is loading override.css.
     * A write run leaves the section empty.
     */
    private function operationalChecks(): OperationalChecks
    {
        return OperationalChecks::of($this->systemPluginCheck());
    }

    /**
     * `system_plugin_enabled` on its own, for the dashboard. It loads no rule and
     * walks no path.
     */
    public function systemPluginCheck(): OperationalCheck
    {
        return $this->systemPlugin->derive($this->operational->systemPluginEnabled());
    }

    /**
     * Load, validate and walk, or hand back the faulted report that stopped it.
     *
     * @param RunReport|null         $faulted Set when this returns null.
     * @param OperationalChecks|null $checks  The section a faulted report carries.
     *                                        Empty when not given.
     *
     * @return array{ruleSet: RuleSet, values: EnvironmentValues, states: list<TargetState>}|null
     */
    private function walk(?string $targetPath, ?RunReport &$faulted, ?OperationalChecks $checks = null): ?array
    {
        $checks ??= OperationalChecks::none();

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

            // The path may come from request input. One the set does not declare, in
            // any spelling, is refused here, before any operation can take the write lock
            // or build a marker path out of it. Control bytes are escaped so the reason
            // stays one printable line.
            if ($states === []) {
                $faulted = RunReport::faulted(
                    [\sprintf(self::UNDECLARED, \addcslashes($targetPath, "\0..\37"))],
                    $checks,
                    $interruption
                );

                return null;
            }
        }

        return ['ruleSet' => $loaded->ruleSet, 'values' => $values, 'states' => $states];
    }

    /**
     * Each target's outcome, for the run-level answers below.
     *
     * @param array<string, TargetResult> $results
     *
     * @return array<string, WriteOutcome>
     */
    private static function outcomes(array $results): array
    {
        return \array_map(static fn (TargetResult $result): WriteOutcome => $result->outcome, $results);
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
     */
    private static function outcomeValue(?WriteOutcome $outcome): ?ReportedValue
    {
        if ($outcome === null) {
            return null;
        }

        return $outcome->isFaulted()
            ? ReportedValue::unavailable($outcome->reason)
            : ReportedValue::known($outcome->reason);
    }

    private function reach(string $point): ?bool
    {
        if ($this->checkpoint !== null) {
            ($this->checkpoint)($point, null);
        }

        return null;
    }
}
