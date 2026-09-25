<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Extension;

use Closure;
use Joomla\CMS\Extension\MVCComponent;
use LogicException;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\ReadOnlyRunnerInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;

\defined('_JEXEC') or die;

/**
 * The component itself, and the only way MVC code reaches the runner.
 *
 * The runner lives in the component's child container, which neither this class
 * nor the MVC factory exposes. The service provider hands over a closure that
 * resolves it there, so a model asks the booted component and constructs nothing
 * of the core itself (plan decision 5).
 */
class ECSKCCustomisationComponent extends MVCComponent
{
    /** @var (Closure(): Runner)|null */
    private ?Closure $runnerFactory = null;

    /**
     * @param Closure(): Runner $factory
     */
    public function setRunnerFactory(Closure $factory): void
    {
        $this->runnerFactory = $factory;
    }

    public function runner(): Runner
    {
        if ($this->runnerFactory === null) {
            throw new LogicException('The service provider set no runner factory on the component.');
        }

        return ($this->runnerFactory)();
    }

    /**
     * The same runner, typed so a caller can reach only the reads.
     *
     * The installer plugin takes this one, so no change to the plugin can start a write
     * run without first changing its type.
     */
    public function readOnlyRunner(): ReadOnlyRunnerInterface
    {
        return $this->runner();
    }
}
