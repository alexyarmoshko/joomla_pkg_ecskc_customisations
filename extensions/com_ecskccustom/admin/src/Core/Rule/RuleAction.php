<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition\ConditionGroup;

/**
 * One action of an object: exactly one operation, either a copy or a removal.
 *
 * Decision 11 fixes one operation per action, and the schema enforces it with a
 * choice of exactly one element, so nothing here has to represent an action that
 * carries none or several.
 *
 * Conditions guard the operation and are the reason an object carries several
 * actions: one path at one revision doing one thing on one host and something else
 * elsewhere. They are held here unevaluated. Whether they hold is a property of the
 * environment a run finds, not of the rule set, so ConditionEvaluator answers that
 * and merged-set validation never asks.
 *
 * A null group is an action with no conditions, which the schema permits and which
 * always applies.
 */
final class RuleAction
{
    public const COPY   = 'copy';
    public const REMOVE = 'remove';

    private function __construct(
        public readonly string $operation,
        public readonly ?string $source,
        public readonly ?string $sourceHash,
        public readonly bool $backup,
        public readonly ?ConditionGroup $conditions,
    ) {
    }

    public static function copy(string $source, string $sourceHash, ?ConditionGroup $conditions = null): self
    {
        return new self(self::COPY, $source, $sourceHash, false, $conditions);
    }

    public static function remove(bool $backup, ?ConditionGroup $conditions = null): self
    {
        return new self(self::REMOVE, null, null, $backup, $conditions);
    }

    public function isCopy(): bool
    {
        return $this->operation === self::COPY;
    }
}
