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
 * Everything above the list. It describes the whole report, so the filters never
 * change it. Report text is the runner's, escaped (plan decision 48). The run
 * line names what ran and says that nothing was written.
 *
 * @var \YakShaver\Component\ECSKCCustomisation\Administrator\View\Targets\HtmlView $this
 */

$report = $this->report;
$run    = $this->run;

if ($run->path === null) {
    $scope = Text::_($run->isDryRun() ? 'COM_ECSKCCUSTOM_RUN_DRY_RUN_ALL' : 'COM_ECSKCCUSTOM_RUN_CHECK_ALL');
} else {
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
</div>
<?php if ($report->faults() !== []) : ?>
    <div class="alert alert-danger" id="ecskc-faults">
        <h2 class="alert-heading h4"><?php echo Text::_('COM_ECSKCCUSTOM_FAULTS_HEADING'); ?></h2>
        <ul class="mb-0">
            <?php foreach ($report->faults() as $fault) : ?>
                <li><?php echo $this->escape($fault); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<?php if ($report->wasInterrupted()) : ?>
    <div class="alert alert-warning" id="ecskc-interruption">
        <h2 class="alert-heading h4"><?php echo Text::_('COM_ECSKCCUSTOM_INTERRUPTION_HEADING'); ?></h2>
        <p class="mb-0"><?php echo $this->escape($report->interruption->describe()); ?></p>
    </div>
<?php endif; ?>
<table class="table table-sm w-auto" id="ecskc-counts">
    <caption><?php echo Text::_('COM_ECSKCCUSTOM_COUNTS_CAPTION'); ?></caption>
    <thead>
        <tr>
            <?php foreach (\array_keys($report->counts->byStatus) as $status) : ?>
                <th scope="col"><?php echo $this->escape($status); ?></th>
            <?php endforeach; ?>
            <th scope="col"><?php echo Text::_('COM_ECSKCCUSTOM_COUNTS_TOTAL'); ?></th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <?php foreach ($report->counts->byStatus as $count) : ?>
                <td><?php echo (int) $count; ?></td>
            <?php endforeach; ?>
            <td><?php echo (int) $report->counts->targets; ?></td>
        </tr>
    </tbody>
</table>
<div id="ecskc-checks">
    <?php if ($report->checks->isEmpty()) : ?>
        <p><?php echo Text::_('COM_ECSKCCUSTOM_CHECKS_NONE'); ?></p>
    <?php else : ?>
        <p><?php echo Text::sprintf('COM_ECSKCCUSTOM_CHECKS_SUMMARY', $report->counts->checks, $report->counts->notifiableChecks); ?></p>
        <ul>
            <?php foreach ($report->checks->all() as $check) : ?>
                <li><?php echo $this->escape($check->describe()); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
