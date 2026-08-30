<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition;

/**
 * What an action's conditions decided: matched, not matched, or faulted.
 *
 * Three outcomes rather than a boolean, because the third is the one the design
 * turns on. "Not matched" means the rule set said this action does not apply here,
 * which is an ordinary answer and needs no explanation. "Faulted" means the package
 * could not tell, which is a refusal: the object writes nothing and reports why.
 * Collapsing the two into false is precisely the mistake the fault rule exists to
 * prevent - it turns "I could not determine the host" into "this is not that host".
 *
 * A faulted outcome carries every reason, not the first. All members of a group are
 * evaluated, so an operator sees each undeterminable attribute in one report rather
 * than one per run.
 */
final class ConditionOutcome
{
    public const MATCHED     = 'matched';
    public const NOT_MATCHED = 'not-matched';
    public const FAULTED     = 'faulted';

    /**
     * @param list<string> $reasons
     */
    private function __construct(
        public readonly string $status,
        public readonly array $reasons,
    ) {
    }

    public static function matched(): self
    {
        return new self(self::MATCHED, []);
    }

    public static function notMatched(): self
    {
        return new self(self::NOT_MATCHED, []);
    }

    /**
     * @param list<string> $reasons
     */
    public static function faulted(array $reasons): self
    {
        return new self(self::FAULTED, $reasons);
    }

    /**
     * True only for a match. A faulted outcome is not a match, which is what stops
     * an action whose conditions could not be judged from running.
     */
    public function isMatched(): bool
    {
        return $this->status === self::MATCHED;
    }

    public function isFaulted(): bool
    {
        return $this->status === self::FAULTED;
    }
}
