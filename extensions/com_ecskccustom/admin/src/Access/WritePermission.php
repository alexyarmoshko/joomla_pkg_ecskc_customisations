<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Access;

use Joomla\CMS\User\User;

\defined('_JEXEC') or die;

/**
 * The one check every write task makes, after the CSRF token (plan decision 47).
 *
 * `core.manage` is not checked here: the component dispatcher refuses a request
 * without it before any controller runs.
 */
final class WritePermission
{
    public const ACTION = 'ecskccustom.write';

    public const ASSET = 'com_ecskccustom';

    public static function allows(User $user): bool
    {
        return $user->authorise(self::ACTION, self::ASSET) === true;
    }
}
