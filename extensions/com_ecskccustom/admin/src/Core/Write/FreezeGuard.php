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

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\FreezeMarkers;

/**
 * The check every write to a target consults before it changes anything there.
 *
 * Brief decision 19: a freeze prevents every write to a path. Apply, revert and redo
 * ask this with the run lock held, and a frozen path is skipped. The lock is what
 * stops a write that passed this check before a freeze returned, decision 30. This
 * check is what stops every write that starts after one.
 *
 * The answer is the reader's, `FreezeMarkers::isFrozen()`, so every marker shape that
 * reads as frozen also refuses a write. Only an unfreeze ends a freeze.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class FreezeGuard
{
    public function __construct(private readonly FreezeMarkers $markers)
    {
    }

    /**
     * Why no write may reach this target, or null when the guard admits one.
     *
     * @param string $targetPath The canonical root-relative target path.
     */
    public function refusal(string $targetPath): ?string
    {
        if (!$this->markers->isFrozen($targetPath)) {
            return null;
        }

        return \sprintf('%s is frozen, so nothing is written there until it is unfrozen', $targetPath);
    }
}
