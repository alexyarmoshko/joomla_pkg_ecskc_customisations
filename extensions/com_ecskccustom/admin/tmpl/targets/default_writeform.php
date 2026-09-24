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
use YakShaver\Component\ECSKCCustomisation\Administrator\Model\RunRequest;

/**
 * The one form every write button submits. It sits outside adminForm, because forms
 * do not nest; each button names it with `form`, its task with `formaction`, and its
 * path as the button's own value. It carries the token and nothing else.
 *
 * @var \YakShaver\Component\ECSKCCustomisation\Administrator\View\Targets\HtmlView $this
 */
?>
<?php if ($this->canWrite) : ?>
    <form action="<?php echo $this->runUrl(RunRequest::CHECK); ?>" method="post" id="ecskc-write">
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>
<?php endif; ?>
