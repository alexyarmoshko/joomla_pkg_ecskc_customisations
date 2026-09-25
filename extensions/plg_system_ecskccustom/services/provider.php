<?php

/**
 * ECSKC Customisations system plugin — service provider.
 *
 * @package     YakShaver\Plugin\System\ECSKCCustomisation
 * @subpackage  System
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use YakShaver\Plugin\System\ECSKCCustomisation\Extension\ECSKCCustomisationPlugin;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * The plugin needs nothing from the component, so nothing here boots it.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static fn (): PluginInterface => new ECSKCCustomisationPlugin(
                (array) PluginHelper::getPlugin('system', 'ecskccustom'),
                JPATH_ROOT
            )
        );
    }
};
