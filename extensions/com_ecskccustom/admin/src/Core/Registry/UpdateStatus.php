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
 * What the Update cell says, work item 8. Available and Recorded carry a version.
 */
enum UpdateStatus: string
{
    case Available     = 'available';
    case Recorded      = 'recorded';
    case NoneRecorded  = 'none_recorded';
    case NotChecked    = 'not_checked';
    case NotApplicable = 'not_applicable';

    /**
     * Available and Recorded take the version as their one `%s` argument.
     */
    public function languageKey(): string
    {
        return match ($this) {
            self::NotChecked    => 'COM_ECSKCCUSTOM_PLUGINS_NOT_CHECKED',
            self::NotApplicable => 'COM_ECSKCCUSTOM_PLUGINS_NOT_APPLICABLE',
            default             => 'COM_ECSKCCUSTOM_PLUGINS_UPDATE_' . \strtoupper($this->value),
        };
    }
}
