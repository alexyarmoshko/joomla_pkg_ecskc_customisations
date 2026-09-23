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
 * One condition: an environment attribute, how to match it, and what to match it
 * against.
 */
final class RuleCondition
{
    public const LITERAL = 'literal';
    public const REGEX   = 'regex';
    public const VERSION = 'version';

    public function __construct(
        public readonly string $type,
        public readonly string $attribute,
        public readonly string $operand,
        public readonly ?string $description = null,
    ) {
    }
}
