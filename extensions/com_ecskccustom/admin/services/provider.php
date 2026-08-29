<?php

/**
 * ECSKC Customisations — service provider.
 *
 * A Joomla component registers once, for both client sides; this is the only
 * provider file in the extension.
 *
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentProviderInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\MergedSetValidator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleLoader;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\SchemaValidator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Environment\JoomlaEnvironmentProvider;
use YakShaver\Component\ECSKCCustomisation\Administrator\Extension\ECSKCCustomisationComponent;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * The core collaborators are registered here rather than constructed where they
     * are used, so a test can replace any one of them - above all the environment
     * provider, which is the only route by which Joomla's own values reach the
     * derivation the core owns (decision 16).
     *
     * The runner itself is registered here once it exists; tickets 13 to 17 build
     * it, and this file is where it is wired in.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     */
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new MVCFactory('\\YakShaver\\Component\\ECSKCCustomisation'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\YakShaver\\Component\\ECSKCCustomisation'));

        $container->set(
            PackagePaths::class,
            static fn (): PackagePaths => new PackagePaths(
                JPATH_ADMINISTRATOR . '/components/com_ecskccustom'
            )
        );

        $container->set(
            EnvironmentProviderInterface::class,
            static fn (): EnvironmentProviderInterface => new JoomlaEnvironmentProvider()
        );

        $container->set(
            SchemaValidator::class,
            static fn (Container $container): SchemaValidator => new SchemaValidator(
                $container->get(PackagePaths::class)->root() . '/schema/patch.xsd'
            )
        );

        $container->set(
            RuleLoader::class,
            static fn (Container $container): RuleLoader => new RuleLoader(
                $container->get(SchemaValidator::class)
            )
        );

        $container->set(
            MergedSetValidator::class,
            static fn (Container $container): MergedSetValidator => new MergedSetValidator(
                $container->get(PackagePaths::class)->payload()
            )
        );

        $container->set(
            ComponentInterface::class,
            static function (Container $container) {
                $component = new ECSKCCustomisationComponent(
                    $container->get(ComponentDispatcherFactoryInterface::class)
                );

                $component->setMVCFactory($container->get(MVCFactoryInterface::class));

                return $component;
            }
        );
    }
};
