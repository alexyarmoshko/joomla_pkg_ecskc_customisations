<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Operational;

/**
 * Supplies the raw extension records the package needs to observe its own delivery,
 * and maps none of them.
 *
 * The Joomla adapter reads `#__extensions`; a test returns literal values and needs
 * no database. As with the environment provider, the core derives every outcome, so
 * both run the same derivation.
 */
interface OperationalCheckProviderInterface
{
    /**
     * The `enabled` column of the `plg_system_ecskccustom` record, as the driver
     * supplies it, `null` when there is no such record, or the read failure.
     */
    public function systemPluginEnabled(): ExtensionRecordRead;
}
