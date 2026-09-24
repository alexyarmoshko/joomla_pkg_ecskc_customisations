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
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
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

$labels = [
    TargetReport::TARGET_PATH        => 'COM_ECSKCCUSTOM_FIELD_TARGET_PATH',
    TargetReport::EXPECTED_HASH      => 'COM_ECSKCCUSTOM_FIELD_EXPECTED_HASH',
    TargetReport::ACTUAL_HASH        => 'COM_ECSKCCUSTOM_FIELD_ACTUAL_HASH',
    TargetReport::LAST_PACKAGE_WRITE => 'COM_ECSKCCUSTOM_FIELD_LAST_PACKAGE_WRITE',
];

$field = fn (string $value): string => $value === ''
    ? Text::_('COM_ECSKCCUSTOM_FIELD_EMPTY')
    : $this->escape($value);

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
    <?php if ($result->faults !== []) : ?>
        <div class="alert alert-danger" id="ecskc-faults">
            <h2 class="alert-heading h4"><?php echo Text::_('COM_ECSKCCUSTOM_FAULTS_HEADING'); ?></h2>
            <ul class="mb-0">
                <?php foreach ($result->faults as $fault) : ?>
                    <li><?php echo $this->escape($fault); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if ($result->interruption !== null) : ?>
        <div class="alert alert-warning" id="ecskc-interruption">
            <h2 class="alert-heading h4"><?php echo Text::_('COM_ECSKCCUSTOM_INTERRUPTION_HEADING'); ?></h2>
            <p class="mb-0"><?php echo $this->escape($result->interruption); ?></p>
        </div>
    <?php endif; ?>
    <table class="table table-sm w-auto" id="ecskc-counts">
        <caption><?php echo Text::_('COM_ECSKCCUSTOM_COUNTS_CAPTION'); ?></caption>
        <thead>
            <tr>
                <?php foreach (\array_keys($result->counts) as $status) : ?>
                    <th scope="col"><?php echo $this->escape($status); ?></th>
                <?php endforeach; ?>
                <th scope="col"><?php echo Text::_('COM_ECSKCCUSTOM_COUNTS_TOTAL'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <?php foreach ($result->counts as $count) : ?>
                    <td><?php echo (int) $count; ?></td>
                <?php endforeach; ?>
                <td><?php echo (int) $result->total; ?></td>
            </tr>
        </tbody>
    </table>
    <?php if ($result->targets === []) : ?>
        <div class="alert alert-info" id="ecskc-no-targets">
            <span class="icon-info-circle" aria-hidden="true"></span>
            <?php echo Text::_('COM_ECSKCCUSTOM_TARGETS_NONE'); ?>
        </div>
    <?php else : ?>
        <table class="table" id="ecskc-targets">
            <caption class="visually-hidden"><?php echo Text::_('COM_ECSKCCUSTOM_TARGETS_CAPTION'); ?></caption>
            <thead>
                <tr>
                    <th scope="col"><?php echo Text::_('COM_ECSKCCUSTOM_HEADING_TARGET_PATH'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_ECSKCCUSTOM_HEADING_STATE'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_ECSKCCUSTOM_HEADING_REASON'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($result->targets as $target) : ?>
                    <tr data-status="<?php echo $this->escape($target['status']); ?>">
                        <th scope="row" class="text-break"><code><?php echo $field($target['path']); ?></code></th>
                        <td><?php echo $field($target['state']); ?></td>
                        <td>
                            <p class="ecskc-reason"><?php echo $field($target['reason']); ?></p>
                            <?php if ($target['status'] !== TargetStatus::OK) : ?>
                                <dl class="ecskc-required row mb-0">
                                    <?php foreach ($target['required'] as $name => $value) : ?>
                                        <dt class="col-sm-3"><?php echo isset($labels[$name]) ? Text::_($labels[$name]) : $this->escape($name); ?></dt>
                                        <dd class="col-sm-9"><?php echo $field($value); ?></dd>
                                    <?php endforeach; ?>
                                </dl>
                            <?php endif; ?>
                            <details class="mt-2" open>
                                <summary><?php echo Text::_('COM_ECSKCCUSTOM_DETAILS'); ?></summary>
                                <?php if ($target['objects'] !== []) : ?>
                                    <p class="fw-bold mb-1"><?php echo Text::_('COM_ECSKCCUSTOM_DETAILS_OBJECTS'); ?></p>
                                    <ul class="ecskc-objects">
                                        <?php foreach ($target['objects'] as $line) : ?>
                                            <li><?php echo $field($line); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <?php if ($target['plan'] !== []) : ?>
                                    <p class="fw-bold mb-1"><?php echo Text::_('COM_ECSKCCUSTOM_DETAILS_PLAN'); ?></p>
                                    <ul class="ecskc-plan">
                                        <?php foreach ($target['plan'] as $line) : ?>
                                            <li><?php echo $field($line); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <p class="fw-bold mb-1"><?php echo Text::_('COM_ECSKCCUSTOM_DETAILS_BACKUP'); ?></p>
                                <p class="ecskc-backup"><?php echo $field($target['backup']); ?></p>
                                <?php if ($target['outcome'] !== null) : ?>
                                    <p class="fw-bold mb-1"><?php echo Text::_('COM_ECSKCCUSTOM_DETAILS_OUTCOME'); ?></p>
                                    <p class="ecskc-outcome"><?php echo $field($target['outcome']); ?></p>
                                <?php endif; ?>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php echo $this->loadTemplate('writeform'); ?>
