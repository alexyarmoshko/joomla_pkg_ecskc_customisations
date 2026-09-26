<?php

/**
 * ECSKC Customisations installer plugin: service provider.
 *
 * @package     YakShaver\Plugin\Installer\ECSKCCustomisation
 * @subpackage  Installer
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use YakShaver\Plugin\Installer\ECSKCCustomisation\Extension\ECSKCCustomisationPlugin;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * The plugin boots the component when an event needs the runner, not here, so
     * a missing component costs an installer request nothing until then.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (): PluginInterface {
                $plugin = new ECSKCCustomisationPlugin(
                    (array) PluginHelper::getPlugin('installer', 'ecskccustom')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
