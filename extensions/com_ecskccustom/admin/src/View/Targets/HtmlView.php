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

use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Model\RunRequest;
use YakShaver\Component\ECSKCCustomisation\Administrator\Model\TargetsModel;

\defined('_JEXEC') or die;

/**
 * One read-only runner report: a check or a dry run, of the set or of one path. The
 * summary describes the whole report; the rows are what the filters let through.
 */
class HtmlView extends BaseHtmlView
{
    public RunReport $report;

    public RunRequest $run;

    /** @var list<TargetReport> */
    public array $items = [];

    public ?Form $filterForm = null;

    /** @var array<string, mixed> */
    public array $activeFilters = [];

    /**
     * @param string|null $tpl
     */
    public function display($tpl = null): void
    {
        /** @var TargetsModel $model */
        $model = $this->getModel();

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

    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_ECSKCCUSTOM'), 'wrench');

        $toolbar = $this->getDocument()->getToolbar();

        // Links, not tasks: both runs are reads, so they need no token (ticket 39).
        $toolbar->linkButton('ecskc-check', 'COM_ECSKCCUSTOM_TOOLBAR_CHECK')
            ->icon('icon-search')
            ->url($this->runUrl(RunRequest::CHECK));
        $toolbar->linkButton('ecskc-dryrun', 'COM_ECSKCCUSTOM_TOOLBAR_DRY_RUN')
            ->icon('icon-eye')
            ->url($this->runUrl(RunRequest::DRY_RUN));

        $user = $this->getCurrentUser();

        if ($user->authorise('core.admin', 'com_ecskccustom') || $user->authorise('core.options', 'com_ecskccustom')) {
            $toolbar->preferences('com_ecskccustom');
        }
    }
}
