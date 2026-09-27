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

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\HashResult;

/**
 * The state of one target path after the walk, with the account underneath it.
 *
 * The two hashes are HashResult rather than strings because the runner contract
 * requires the field either way: every blocked, incomplete, faulted, refused and
 * skipped target carries an expected and an actual hash, and where one cannot be
 * computed the field carries the reason instead of a hash. A nullable string would
 * let a caller omit it and would say nothing about why.
 */
final class TargetState
{
    /**
     * The head of the chain is switched off, so what the rule set writes here is the
     * revision before it.
     */
    public const DISABLED = 'disabled';

    /**
     * The head of the chain is written for another environment, so what the rule set
     * writes here is the revision before it.
     */
    public const UNMET = 'unmet';

    /**
     * @param list<ObjectOutcome>     $objectOutcomes
     * @param list<ProposedOperation> $operations
     */
    private function __construct(
        public readonly string $targetPath,
        public readonly string $status,
        public readonly string $reason,
        public readonly HashResult $expected,
        public readonly HashResult $actual,
        private readonly array $objectOutcomes,
        private readonly array $operations,
        public readonly ?string $qualifier = null,
        private readonly ?self $underlying = null,
    ) {
    }

    /**
     * @param list<ObjectOutcome>     $objectOutcomes
     * @param list<ProposedOperation> $operations
     */
    public static function of(
        string $targetPath,
        string $status,
        string $reason,
        HashResult $expected,
        HashResult $actual,
        array $objectOutcomes = [],
        array $operations = [],
        ?string $qualifier = null,
    ): self {
        return new self(
            $targetPath,
            $status,
            $reason,
            $expected,
            $actual,
            $objectOutcomes,
            $operations,
            $qualifier
        );
    }

    /**
     * The state this target would report if the operator released it.
     */
    public static function frozen(self $underlying): self
    {
        return new self(
            $underlying->targetPath,
            TargetStatus::FROZEN,
            \sprintf('the path is frozen, and would otherwise be %s', $underlying->describe()),
            $underlying->expected,
            $underlying->actual,
            $underlying->objectOutcomes,
            [],
            null,
            $underlying
        );
    }

    /**
     * @return list<ObjectOutcome>
     */
    public function objectOutcomes(): array
    {
        return $this->objectOutcomes;
    }

    /**
     * What a later phase would execute. Empty on a frozen path, by construction.
     *
     * @return list<ProposedOperation>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    public function isFrozen(): bool
    {
        return $this->underlying !== null;
    }

    /**
     * The state the walk found under the freeze, or null for a target that is not
     * frozen. The report shows only its status, through `describe()`.
     */
    public function underlying(): ?self
    {
        return $this->underlying;
    }

    /**
     * The status as a report renders it, with its qualifier where it has one.
     *
     * `ok (disabled)`, `ok (unmet)`, `frozen (would be actionable)`. The status itself
     * stays one of the seven, so a caller that switches on it needs no new case, and
     * the reason an operator has to read is still on the line.
     */
    public function describe(): string
    {
        if ($this->underlying !== null) {
            return \sprintf('frozen (would be %s)', $this->underlying->describe());
        }

        if ($this->qualifier !== null) {
            return \sprintf('%s (%s)', $this->status, $this->qualifier);
        }

        return $this->status;
    }
}
