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

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleAction;

/**
 * The outcome of evaluating conditions across an object's actions.
 *
 * An action may be selected (the first whose conditions held), unmet (no action
 * had conditions that hold in this environment), or faulted (at least one
 * condition could not be judged).
 */
final class ActionSelection
{
    public const SELECTED = 'selected';
    public const UNMET    = 'unmet';
    public const FAULTED  = 'faulted';

    private function __construct(
        public readonly string $status,
        public readonly ?RuleAction $action,
        public readonly ?string $faultReason,
    ) {
    }

    public static function selected(RuleAction $action): self
    {
        return new self(self::SELECTED, $action, null);
    }

    public static function unmet(): self
    {
        return new self(self::UNMET, null, null);
    }

    public static function faulted(string $reason): self
    {
        return new self(self::FAULTED, null, $reason);
    }

    public function isSelected(): bool
    {
        return $this->status === self::SELECTED;
    }

    public function isUnmet(): bool
    {
        return $this->status === self::UNMET;
    }

    public function isFaulted(): bool
    {
        return $this->status === self::FAULTED;
    }
}
