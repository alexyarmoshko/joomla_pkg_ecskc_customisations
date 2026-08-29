<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment;

/**
 * Supplies the raw Joomla inputs, and resolves nothing.
 *
 * The Joomla adapter implements this; a test implements it with literal values and
 * needs no Joomla at all. That is the point of the seam: one derivation path,
 * exercised identically in both.
 */
interface EnvironmentProviderInterface
{
    public function raw(): RawEnvironment;
}
