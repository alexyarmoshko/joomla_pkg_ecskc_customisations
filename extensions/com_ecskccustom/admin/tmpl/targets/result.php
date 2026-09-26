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
use YakShaver\Component\ECSKCCustomisation\Administrator\Model\RunRequest;
use YakShaver\Component\ECSKCCustomisation\Administrator\Model\WriteResult;

/**
 * What one write task did, from the copy it stored, shown once. Faults, the
 * interruption, and every row are the runner's own text, escaped (plan decision 48).
 * A lock that was refused, an intent left outstanding, and an interrupted run each
 * arrive here as the report worded them.
 *
 * @var \YakShaver\Component\ECSKCCustomisation\Administrator\View\Targets\HtmlView $this
 */

$result = $this->result;

$this->counts = [
    'faults'       => $result->faults,
    'interruption' => $result->interruption,
    'byStatus'     => $result->counts,
    'total'        => $result->total,
];

$this->rows        = $result->targets;
$this->rowsOpen    = true;
$this->rowControls = null;

// Trusted HTML: the language string plus a <code> element around the escaped path.
$heading = $result->path === null
    ? Text::_('COM_ECSKCCUSTOM_RESULT_' . \strtoupper($result->operation))
    : Text::sprintf('COM_ECSKCCUSTOM_RESULT_' . \strtoupper($result->operation), '<code>' . $this->escape($result->path) . '</code>');
?>
<div id="ecskc-result">
    <div class="alert alert-info" id="ecskc-run">
        <p class="mb-0"><?php echo $heading; ?></p>
        <p class="mb-0"><?php echo Text::_('COM_ECSKCCUSTOM_RESULT_SHOWN_ONCE'); ?></p>
        <p class="mb-0"><a href="<?php echo $this->runUrl(RunRequest::CHECK); ?>"><?php echo Text::_('COM_ECSKCCUSTOM_RESULT_FRESH_CHECK'); ?></a></p>
    </div>
    <?php if ($result->warning !== null) : ?>
        <div class="alert alert-warning" id="ecskc-revert-warning">
            <h2 class="alert-heading h4"><?php echo Text::_('COM_ECSKCCUSTOM_REVERT_WARNING_HEADING'); ?></h2>
            <p><?php echo $this->escape($result->warning); ?></p>
            <?php if ($this->canWrite && $result->path !== null) : ?>
                <button type="submit" form="ecskc-write" formaction="<?php echo $this->taskUrl(WriteResult::FREEZE); ?>" name="path" value="<?php echo $this->escape($result->path); ?>" data-ecskc-confirm="<?php echo $this->escape(Text::sprintf('COM_ECSKCCUSTOM_CONFIRM_FREEZE', $result->path)); ?>" class="btn btn-warning" id="ecskc-revert-freeze">
                    <span class="icon-lock" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ECSKCCUSTOM_ROW_FREEZE'); ?>
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php echo $this->loadTemplate('counts'); ?>
    <?php if ($result->targets === []) : ?>
        <div class="alert alert-info" id="ecskc-no-targets">
            <span class="icon-info-circle" aria-hidden="true"></span>
            <?php echo Text::_('COM_ECSKCCUSTOM_TARGETS_NONE'); ?>
        </div>
    <?php else : ?>
        <?php echo $this->loadTemplate('rows'); ?>
    <?php endif; ?>
</div>
<?php echo $this->loadTemplate('writeform'); ?>
