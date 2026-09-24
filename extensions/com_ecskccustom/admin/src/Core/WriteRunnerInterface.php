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
 * The runner's writes: the calls a write task may make, and only after its token
 * and permission checks have passed.
 */
interface WriteRunnerInterface
{
    /**
     * @param string|null $targetPath One canonical target path, or null for the set.
     */
    public function apply(?string $targetPath = null): RunReport;

    public function revert(string $targetPath): RunReport;

    public function redo(string $targetPath): RunReport;

    public function freeze(string $targetPath): RunReport;

    public function unfreeze(string $targetPath): RunReport;
}
