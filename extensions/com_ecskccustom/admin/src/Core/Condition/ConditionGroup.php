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
 * A group of conditions, holding when all of its members hold or when any one does.
 *
 * Groups nest, and a member is either a condition or another group. Mixed logic -
 * this host and either of those two versions - comes from nesting rather than from
 * a second element type, which is what keeps the condition language to one
 * construct with one setting.
 */
final class ConditionGroup
{
    public const ALL = 'all';
    public const ANY = 'any';

    /**
     * @param list<RuleCondition|ConditionGroup> $members
     */
    public function __construct(
        public readonly string $match,
        public readonly array $members,
    ) {
    }
}
