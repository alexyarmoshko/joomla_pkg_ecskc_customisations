<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report;

/**
 * What one run of the runner returns. Everything the operator is shown comes from here.
 *
 * The plan's list, and where each part lives:
 *
 * | The report carries                      | Where it is                             |
 * |-----------------------------------------|-----------------------------------------|
 * | one state per target path               | `targets()`, one TargetReport each      |
 * | the object-by-object outcome            | `TargetReport::objectOutcomes()`        |
 * | the reason for an action, skip or fault | on the state, the object and the run    |
 * | the backup path                         | `TargetReport::$backup`                 |
 * | the last recorded package write         | `TargetReport::$lastPackageWrite`       |
 * | run-level counts                        | `$counts`                               |
 * | interrupted-run information             | `$interruption`                         |
 * | the operational-checks section          | `$checks`, present and empty in Phase 1 |
 *
 * ## A run that faults before it can walk
 *
 * A rule set that does not load, or that loads and fails merged-set validation, ends
 * the run: there is nothing to report state against, and reporting zero targets and
 * saying nothing else would read as a site with no customisations. So the report
 * carries run-level faults of its own, and a faulted report has no targets rather
 * than an empty list that means "all well". That is the package at fault, never a
 * target, which is the same line brief 5.3 draws for `faulted`.
 *
 * ## Interrupted runs
 *
 * Decision 16 of the brief: a marker on disk with a free lock means the previous run
 * was interrupted. Both are written by the write path, which is Phase 2, so in a
 * report-only phase the field says so rather than claiming a clean run it has no
 * record of. It is a ReportedValue for that reason - "no run was interrupted" and
 * "the package cannot tell" have to read differently.
 */
final class RunReport
{
    /**
     * @param list<TargetReport> $targets
     * @param list<string>       $faults
     */
    private function __construct(
        private readonly array $targets,
        public readonly RunCounts $counts,
        public readonly OperationalChecks $checks,
        public readonly ReportedValue $interruption,
        private readonly array $faults,
    ) {
    }

    /**
     * @param list<TargetReport> $targets
     */
    public static function of(
        array $targets,
        OperationalChecks $checks,
        ReportedValue $interruption,
    ): self {
        return new self($targets, RunCounts::of($targets, $checks), $checks, $interruption, []);
    }

    /**
     * A run that could not reach the site, and why.
     *
     * @param list<string> $faults
     */
    public static function faulted(
        array $faults,
        OperationalChecks $checks,
        ReportedValue $interruption,
    ): self {
        return new self([], RunCounts::of([], $checks), $checks, $interruption, \array_values($faults));
    }

    /**
     * @return list<TargetReport>
     */
    public function targets(): array
    {
        return $this->targets;
    }

    /**
     * @return list<string>
     */
    public function faults(): array
    {
        return $this->faults;
    }

    public function isFaulted(): bool
    {
        return $this->faults !== [];
    }

    public function wasInterrupted(): bool
    {
        return $this->interruption->isKnown();
    }
}
