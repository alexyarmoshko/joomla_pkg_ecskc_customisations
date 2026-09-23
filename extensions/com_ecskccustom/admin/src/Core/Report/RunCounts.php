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

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;

/**
 * The run-level counts: how many managed paths are in each state, and how many
 * operational checks ran beside them.
 *
 * - Every one of the seven statuses is a key, with zero where nothing is in it. A
 *   renderer can then show a fixed row of counts rather than discovering which
 *   states happen to appear in this run.
 * - A frozen target counts as `frozen` and not as the state it would otherwise be.
 *   One status per path, and `frozen` is the one it is in.
 * - Checks are counted separately and never added to the target counts, so the
 *   target counts stay exactly the counts of managed paths. An operational check is
 *   not a target path.
 */
final class RunCounts
{
    /**
     * @param array<string, int> $byStatus
     */
    private function __construct(
        public readonly array $byStatus,
        public readonly int $targets,
        public readonly int $checks,
        public readonly int $notifiableChecks,
    ) {
    }

    /**
     * @param list<TargetReport> $targets
     */
    public static function of(array $targets, OperationalChecks $checks): self
    {
        $byStatus = \array_fill_keys(TargetStatus::ALL, 0);

        foreach ($targets as $target) {
            // A status outside the seven cannot be produced by the walk, and would be
            // a bug rather than a state. Counting it into a key of its own would hide
            // it in a summary line; this way the totals disagree and somebody looks.
            if (\array_key_exists($target->status(), $byStatus)) {
                ++$byStatus[$target->status()];
            }
        }

        return new self($byStatus, \count($targets), $checks->count(), \count($checks->notifiable()));
    }

    public function forStatus(string $status): int
    {
        return $this->byStatus[$status] ?? 0;
    }
}
