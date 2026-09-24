<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ListModel;
use UnexpectedValueException;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;
use YakShaver\Component\ECSKCCustomisation\Administrator\Extension\ECSKCCustomisationComponent;

\defined('_JEXEC') or die;

/**
 * One runner report as a list: one row per target path, narrowed by the filters.
 *
 * ListModel supplies the stateful filters and the filter form. The rows come from
 * the report and not from a query, as com_checkin's come from its table scan.
 */
class TargetsModel extends ListModel
{
    private ?RunReport $report = null;

    /**
     * The whole-set check, run once per request however often the view asks.
     */
    public function getReport(): RunReport
    {
        return $this->report ??= $this->runner()->check();
    }

    /**
     * @return list<TargetReport>
     */
    public function getItems()
    {
        $filter = new TargetFilter(
            (string) $this->getState('filter.status', ''),
            (string) $this->getState('filter.search', '')
        );

        return $filter->apply($this->getReport()->targets());
    }

    /**
     * The number of rows the filters let through, not the number of managed paths.
     */
    public function getTotal()
    {
        return \count($this->getItems());
    }

    private function runner(): Runner
    {
        $component = Factory::getApplication()->bootComponent('com_ecskccustom');

        if (!$component instanceof ECSKCCustomisationComponent) {
            throw new UnexpectedValueException('com_ecskccustom did not boot as ECSKCCustomisationComponent.');
        }

        return $component->runner();
    }
}
