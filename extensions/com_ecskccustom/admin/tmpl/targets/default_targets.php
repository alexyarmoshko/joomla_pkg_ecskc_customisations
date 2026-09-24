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
 * One row per target path the filters let through. A row that is not `ok` shows
 * the required fields in the report's order, each a value or the reason there is
 * none; this is a Phase 3 exit criterion. Each row links to its own check and
 * dry run, and a dry run opens every row's plan. A writer also gets the row's
 * write buttons, each with a browser prompt naming the operation and the path.
 *
 * @var \YakShaver\Component\ECSKCCustomisation\Administrator\View\Targets\HtmlView $this
 */

$labels = [
    TargetReport::TARGET_PATH        => 'COM_ECSKCCUSTOM_FIELD_TARGET_PATH',
    TargetReport::EXPECTED_HASH      => 'COM_ECSKCCUSTOM_FIELD_EXPECTED_HASH',
    TargetReport::ACTUAL_HASH        => 'COM_ECSKCCUSTOM_FIELD_ACTUAL_HASH',
    TargetReport::LAST_PACKAGE_WRITE => 'COM_ECSKCCUSTOM_FIELD_LAST_PACKAGE_WRITE',
];

// Each button posts the row's path to one task through the write form. A frozen
// row offers unfreeze instead of freeze.
$writeButton = fn (string $operation, string $path): string => \sprintf(
    '<button type="submit" form="ecskc-write" formaction="%s" name="path" value="%s" data-ecskc-confirm="%s" class="btn btn-sm btn-outline-secondary me-1 mt-1">%s</button>',
    $this->taskUrl($operation),
    $this->escape($path),
    $this->escape(Text::sprintf('COM_ECSKCCUSTOM_CONFIRM_' . \strtoupper($operation), $path)),
    Text::_('COM_ECSKCCUSTOM_ROW_' . \strtoupper($operation))
);

// A field is never left blank, even if the report hands over an empty string.
$field = fn (string $value): string => $value === ''
    ? Text::_('COM_ECSKCCUSTOM_FIELD_EMPTY')
    : $this->escape($value);
?>
<?php if ($this->items === []) : ?>
    <div class="alert alert-info" id="ecskc-no-targets">
        <span class="icon-info-circle" aria-hidden="true"></span>
        <?php echo Text::_($this->report->targets() === [] ? 'COM_ECSKCCUSTOM_TARGETS_NONE' : 'COM_ECSKCCUSTOM_TARGETS_NO_MATCH'); ?>
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
            <?php foreach ($this->items as $target) : ?>
                <?php
                $outcomes = $target->objectOutcomes();
                $plan     = $target->plan();
                ?>
                <tr data-status="<?php echo $this->escape($target->status()); ?>">
                    <th scope="row" class="text-break">
                        <code><?php echo $field($target->targetPath()); ?></code>
                        <p class="ecskc-row-runs small mb-0">
                            <a href="<?php echo $this->runUrl(RunRequest::CHECK, $target->targetPath()); ?>"><?php echo Text::_('COM_ECSKCCUSTOM_ROW_CHECK'); ?></a>
                            <a href="<?php echo $this->runUrl(RunRequest::DRY_RUN, $target->targetPath()); ?>" class="ms-2"><?php echo Text::_('COM_ECSKCCUSTOM_ROW_DRY_RUN'); ?></a>
                        </p>
                        <?php if ($this->canWrite) : ?>
                            <p class="ecskc-row-writes mb-0">
                                <?php echo $writeButton(WriteResult::APPLY, $target->targetPath()); ?>
                                <?php echo $writeButton(WriteResult::REVERT, $target->targetPath()); ?>
                                <?php echo $writeButton(WriteResult::REDO, $target->targetPath()); ?>
                                <?php echo $writeButton($target->status() === TargetStatus::FROZEN ? WriteResult::UNFREEZE : WriteResult::FREEZE, $target->targetPath()); ?>
                            </p>
                        <?php endif; ?>
                    </th>
                    <td><?php echo $field($target->describe()); ?></td>
                    <td>
                        <p class="ecskc-reason"><?php echo $field($target->reason()); ?></p>
                        <?php if ($target->status() !== TargetStatus::OK) : ?>
                            <dl class="ecskc-required row mb-0">
                                <?php foreach ($target->requiredFields() as $name => $value) : ?>
                                    <dt class="col-sm-3"><?php echo isset($labels[$name]) ? Text::_($labels[$name]) : $this->escape($name); ?></dt>
                                    <dd class="col-sm-9"><?php echo $field($value); ?></dd>
                                <?php endforeach; ?>
                            </dl>
                        <?php endif; ?>
                        <?php if ($outcomes !== [] || $plan !== [] || $target->outcome !== null) : ?>
                            <details class="mt-2"<?php echo $this->run->isDryRun() ? ' open' : ''; ?>>
                                <summary><?php echo Text::_('COM_ECSKCCUSTOM_DETAILS'); ?></summary>
                                <?php if ($outcomes !== []) : ?>
                                    <p class="fw-bold mb-1"><?php echo Text::_('COM_ECSKCCUSTOM_DETAILS_OBJECTS'); ?></p>
                                    <ul class="ecskc-objects">
                                        <?php foreach ($outcomes as $outcome) : ?>
                                            <li><?php echo $field($outcome->describe()); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <?php if ($plan !== []) : ?>
                                    <p class="fw-bold mb-1"><?php echo Text::_('COM_ECSKCCUSTOM_DETAILS_PLAN'); ?></p>
                                    <ul class="ecskc-plan">
                                        <?php foreach ($plan as $operation) : ?>
                                            <li><?php echo $field($operation->describe()); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <?php if ($target->outcome !== null) : ?>
                                    <p class="fw-bold mb-1"><?php echo Text::_('COM_ECSKCCUSTOM_DETAILS_OUTCOME'); ?></p>
                                    <p class="ecskc-outcome"><?php echo $field($target->outcome->describe()); ?></p>
                                <?php endif; ?>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
