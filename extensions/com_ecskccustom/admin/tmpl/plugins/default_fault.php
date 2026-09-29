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

/**
 * Why there is no list, decision 53: a list built from a failed read would show
 * extensions as missing that nothing looked for.
 *
 * @var \YakShaver\Component\ECSKCCustomisation\Administrator\View\Plugins\HtmlView $this
 */
?>
<div class="alert alert-danger" id="ecskc-plugins-fault">
    <h2 class="alert-heading h4"><?php echo $this->escape(Text::_('COM_ECSKCCUSTOM_PLUGINS_FAULT_HEADING')); ?></h2>
    <p><?php echo $this->escape(Text::_('COM_ECSKCCUSTOM_PLUGINS_FAULT_INTRO')); ?></p>
    <ul class="mb-0">
        <?php foreach ($this->faults as $fault) : ?>
            <li><?php echo $this->escape($fault); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
