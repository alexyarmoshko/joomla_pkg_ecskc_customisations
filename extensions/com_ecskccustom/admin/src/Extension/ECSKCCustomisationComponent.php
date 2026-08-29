<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Extension;

use Joomla\CMS\Extension\MVCComponent;

\defined('_JEXEC') or die;

/**
 * The component itself.
 *
 * Bare on purpose. Phase 1 needs a real installation and bootstrap path in DDEV
 * and nothing more; the views, controllers and permissions arrive in Phase 3.
 */
class ECSKCCustomisationComponent extends MVCComponent
{
}
