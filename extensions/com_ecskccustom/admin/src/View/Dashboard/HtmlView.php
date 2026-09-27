<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\View\Dashboard;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalCheck;
use YakShaver\Component\ECSKCCustomisation\Administrator\Model\DashboardModel;

\defined('_JEXEC') or die;

/**
 * What the component does, which version is installed, and whether the system
 * plugin is enabled. It checks no managed path; the target report does that.
 */
class HtmlView extends BaseHtmlView
{
    public string $version = '';

    public string $author = '';

    public ?OperationalCheck $systemPlugin = null;

    /**
     * @param string|null $tpl
     */
    public function display($tpl = null): void
    {
        /** @var DashboardModel $model */
        $model = $this->getModel();
        $info  = $model->getComponentInfo();

        $this->version = $info['version'];
        $this->author  = $info['author'];

        $this->systemPlugin = $model->getSystemPluginCheck();

        $this->addToolbar();

        parent::display($tpl);
    }

    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_ECSKCCUSTOM_DASHBOARD_TITLE'), 'wrench');

        $user = $this->getCurrentUser();

        if ($user->authorise('core.admin', 'com_ecskccustom') || $user->authorise('core.options', 'com_ecskccustom')) {
            $this->getDocument()->getToolbar()->preferences('com_ecskccustom');
        }
    }
}
