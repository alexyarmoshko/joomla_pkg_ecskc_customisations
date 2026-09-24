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
 *
 * The report is the read-only run the display URL names in `run` and `path`. Both
 * are request parameters and not user state: the same URL always runs the same thing.
 * The one piece of user state is a write task's result, which is shown once.
 */
class TargetsModel extends ListModel
{
    private ?RunReport $report = null;

    private ?WriteResult $writeResult = null;

    private bool $writeResultTaken = false;

    /**
     * The requested run, done once per request however often the view asks.
     */
    public function getReport(): RunReport
    {
        return $this->report ??= $this->getRunRequest()->execute($this->runner());
    }

    /**
     * The result a write task left in the session, once. The stored copy is dropped
     * on the first read, so a reload runs the display URL's check instead.
     */
    public function takeWriteResult(): ?WriteResult
    {
        if (!$this->writeResultTaken) {
            $app = Factory::getApplication();

            $this->writeResult      = WriteResult::fromArray($app->getUserState(WriteResult::STATE_KEY));
            $this->writeResultTaken = true;

            $app->setUserState(WriteResult::STATE_KEY, null);
        }

        return $this->writeResult;
    }

    public function getRunRequest(): RunRequest
    {
        $request = $this->getState('run.request');

        return $request instanceof RunRequest ? $request : RunRequest::wholeSetCheck();
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

    /**
     * @param string|null $ordering
     * @param string|null $direction
     */
    protected function populateState($ordering = null, $direction = null)
    {
        parent::populateState($ordering, $direction);

        $input = Factory::getApplication()->getInput();

        // `raw`, because every other filter rewrites the value, and a rewritten
        // undeclared path can come out as a declared one.
        $this->setState(
            'run.request',
            RunRequest::fromInput($input->get('run', null, 'raw'), $input->get('path', null, 'raw'))
        );
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
