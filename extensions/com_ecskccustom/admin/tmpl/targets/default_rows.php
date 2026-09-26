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

/**
 * The target table both pages show, from rows in the primitive shape WriteResult
 * stores. Every value is the runner's own text, escaped (plan decision 48). A row
 * that is not `ok` shows the required fields in the report's order, each a value
 * or the reason there is none; this is a Phase 3 exit criterion.
 *
 * The page that loads this sets `rows`, `rowsOpen` for whether the details start
 * open, and `rowControls` where a row carries links or buttons under its path. A
 * row's `backup` is null where the page shows no backup line.
 *
 * @var \YakShaver\Component\ECSKCCustomisation\Administrator\View\Targets\HtmlView $this
 */

$labels = [
    TargetReport::TARGET_PATH        => 'COM_ECSKCCUSTOM_FIELD_TARGET_PATH',
    TargetReport::EXPECTED_HASH      => 'COM_ECSKCCUSTOM_FIELD_EXPECTED_HASH',
    TargetReport::ACTUAL_HASH        => 'COM_ECSKCCUSTOM_FIELD_ACTUAL_HASH',
    TargetReport::LAST_PACKAGE_WRITE => 'COM_ECSKCCUSTOM_FIELD_LAST_PACKAGE_WRITE',
];

// A field is never left blank, even if the report hands over an empty string.
$field = fn (string $value): string => $value === ''
    ? Text::_('COM_ECSKCCUSTOM_FIELD_EMPTY')
    : $this->escape($value);
?>
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
        <?php foreach ($this->rows as $row) : ?>
            <tr data-status="<?php echo $this->escape($row['status']); ?>">
                <th scope="row" class="text-break">
                    <code><?php echo $field($row['path']); ?></code>
                    <?php if ($this->rowControls !== null) : ?>
                        <?php echo ($this->rowControls)($row); ?>
                    <?php endif; ?>
                </th>
                <td><?php echo $field($row['state']); ?></td>
                <td>
                    <p class="ecskc-reason"><?php echo $field($row['reason']); ?></p>
                    <?php if ($row['status'] !== TargetStatus::OK) : ?>
                        <dl class="ecskc-required row mb-0">
                            <?php foreach ($row['required'] as $name => $value) : ?>
                                <dt class="col-sm-3"><?php echo isset($labels[$name]) ? Text::_($labels[$name]) : $this->escape($name); ?></dt>
                                <dd class="col-sm-9"><?php echo $field($value); ?></dd>
                            <?php endforeach; ?>
                        </dl>
                    <?php endif; ?>
                    <?php if ($row['objects'] !== [] || $row['plan'] !== [] || $row['backup'] !== null || $row['outcome'] !== null) : ?>
                        <details class="mt-2"<?php echo $this->rowsOpen ? ' open' : ''; ?>>
                            <summary><?php echo Text::_('COM_ECSKCCUSTOM_DETAILS'); ?></summary>
                            <?php if ($row['objects'] !== []) : ?>
                                <p class="fw-bold mb-1"><?php echo Text::_('COM_ECSKCCUSTOM_DETAILS_OBJECTS'); ?></p>
                                <ul class="ecskc-objects">
                                    <?php foreach ($row['objects'] as $line) : ?>
                                        <li><?php echo $field($line); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if ($row['plan'] !== []) : ?>
                                <p class="fw-bold mb-1"><?php echo Text::_('COM_ECSKCCUSTOM_DETAILS_PLAN'); ?></p>
                                <ul class="ecskc-plan">
                                    <?php foreach ($row['plan'] as $line) : ?>
                                        <li><?php echo $field($line); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if ($row['backup'] !== null) : ?>
                                <p class="fw-bold mb-1"><?php echo Text::_('COM_ECSKCCUSTOM_DETAILS_BACKUP'); ?></p>
                                <p class="ecskc-backup"><?php echo $field($row['backup']); ?></p>
                            <?php endif; ?>
                            <?php if ($row['outcome'] !== null) : ?>
                                <p class="fw-bold mb-1"><?php echo Text::_('COM_ECSKCCUSTOM_DETAILS_OUTCOME'); ?></p>
                                <p class="ecskc-outcome"><?php echo $field($row['outcome']); ?></p>
                            <?php endif; ?>
                        </details>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
