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
use YakShaver\Component\ECSKCCustomisation\Administrator\Model\WriteResult;

/**
 * Everything above the list. It describes the whole report, so the filters never
 * change it. Report text is the runner's, escaped (plan decision 48). The run
 * line names what ran and says that nothing was written. A whole-set dry run is
 * also where a writer confirms the whole-set apply (plan decision 49).
 *
 * @var \YakShaver\Component\ECSKCCustomisation\Administrator\View\Targets\HtmlView $this
 */

$report = $this->report;
$run    = $this->run;

$this->counts = [
    'faults'           => $report->faults(),
    'interruption'     => $report->wasInterrupted() ? $report->interruption->describe() : null,
    // A check or dry run leaves an interruption for the next write to recover.
    'interruptionNext' => 'COM_ECSKCCUSTOM_INTERRUPTION_NEXT',
    'byStatus'         => $report->counts->byStatus,
    'total'            => $report->counts->targets,
];

if ($run->path === null) {
    $scope = Text::_($run->isDryRun() ? 'COM_ECSKCCUSTOM_RUN_DRY_RUN_ALL' : 'COM_ECSKCCUSTOM_RUN_CHECK_ALL');
} else {
    // Trusted HTML: the language string plus a <code> element around the escaped path.
    $scope = Text::sprintf(
        $run->isDryRun() ? 'COM_ECSKCCUSTOM_RUN_DRY_RUN_PATH' : 'COM_ECSKCCUSTOM_RUN_CHECK_PATH',
        '<code>' . $this->escape($run->path) . '</code>'
    );
}
?>
<?php if ($run->notice !== null) : ?>
    <div class="alert alert-warning" id="ecskc-run-notice">
        <?php echo Text::_($run->notice); ?>
    </div>
<?php endif; ?>
<div class="alert alert-info" id="ecskc-run">
    <p class="mb-0"><?php echo $scope; ?></p>
    <?php if ($run->path !== null) : ?>
        <p class="mb-0"><a href="<?php echo $this->runUrl($run->run); ?>"><?php echo Text::_('COM_ECSKCCUSTOM_RUN_ALL_LINK'); ?></a></p>
    <?php endif; ?>
    <p class="mb-0 fw-bold"><?php echo Text::_($run->isDryRun() ? 'COM_ECSKCCUSTOM_RUN_DRY_RUN_NOTHING_WRITTEN' : 'COM_ECSKCCUSTOM_RUN_CHECK_NOTHING_WRITTEN'); ?></p>
    <?php if ($this->canWrite && $run->isDryRun() && $run->path === null) : ?>
        <p class="mt-3 mb-2"><?php echo Text::_('COM_ECSKCCUSTOM_APPLY_PLAN_DESC'); ?></p>
        <button type="submit" form="ecskc-write" formaction="<?php echo $this->taskUrl(WriteResult::APPLY_ALL); ?>" name="<?php echo WriteResult::CONFIRM_FIELD; ?>" value="1" class="btn btn-primary" id="ecskc-apply-plan">
            <span class="icon-check" aria-hidden="true"></span>
            <?php echo Text::_('COM_ECSKCCUSTOM_APPLY_PLAN'); ?>
        </button>
    <?php endif; ?>
</div>
<?php echo $this->loadTemplate('counts'); ?>
