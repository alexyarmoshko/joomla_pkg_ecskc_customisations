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
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Operational\SystemPluginCheck;

/**
 * The system plugin card is green only for `enabled`. An `unknown` outcome is red
 * like `disabled`, with the runner's reason, because the page cannot tell whether
 * the site loads override.css.
 *
 * @var \YakShaver\Component\ECSKCCustomisation\Administrator\View\Dashboard\HtmlView $this
 */

$plugin  = $this->systemPlugin;
$enabled = $plugin !== null && $plugin->outcome === SystemPluginCheck::ENABLED;
$label   = match ($plugin?->outcome) {
    SystemPluginCheck::ENABLED  => 'COM_ECSKCCUSTOM_SYSTEM_PLUGIN_ENABLED',
    SystemPluginCheck::DISABLED => 'COM_ECSKCCUSTOM_SYSTEM_PLUGIN_DISABLED',
    default                     => 'COM_ECSKCCUSTOM_SYSTEM_PLUGIN_UNKNOWN',
};
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
                        <li><?php echo Text::_('COM_ECSKCCUSTOM_FEATURE_PLUGINS'); ?></li>
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
            <?php if ($plugin !== null) : ?>
                <div class="card mt-3" id="ecskc-system-plugin">
                    <div class="card-header">
                        <h3 class="card-title"><?php echo Text::_('COM_ECSKCCUSTOM_SYSTEM_PLUGIN'); ?></h3>
                    </div>
                    <div class="card-body">
                        <p class="mb-0">
                            <?php echo Text::_('COM_ECSKCCUSTOM_SYSTEM_PLUGIN_NAME'); ?>
                            <span class="badge <?php echo $enabled ? 'bg-success' : 'bg-danger'; ?>"><?php echo Text::_($label); ?></span>
                        </p>
                        <?php if ($plugin->outcome === SystemPluginCheck::UNKNOWN) : ?>
                            <p class="mt-2 mb-0 small"><?php echo $this->escape($plugin->reason); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
