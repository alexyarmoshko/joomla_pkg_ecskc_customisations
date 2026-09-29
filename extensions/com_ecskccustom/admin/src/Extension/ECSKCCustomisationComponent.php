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
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryLoadResult;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryResolver;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;

\defined('_JEXEC') or die;

/**
 * The component itself, and the only way MVC code reaches the runner.
 *
 * The runner lives in the component's child container, which neither this class
 * nor the MVC factory exposes. The service provider hands over a closure that
 * resolves it there, so a model asks the booted component and constructs nothing
 * of the core itself (plan decision 5). The Plugins screen reaches the registry and
 * its resolver the same way.
 */
class ECSKCCustomisationComponent extends MVCComponent
{
    /** @var (Closure(): Runner)|null */
    private ?Closure $runnerFactory = null;

    /** @var (Closure(): RegistryLoadResult)|null */
    private ?Closure $registryFactory = null;

    /** @var (Closure(): RegistryResolver)|null */
    private ?Closure $resolverFactory = null;

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
     * The same runner, typed as the read-only interface.
     *
     * The installer plugin takes this one. The type is a static contract: the object
     * is still the concrete `Runner`, so it keeps the plugin to reads only for as long
     * as the plugin calls nothing outside the interface.
     */
    public function readOnlyRunner(): ReadOnlyRunnerInterface
    {
        return $this->runner();
    }

    /**
     * @param Closure(): RegistryLoadResult $registry The installed registry, loaded.
     * @param Closure(): RegistryResolver   $resolver
     */
    public function setRegistryFactories(Closure $registry, Closure $resolver): void
    {
        $this->registryFactory = $registry;
        $this->resolverFactory = $resolver;
    }

    /**
     * The installed registry, loaded and validated. The Plugins screen is its only
     * runtime reader.
     */
    public function registry(): RegistryLoadResult
    {
        if ($this->registryFactory === null) {
            throw new LogicException('The service provider set no registry factory on the component.');
        }

        return ($this->registryFactory)();
    }

    public function registryResolver(): RegistryResolver
    {
        if ($this->resolverFactory === null) {
            throw new LogicException('The service provider set no registry resolver factory on the component.');
        }

        return ($this->resolverFactory)();
    }
}
