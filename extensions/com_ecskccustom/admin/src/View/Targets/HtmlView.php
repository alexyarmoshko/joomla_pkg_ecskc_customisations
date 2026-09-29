<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\View\Targets;

use Closure;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;
use YakShaver\Component\ECSKCCustomisation\Administrator\Access\WritePermission;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Model\RunRequest;
use YakShaver\Component\ECSKCCustomisation\Administrator\Model\TargetsModel;
use YakShaver\Component\ECSKCCustomisation\Administrator\Model\WriteResult;

\defined('_JEXEC') or die;

/**
 * One read-only runner report: a check or a dry run, of the set or of one path. The
 * summary describes the whole report; the rows are what the filters let through.
 *
 * After a write task, the page shows that task's stored result instead, once, in
 * the `result` layout, and runs nothing.
 */
class HtmlView extends BaseHtmlView
{
    /**
     * Asks before a write button submits, with the prompt the button carries. The
     * prompt guards against a slip. The task checks the token and the permission.
     */
    private const CONFIRM_SCRIPT = <<<'JS'
        document.addEventListener('submit', (event) => {
            const message = event.submitter ? event.submitter.dataset.ecskcConfirm : undefined;
            if (message !== undefined && !window.confirm(message)) {
                event.preventDefault();
            }
        });
        JS;

    public RunReport $report;

    public RunRequest $run;

    /** @var list<TargetReport> */
    public array $items = [];

    public ?Form $filterForm = null;

    public ?WriteResult $result = null;

    /** Whether to show write buttons. Hiding them is not the control; the task checks. */
    public bool $canWrite = false;

    /** @var array<string, mixed> */
    public array $activeFilters = [];

    /**
     * The shared `counts` partial's input, set by the layout that loads it.
     *
     * @var array{faults: list<string>, interruption: string|null, byStatus: array<string, int>, total: int}|array{}
     */
    public array $counts = [];

    /**
     * The shared `rows` partial's input, set by the layout that loads it: the rows in
     * WriteResult's primitive shape, with `backup` null where no backup line is shown.
     *
     * @var list<array<string, mixed>>
     */
    public array $rows = [];

    /** Whether each row's details start open. */
    public bool $rowsOpen = false;

    /**
     * Trusted HTML to put under a row's path, or null for none.
     *
     * @var (Closure(array<string, mixed>): string)|null
     */
    public ?Closure $rowControls = null;

    /**
     * @param string|null $tpl
     */
    public function display($tpl = null): void
    {
        /** @var TargetsModel $model */
        $model = $this->getModel();

        $this->canWrite = WritePermission::allows($this->getCurrentUser());
        $this->result   = $model->takeWriteResult();

        if ($this->canWrite) {
            $this->getDocument()->getWebAssetManager()->addInlineScript(self::CONFIRM_SCRIPT);
        }

        if ($this->result !== null) {
            $this->setLayout('result');
            $this->addToolbar();

            parent::display($tpl);

            return;
        }

        $this->run           = $model->getRunRequest();
        $this->report        = $model->getReport();
        $this->items         = $model->getItems();
        $this->filterForm    = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * The display URL of a read-only run, escaped for an HTML attribute.
     *
     * @param string      $run  RunRequest::CHECK or RunRequest::DRY_RUN.
     * @param string|null $path One target path, or null for the set.
     */
    public function runUrl(string $run, ?string $path = null): string
    {
        return Route::_(RunRequest::displayUrl($run, $path));
    }

    /**
     * The URL a write button posts to, escaped for an HTML attribute.
     *
     * @param string $operation One of WriteResult::OPERATIONS.
     */
    public function taskUrl(string $operation): string
    {
        return Route::_('index.php?option=com_ecskccustom&task=targets.' . $operation);
    }

    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_ECSKCCUSTOM_TARGETS_TITLE'), 'wrench');

        $toolbar = $this->getDocument()->getToolbar();

        // Links, not tasks: both runs are reads, so they need no token (ticket 39).
        $toolbar->linkButton('ecskc-check', 'COM_ECSKCCUSTOM_TOOLBAR_CHECK')
            ->icon('icon-search')
            ->url($this->runUrl(RunRequest::CHECK));
        $toolbar->linkButton('ecskc-dryrun', 'COM_ECSKCCUSTOM_TOOLBAR_DRY_RUN')
            ->icon('icon-eye')
            ->url($this->runUrl(RunRequest::DRY_RUN));

        // A link to the whole-set dry run, whose "Apply this plan" button is the
        // confirmation (plan decision 49).
        if ($this->canWrite) {
            $toolbar->linkButton('ecskc-apply', 'COM_ECSKCCUSTOM_TOOLBAR_APPLY')
                ->icon('icon-check')
                ->url($this->runUrl(RunRequest::DRY_RUN));
        }

        $user = $this->getCurrentUser();

        if ($user->authorise('core.admin', 'com_ecskccustom') || $user->authorise('core.options', 'com_ecskccustom')) {
            $toolbar->preferences('com_ecskccustom');
        }
    }
}
