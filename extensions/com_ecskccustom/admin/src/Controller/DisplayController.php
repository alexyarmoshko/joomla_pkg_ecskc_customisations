<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Controller;

use Joomla\CMS\MVC\Controller\BaseController;

\defined('_JEXEC') or die;

/**
 * Opening the component shows the target report.
 */
class DisplayController extends BaseController
{
    /**
     * @var string
     */
    protected $default_view = 'targets';
}
