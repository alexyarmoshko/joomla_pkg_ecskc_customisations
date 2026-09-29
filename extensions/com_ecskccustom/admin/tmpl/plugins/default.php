<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

/**
 * The fault, alone, or the search tools, the list and its footer. The form carries
 * the sort, search and limit; no task reads it.
 *
 * @var \YakShaver\Component\ECSKCCustomisation\Administrator\View\Plugins\HtmlView $this
 */
?>
<form action="<?php echo Route::_('index.php?option=com_ecskccustom&view=plugins'); ?>" method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php if ($this->faults !== []) : ?>
                    <?php echo $this->loadTemplate('fault'); ?>
                <?php else : ?>
                    <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>
                    <?php echo $this->loadTemplate('list'); ?>
                <?php endif; ?>
                <input type="hidden" name="task" value="">
                <?php echo HTMLHelper::_('form.token'); ?>
            </div>
        </div>
    </div>
</form>
