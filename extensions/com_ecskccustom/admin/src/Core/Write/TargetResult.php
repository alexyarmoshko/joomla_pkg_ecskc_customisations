<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\PlannedOperation;

/**
 * What a write run did at one target: its outcome, the plan it carried out, and the
 * backups that plan left preserved.
 */
final class TargetResult
{
    /**
     * @param list<PlannedOperation>|null $plan    The plan as executed, each line with
     *                                             its result. Null where the operation
     *                                             ran from no plan, as a freeze or a
     *                                             revert does, and the report plans the
     *                                             target again from the tree.
     * @param list<string>                $backups The backups this run preserved here.
     */
    public function __construct(
        public readonly WriteOutcome $outcome,
        public readonly ?array $plan,
        public readonly array $backups,
    ) {
    }
}
