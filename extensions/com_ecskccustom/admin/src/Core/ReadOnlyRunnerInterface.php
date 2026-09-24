<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;

/**
 * The runner's two reads, and nothing that writes.
 *
 * Code that must never reach a write run takes this type rather than Runner, so
 * the type itself rules the writes out.
 */
interface ReadOnlyRunnerInterface
{
    /**
     * @param string|null $targetPath One canonical target path, or null for the set.
     */
    public function check(?string $targetPath = null): RunReport;

    /**
     * @param string|null $targetPath One canonical target path, or null for the set.
     */
    public function dryRun(?string $targetPath = null): RunReport;
}
