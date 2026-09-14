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
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition\ConditionEvaluator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentProviderInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentResolver;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\Hasher;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path\PathPolicy;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\MergedSetValidator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleLoader;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\SchemaValidator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventLogHistory;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\FreezeMarkers;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteHistoryInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ObjectWalker;
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
     * The runner is the only one of these an adapter resolves. It is registered
     * against its own class rather than an interface because there is one of it;
     * what a test replaces is a collaborator underneath it, above all the
     * environment provider and the write history.
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
            EnvironmentResolver::class,
            static fn (): EnvironmentResolver => new EnvironmentResolver()
        );

        $container->set(
            ConditionEvaluator::class,
            static fn (): ConditionEvaluator => new ConditionEvaluator()
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
            Hasher::class,
            static fn (): Hasher => new Hasher()
        );

        $container->set(
            PathPolicy::class,
            static fn (Container $container): PathPolicy => new PathPolicy(
                JPATH_ROOT,
                $container->get(PackagePaths::class)->root()
            )
        );

        $container->set(
            FreezeMarkers::class,
            static fn (Container $container): FreezeMarkers => new FreezeMarkers(
                $container->get(PackagePaths::class)
            )
        );

        $container->set(
            ObjectWalker::class,
            static fn (Container $container): ObjectWalker => new ObjectWalker(
                $container->get(PathPolicy::class),
                $container->get(Hasher::class),
                $container->get(ConditionEvaluator::class),
                $container->get(FreezeMarkers::class)
            )
        );

        $container->set(
            WriteHistoryInterface::class,
            static fn (Container $container): WriteHistoryInterface => new EventLogHistory(
                $container->get(PackagePaths::class)
            )
        );

        $container->set(
            Runner::class,
            static fn (Container $container): Runner => new Runner(
                $container->get(PackagePaths::class),
                $container->get(RuleLoader::class),
                $container->get(MergedSetValidator::class),
                $container->get(EnvironmentProviderInterface::class),
                $container->get(EnvironmentResolver::class),
                $container->get(ObjectWalker::class),
                $container->get(WriteHistoryInterface::class)
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
