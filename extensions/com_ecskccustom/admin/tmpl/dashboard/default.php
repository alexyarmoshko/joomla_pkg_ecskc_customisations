<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \YakShaver\Component\ECSKCCustomisation\Administrator\View\Dashboard\HtmlView $this */
?>
<div class="com-ecskccustom-dashboard">
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><?php echo Text::_('COM_ECSKCCUSTOM'); ?></h3>
                </div>
                <div class="card-body">
                    <p><?php echo Text::_('COM_ECSKCCUSTOM_DASHBOARD_DESC'); ?></p>
                    <ul>
                        <li><?php echo Text::_('COM_ECSKCCUSTOM_FEATURE_CHECK'); ?></li>
                        <li><?php echo Text::_('COM_ECSKCCUSTOM_FEATURE_REPORT'); ?></li>
                        <li><?php echo Text::_('COM_ECSKCCUSTOM_FEATURE_DRY_RUN'); ?></li>
                        <li><?php echo Text::_('COM_ECSKCCUSTOM_FEATURE_HISTORY'); ?></li>
                        <li><?php echo Text::_('COM_ECSKCCUSTOM_FEATURE_FREEZE'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><?php echo Text::_('COM_ECSKCCUSTOM_QUICK_INFO'); ?></h3>
                </div>
                <div class="card-body">
                    <dl>
                        <dt><?php echo Text::_('COM_ECSKCCUSTOM_VERSION'); ?></dt>
                        <dd><?php echo $this->escape($this->version); ?></dd>
                        <dt><?php echo Text::_('COM_ECSKCCUSTOM_AUTHOR'); ?></dt>
                        <dd><?php echo $this->escape($this->author); ?></dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>
