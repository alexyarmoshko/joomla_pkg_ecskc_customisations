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
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
use YakShaver\Component\ECSKCCustomisation\Administrator\Model\RunRequest;
use YakShaver\Component\ECSKCCustomisation\Administrator\Model\WriteResult;

/**
 * One row per target path the filters let through, drawn by the shared `rows`
 * partial. Each row links to its own check and dry run, and a dry run opens every
 * row's plan. A writer also gets the row's write buttons, each with a browser
 * prompt naming the operation and the path. The live report shows no backup line.
 *
 * @var \YakShaver\Component\ECSKCCustomisation\Administrator\View\Targets\HtmlView $this
 */

// Each button posts the row's path to one task through the write form. A frozen
// row offers unfreeze instead of freeze. Trusted HTML: every attribute value is
// escaped, and the label is a language string.
$writeButton = fn (string $operation, string $path): string => \sprintf(
    '<button type="submit" form="ecskc-write" formaction="%s" name="path" value="%s" data-ecskc-confirm="%s" class="btn btn-sm btn-outline-secondary me-1 mt-1">%s</button>',
    $this->taskUrl($operation),
    $this->escape($path),
    $this->escape(Text::sprintf('COM_ECSKCCUSTOM_CONFIRM_' . \strtoupper($operation), $path)),
    Text::_('COM_ECSKCCUSTOM_ROW_' . \strtoupper($operation))
);

$this->rows = [];

foreach ($this->items as $target) {
    $this->rows[] = \array_replace(WriteResult::row($target), ['backup' => null]);
}

$this->rowsOpen    = $this->run->isDryRun();
$this->rowControls = function (array $row) use ($writeButton): string {
    \ob_start();
    ?>
    <p class="ecskc-row-runs small mb-0">
        <a href="<?php echo $this->runUrl(RunRequest::CHECK, $row['path']); ?>"><?php echo Text::_('COM_ECSKCCUSTOM_ROW_CHECK'); ?></a>
        <a href="<?php echo $this->runUrl(RunRequest::DRY_RUN, $row['path']); ?>" class="ms-2"><?php echo Text::_('COM_ECSKCCUSTOM_ROW_DRY_RUN'); ?></a>
    </p>
    <?php if ($this->canWrite) : ?>
        <p class="ecskc-row-writes mb-0">
            <?php echo $writeButton(WriteResult::APPLY, $row['path']); ?>
            <?php echo $writeButton(WriteResult::REVERT, $row['path']); ?>
            <?php echo $writeButton(WriteResult::REDO, $row['path']); ?>
            <?php echo $writeButton($row['status'] === TargetStatus::FROZEN ? WriteResult::UNFREEZE : WriteResult::FREEZE, $row['path']); ?>
        </p>
    <?php endif; ?>
    <?php
    return (string) \ob_get_clean();
};
?>
<?php if ($this->items === []) : ?>
    <div class="alert alert-info" id="ecskc-no-targets">
        <span class="icon-info-circle" aria-hidden="true"></span>
        <?php echo Text::_($this->report->targets() === [] ? 'COM_ECSKCCUSTOM_TARGETS_NONE' : 'COM_ECSKCCUSTOM_TARGETS_NO_MATCH'); ?>
    </div>
<?php else : ?>
    <?php echo $this->loadTemplate('rows'); ?>
<?php endif; ?>
