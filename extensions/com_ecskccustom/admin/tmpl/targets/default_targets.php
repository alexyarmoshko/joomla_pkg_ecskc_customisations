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

// Each button posts the row's path to one task through the write form. Its hint
// follows it in Atum's tooltip pattern, which shows a `role="tooltip"` sibling on
// hover and on focus; the counter keeps each hint's id unique on the page. A frozen
// row offers unfreeze instead of freeze. Trusted HTML: every attribute value is
// escaped, and the label and hint are language strings.
$hint        = 0;
$writeButton = function (string $operation, string $path) use (&$hint): string {
    $key = \strtoupper($operation);
    $id  = 'ecskc-hint-' . ++$hint;

    return \sprintf(
        '<button type="submit" form="ecskc-write" formaction="%s" name="path" value="%s" data-ecskc-confirm="%s" aria-describedby="%s" class="btn btn-sm btn-outline-secondary me-1 mt-1">%s</button>'
        . '<div role="tooltip" id="%s">%s</div>',
        $this->taskUrl($operation),
        $this->escape($path),
        $this->escape(Text::sprintf('COM_ECSKCCUSTOM_CONFIRM_' . $key, $path)),
        $id,
        Text::_('COM_ECSKCCUSTOM_ROW_' . $key),
        $id,
        Text::_('COM_ECSKCCUSTOM_HINT_' . $key)
    );
};

$this->rows = [];

foreach ($this->items as $target) {
    $this->rows[] = \array_replace(WriteResult::row($target), ['backup' => null]);
}

$this->rowsOpen    = $this->run->isDryRun();
$this->rowControls = function (array $row) use ($writeButton): string {
    \ob_start();
    ?>
    <p class="ecskc-row-runs small mt-2 mb-0">
        <a href="<?php echo $this->runUrl(RunRequest::CHECK, $row['path']); ?>"><?php echo Text::_('COM_ECSKCCUSTOM_ROW_CHECK'); ?></a>
        <a href="<?php echo $this->runUrl(RunRequest::DRY_RUN, $row['path']); ?>" class="ms-2"><?php echo Text::_('COM_ECSKCCUSTOM_ROW_DRY_RUN'); ?></a>
    </p>
    <?php if ($this->canWrite) : ?>
        <div class="ecskc-row-writes">
            <?php echo $writeButton(WriteResult::APPLY, $row['path']); ?>
            <?php echo $writeButton(WriteResult::REVERT, $row['path']); ?>
            <?php echo $writeButton(WriteResult::REDO, $row['path']); ?>
            <?php echo $writeButton($row['status'] === TargetStatus::FROZEN ? WriteResult::UNFREEZE : WriteResult::FREEZE, $row['path']); ?>
        </div>
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
