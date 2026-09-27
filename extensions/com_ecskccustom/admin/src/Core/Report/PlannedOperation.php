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

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ProposedOperation;

/**
 * One operation of a run plan: what it would do, what stops it, and what it did.
 *
 * `displaced` is "what this operation would replace". A create displaces nothing and
 * says so rather than leaving the field out, on the same rule the required fields
 * follow: an empty field and a field that means "nothing here" read alike and send
 * an operator to different places.
 */
final class PlannedOperation
{
    /** What a dry run's result field says, because it executed nothing. */
    public const NOT_EXECUTED = 'not done: a dry run changes nothing';

    /**
     * @param list<string> $refusals Reasons this operation cannot be performed,
     *                               empty when every precondition holds.
     */
    public function __construct(
        public readonly ProposedOperation $operation,
        public readonly ReportedValue $displaced,
        public readonly ReportedValue $backup,
        public readonly array $refusals,
        public readonly ReportedValue $result,
    ) {
    }

    /**
     * A step an apply found already in place: it displaced nothing and wrote no backup.
     *
     * Planned against the bytes it found, the line would name a backup destination
     * for a payload the package ships and claim the run displaced it, and the
     * target's backup field would then point at a file nobody wrote.
     */
    public static function alreadyInPlace(ProposedOperation $operation, string $reason): self
    {
        $nothing = ReportedValue::none('nothing, the file already had this content');

        return new self(
            $operation,
            $nothing,
            ReportedValue::none('none, nothing was overwritten'),
            [],
            ReportedValue::known($reason)
        );
    }

    /**
     * The same plan line with what an apply made of it.
     */
    public function withResult(ReportedValue $result): self
    {
        return new self(
            $this->operation,
            $this->displaced,
            $this->backup,
            $this->refusals,
            $result
        );
    }

    /**
     * Does every write precondition hold?
     */
    public function isPerformable(): bool
    {
        return $this->refusals === [];
    }

    /**
     * The line as a report renders it.
     */
    public function describe(): string
    {
        $description = \sprintf(
            '%s. Overwrites: %s. Backup: %s.',
            $this->operation->describe(),
            $this->displaced->describe(),
            $this->backup->describe()
        );

        if ($this->refusals !== []) {
            $description .= ' Refused: ' . \implode('; ', $this->refusals) . '.';
        }

        return $description . ' Result: ' . $this->result->describe();
    }
}
