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

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ObjectOutcome;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ProposedOperation;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetState;

/**
 * One line of the report: what the walk found at a target, plus what the walk cannot know.
 *
 * ## Why this wraps the walk's state instead of extending it
 *
 * The walk reads bytes. The last package write and the backup location are read from
 * the event log and the backup tree, which are state records rather than site files,
 * and giving ObjectWalker a second source to consult would put the whole event log
 * behind a class whose job is comparing hashes. So the walk answers what it measured
 * and the report layer composes the rest onto it.
 *
 * The four fields the contract makes mandatory are therefore present by construction
 * rather than by discipline. Every `blocked`, `incomplete`, `faulted`, refused and
 * skipped target carries the target path, the expected hash, the actual hash and the
 * last package write, and where a hash cannot be computed the field holds the reason
 * instead. HashResult and ReportedValue each hold one or the other, and neither is
 * nullable here, so there is no way to build a line that omits one. `requiredFields()`
 * is what a renderer asks for rather than reaching into the state itself.
 *
 * ## The backup field
 *
 * "The backup path when the operation preserves displaced content." A check performs
 * no operation and displaces nothing, so in a report-only run the field carries that
 * as its reason. A dry run and an apply fill it from the plan: where a chain
 * preserves content at more than one step, it names every destination, because an
 * operator judging whether an apply is safe needs to see all of them and not the
 * first.
 *
 * ## The plan and the outcome
 *
 * `plan()` is what a dry run or an apply worked out for this target, one line per
 * operation, with the write preconditions already checked. It is empty for a check,
 * which proposes nothing to perform, and for a frozen target, which proposes
 * nothing at all.
 *
 * `outcome` is what the run did here. A check and a dry run say they performed
 * nothing; an apply says what happened, including that it refused. It is separate
 * from the state because the state is what the tree holds afterwards and the outcome
 * is how it got there - a target that was already `ok` and a target this run carried
 * to `ok` read identically on the state alone.
 */
final class TargetReport
{
    public const TARGET_PATH        = 'target path';
    public const EXPECTED_HASH      = 'expected hash';
    public const ACTUAL_HASH        = 'actual hash';
    public const LAST_PACKAGE_WRITE = 'last package write';

    /**
     * The fields every non-`ok` line has to show, in the order an operator reads them.
     */
    public const REQUIRED_FIELDS = [
        self::TARGET_PATH,
        self::EXPECTED_HASH,
        self::ACTUAL_HASH,
        self::LAST_PACKAGE_WRITE,
    ];

    /** What the outcome field says for a run that performs nothing. */
    public const NOT_ATTEMPTED = 'this run performed no operation at this target';

    /**
     * @param list<PlannedOperation> $plan
     */
    public function __construct(
        public readonly TargetState $state,
        public readonly ReportedValue $lastPackageWrite,
        public readonly ReportedValue $backup,
        private readonly array $plan = [],
        public readonly ?ReportedValue $outcome = null,
    ) {
    }

    /**
     * What a dry run or an apply worked out for this target, operation by operation.
     *
     * @return list<PlannedOperation>
     */
    public function plan(): array
    {
        return $this->plan;
    }

    /**
     * What this run did here, or that it did nothing.
     */
    public function outcome(): ReportedValue
    {
        return $this->outcome ?? ReportedValue::none(self::NOT_ATTEMPTED);
    }

    public function targetPath(): string
    {
        return $this->state->targetPath;
    }

    /**
     * One of the seven statuses, never the qualified form.
     *
     * A caller counting or filtering wants the status alone; describe() is for the
     * operator, who needs to read `ok (disabled)` and `frozen (would be actionable)`.
     */
    public function status(): string
    {
        return $this->state->status;
    }

    public function describe(): string
    {
        return $this->state->describe();
    }

    public function reason(): string
    {
        return $this->state->reason;
    }

    /**
     * The object-by-object account underneath the status.
     *
     * @return list<ObjectOutcome>
     */
    public function objectOutcomes(): array
    {
        return $this->state->objectOutcomes();
    }

    /**
     * @return list<ProposedOperation>
     */
    public function operations(): array
    {
        return $this->state->operations();
    }

    /**
     * The mandatory fields, rendered. Each is a value or the reason there is none.
     *
     * @return array<string, string>
     */
    public function requiredFields(): array
    {
        return [
            self::TARGET_PATH        => $this->state->targetPath,
            self::EXPECTED_HASH      => $this->state->expected->describe(),
            self::ACTUAL_HASH        => $this->state->actual->describe(),
            self::LAST_PACKAGE_WRITE => $this->lastPackageWrite->describe(),
        ];
    }
}
