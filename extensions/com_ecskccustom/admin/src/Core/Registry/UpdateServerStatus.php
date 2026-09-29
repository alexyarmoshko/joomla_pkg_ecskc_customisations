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
 * What the Update server cell says, work item 8.
 */
enum UpdateServerStatus: string
{
    case Registered    = 'registered';
    case Disabled      = 'disabled';
    case None          = 'none';
    case NotChecked    = 'not_checked';
    case NotApplicable = 'not_applicable';

    public function languageKey(): string
    {
        return match ($this) {
            self::NotChecked    => 'COM_ECSKCCUSTOM_PLUGINS_NOT_CHECKED',
            self::NotApplicable => 'COM_ECSKCCUSTOM_PLUGINS_NOT_APPLICABLE',
            default             => 'COM_ECSKCCUSTOM_PLUGINS_UPDATE_SERVER_' . \strtoupper($this->value),
        };
    }
}
