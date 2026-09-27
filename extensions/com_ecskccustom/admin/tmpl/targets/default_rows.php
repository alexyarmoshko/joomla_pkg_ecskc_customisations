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
 * The target list both pages show, one card per row, from rows in the primitive
 * shape WriteResult stores. Every value is the runner's own text, escaped (plan
 * decision 48). The card header holds the path and the state; a row that is not
 * `ok` shows its expected hash, actual hash and last package write in the body, each
 * a value or the reason there is none (the Phase 3 exit criterion). The target path
 * field is not repeated there, because the header shows it.
 *
 * The page that loads this sets `rows`, `rowsOpen` for whether the details start
 * open, and `rowControls` where a row carries links or buttons under its path. A
 * row's `backup` is null where the page shows no backup line.
 *
 * @var \YakShaver\Component\ECSKCCustomisation\Administrator\View\Targets\HtmlView $this
 */

$labels = [
    TargetReport::EXPECTED_HASH      => 'COM_ECSKCCUSTOM_FIELD_EXPECTED_HASH',
    TargetReport::ACTUAL_HASH        => 'COM_ECSKCCUSTOM_FIELD_ACTUAL_HASH',
    TargetReport::LAST_PACKAGE_WRITE => 'COM_ECSKCCUSTOM_FIELD_LAST_PACKAGE_WRITE',
];

$hashes = [TargetReport::EXPECTED_HASH, TargetReport::ACTUAL_HASH];

$badges = [
    TargetStatus::OK             => 'text-bg-success',
    TargetStatus::ACTIONABLE     => 'text-bg-primary',
    TargetStatus::INCOMPLETE     => 'text-bg-warning',
    TargetStatus::FROZEN         => 'text-bg-warning',
    TargetStatus::BLOCKED        => 'text-bg-danger',
    TargetStatus::FAULTED        => 'text-bg-danger',
    TargetStatus::NOT_APPLICABLE => 'text-bg-secondary',
];

// A field is never left blank, even if the report hands over an empty string.
$field = fn (string $value): string => $value === ''
    ? Text::_('COM_ECSKCCUSTOM_FIELD_EMPTY')
    : $this->escape($value);

// Trusted HTML: the escaped path, with a break opportunity after each slash only.
$path = fn (string $value): string => \str_replace('/', '/<wbr>', $field($value));
?>
<div id="ecskc-targets">
    <h2 class="visually-hidden"><?php echo Text::_('COM_ECSKCCUSTOM_TARGETS_CAPTION'); ?></h2>
    <?php foreach ($this->rows as $row) : ?>
        <article class="card mb-3 ecskc-target" data-status="<?php echo $this->escape($row['status']); ?>">
            <div class="card-header">
                <?php // Atum makes a card header a flex row; one block inside keeps its lines stacked. ?>
                <div class="ecskc-card-head w-100 fw-normal">
                    <div class="d-flex align-items-start gap-2">
                        <h3 class="h6 mb-0 flex-grow-1 ecskc-path"><code><?php echo $path($row['path']); ?></code></h3>
                        <span class="badge <?php echo $badges[$row['status']] ?? 'text-bg-secondary'; ?> ecskc-state"><?php echo $field($row['state']); ?></span>
                    </div>
                    <?php if ($this->rowControls !== null) : ?>
                        <?php echo ($this->rowControls)($row); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <p class="ecskc-reason"><?php echo $field($row['reason']); ?></p>
                <?php if ($row['status'] !== TargetStatus::OK) : ?>
                    <dl class="ecskc-required row mb-0">
                        <?php foreach ($row['required'] as $name => $value) : ?>
                            <?php if ($name !== TargetReport::TARGET_PATH) : ?>
                                <dt class="col-md-3 col-xl-2"><?php echo isset($labels[$name]) ? Text::_($labels[$name]) : $this->escape($name); ?></dt>
                                <dd class="col-md-9 col-xl-10<?php echo \in_array($name, $hashes, true) ? ' font-monospace text-break' : ''; ?>"><?php echo $field($value); ?></dd>
                            <?php endif; ?>
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
            </div>
        </article>
    <?php endforeach; ?>
</div>
