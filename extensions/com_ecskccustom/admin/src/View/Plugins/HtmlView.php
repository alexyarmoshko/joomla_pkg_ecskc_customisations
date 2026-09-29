<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\View\Plugins;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Pagination\Pagination;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryResolver;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryRow;
use YakShaver\Component\ECSKCCustomisation\Administrator\Model\PluginLink;
use YakShaver\Component\ECSKCCustomisation\Administrator\Model\PluginsModel;

\defined('_JEXEC') or die;

/**
 * Our extensions as this site has them, read-only. There is no task behind this
 * view: every link goes to Joomla's or Community Builder's own screen.
 *
 * A registry that did not load or a read that failed shows the fault and no list,
 * decision 53.
 */
class HtmlView extends BaseHtmlView
{
    /** @var list<RegistryRow> */
    public array $items = [];

    /** @var list<string> Every reason there is no list; empty when there is one. */
    public array $faults = [];

    /** How many entries the registry resolved to, before the search. */
    public int $registered = 0;

    public ?Pagination $pagination = null;

    public ?Form $filterForm = null;

    /** @var array<string, mixed> */
    public array $activeFilters = [];

    public string $listOrder = RegistryResolver::DEFAULT_ORDERING;

    public string $listDirn = RegistryResolver::DEFAULT_DIRECTION;

    /**
     * @param string|null $tpl
     */
    public function display($tpl = null): void
    {
        /** @var PluginsModel $model */
        $model      = $this->getModel();
        $resolution = $model->getResolution();

        $this->faults = $resolution->faults;

        if ($resolution->isValid()) {
            // The headers and the footer follow the page, not the state asked for,
            // because the resolver replaces what it does not accept.
            $page = $model->getPage();

            $this->registered    = \count($resolution->rows);
            $this->items         = $page->rows;
            $this->listOrder     = $page->ordering;
            $this->listDirn      = $page->direction;
            $this->pagination    = $model->getPagination();
            $this->filterForm    = $model->getFilterForm();
            $this->activeFilters = $model->getActiveFilters();
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * Where the row's Name links, escaped for an HTML attribute, or null for no link.
     */
    public function nameUrl(RegistryRow $row): ?string
    {
        $url = PluginLink::url($row);

        return $url === null ? null : Route::_($url);
    }

    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_ECSKCCUSTOM_PLUGINS_TITLE'), 'wrench');

        $user = $this->getCurrentUser();

        if ($user->authorise('core.admin', 'com_ecskccustom') || $user->authorise('core.options', 'com_ecskccustom')) {
            $this->getDocument()->getToolbar()->preferences('com_ecskccustom');
        }
    }
}
