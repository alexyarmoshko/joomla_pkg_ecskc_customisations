<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry;

/**
 * The one status each row carries, work item 7. Declared in the order Status sorts.
 */
enum EntryStatus: string
{
    case Enabled         = 'enabled';
    case Disabled        = 'disabled';
    case ManifestMissing = 'manifest_missing';
    case NotInstalled    = 'not_installed';

    public function languageKey(): string
    {
        return 'COM_ECSKCCUSTOM_PLUGINS_STATUS_' . \strtoupper($this->value);
    }
}
