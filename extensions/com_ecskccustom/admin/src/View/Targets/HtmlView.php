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
use Joomla\CMS\Toolbar\ToolbarHelper;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Model\TargetsModel;

\defined('_JEXEC') or die;

/**
 * One runner report, read-only. The summary describes the whole report; the rows
 * are what the filters let through.
 */
class HtmlView extends BaseHtmlView
{
    public RunReport $report;

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

        $this->report        = $model->getReport();
        $this->items         = $model->getItems();
        $this->filterForm    = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();

        $this->addToolbar();

        parent::display($tpl);
    }

    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_ECSKCCUSTOM'), 'wrench');

        $user = $this->getCurrentUser();

        if ($user->authorise('core.admin', 'com_ecskccustom') || $user->authorise('core.options', 'com_ecskccustom')) {
            $this->getDocument()->getToolbar()->preferences('com_ecskccustom');
        }
    }
}
