<?php

declare(strict_types=1);

namespace Joomla\CMS\Event\Extension {
    /** The one accessor the plugin uses. */
    final class AfterInstallEvent
    {
        public function __construct(private readonly int|bool $eid)
        {
        }

        public function getEid(): int|bool
        {
            return $this->eid;
        }
    }

    /** The one accessor the plugin uses. */
    final class AfterUpdateEvent
    {
        public function __construct(private readonly int|bool $eid)
        {
        }

        public function getEid(): int|bool
        {
            return $this->eid;
        }
    }
}

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Plugin {

    use Joomla\CMS\Event\Extension\AfterInstallEvent;
    use Joomla\CMS\Event\Extension\AfterUpdateEvent;
    use Joomla\CMS\Language\Text;
    use Joomla\CMS\Log\Log;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\ReadOnlyRunnerInterface;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalCheck;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalChecks;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\ReportedValue;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Extension\ECSKCCustomisationComponent;
    use YakShaver\ECSKCCustomisation\Tests\Unit\Report\NotifiableReportTest;
    use YakShaver\Plugin\Installer\ECSKCCustomisation\Extension\ECSKCCustomisationPlugin;

    /**
     * The installer plugin runs one read-only check after successful extension work and
     * queues at most one message for the request (plan decisions 7, 8, 22 and 51).
     */
    #[CoversClass(ECSKCCustomisationPlugin::class)]
    final class InstallerPluginTest extends TestCase
    {
        private const LANGUAGE = __DIR__ . '/../../../extensions/plg_installer_ecskccustom/language/en-GB/plg_installer_ecskccustom.ini';

        protected function setUp(): void
        {
            Log::$messages = [];
            Text::$fail    = false;
            Text::$strings = \parse_ini_file(self::LANGUAGE, false, INI_SCANNER_RAW) ?: [];
        }

        protected function tearDown(): void
        {
            Text::$strings = [];
        }

        private static function plugin(InstallerApplicationDouble $application): ECSKCCustomisationPlugin
        {
            $plugin = new ECSKCCustomisationPlugin(['name' => 'ecskccustom', 'type' => 'installer']);
            $plugin->setApplication($application);

            return $plugin;
        }

        /**
         * @param list<TargetReport> $targets
         */
        private static function report(array $targets, ?OperationalChecks $checks = null): RunReport
        {
            return RunReport::of($targets, $checks ?? OperationalChecks::none(), ReportedValue::none('no run was interrupted'));
        }

        private static function ok(): RunReport
        {
            return self::report([NotifiableReportTest::target(TargetStatus::OK)]);
        }

        /**
         * @param list<RunReport> $reports
         */
        private static function site(array $reports): InstallerApplicationDouble
        {
            return new InstallerApplicationDouble(new ComponentDouble(new RunnerSpy($reports)));
        }

        /**
         * @return list<array{message: string, type: string}>
         */
        private static function ours(InstallerApplicationDouble $application): array
        {
            return \array_values(\array_filter(
                $application->queue,
                static fn (array $entry): bool => $entry['type'] === ECSKCCustomisationPlugin::MESSAGE_TYPE
            ));
        }

        /**
         * @return iterable<string, array{string, bool}>
         */
        public static function statuses(): iterable
        {
            yield 'ok'             => [TargetStatus::OK, false];
            yield 'actionable'     => [TargetStatus::ACTIONABLE, true];
            yield 'incomplete'     => [TargetStatus::INCOMPLETE, true];
            yield 'blocked'        => [TargetStatus::BLOCKED, true];
            yield 'not applicable' => [TargetStatus::NOT_APPLICABLE, false];
            yield 'faulted'        => [TargetStatus::FAULTED, true];
        }

        #[DataProvider('statuses')]
        public function testATargetStateProducesAMessageExactlyWhenItIsNotifiable(string $status, bool $notifies): void
        {
            $application = self::site([self::report([NotifiableReportTest::target($status)])]);

            self::plugin($application)->onExtensionAfterInstall(new AfterInstallEvent(42));

            $messages = self::ours($application);
            self::assertCount($notifies ? 1 : 0, $messages);

            if ($notifies) {
                self::assertStringContainsString('Managed paths needing attention: 1 (1 ' . $status . ').', $messages[0]['message']);
                self::assertStringContainsString(
                    '<a href="index.php?option=com_ecskccustom&amp;view=targets">Open the Customisations report</a>',
                    $messages[0]['message']
                );
            }
        }

        public function testAFrozenPathThatWouldBeActionableProducesNoMessage(): void
        {
            $application = self::site([self::report([NotifiableReportTest::frozen(TargetStatus::ACTIONABLE)])]);

            self::plugin($application)->onExtensionAfterUpdate(new AfterUpdateEvent(42));

            self::assertSame([], $application->queue);
        }

        /**
         * @return iterable<string, array{string}>
         */
        public static function notifiableChecks(): iterable
        {
            yield 'disabled' => ['disabled'];
            yield 'unknown'  => ['unknown'];
        }

        #[DataProvider('notifiableChecks')]
        public function testANotifiableCheckIsNamedWhenEveryTargetIsOk(string $outcome): void
        {
            $check       = new OperationalCheck('system_plugin_enabled', $outcome, true, 'the plugin is not loading <override.css>');
            $application = self::site([
                self::report([NotifiableReportTest::target(TargetStatus::OK)], OperationalChecks::of($check)),
            ]);

            self::plugin($application)->onExtensionAfterInstall(new AfterInstallEvent(42));

            $messages = self::ours($application);
            self::assertCount(1, $messages);
            self::assertStringContainsString(
                'System plugin: the plugin is not loading &lt;override.css&gt;',
                $messages[0]['message']
            );
            self::assertStringNotContainsString('Managed paths', $messages[0]['message']);
        }

        public function testAnEnabledCheckWithEveryTargetOkProducesNoMessage(): void
        {
            $check       = new OperationalCheck('system_plugin_enabled', 'enabled', false, 'on');
            $application = self::site([self::report([NotifiableReportTest::target(TargetStatus::OK)], OperationalChecks::of($check))]);

            self::plugin($application)->onExtensionAfterInstall(new AfterInstallEvent(42));

            self::assertSame([], $application->queue);
        }

        public function testAFaultedReportWithNoTargetsNamesTheFault(): void
        {
            $application = self::site([
                RunReport::faulted(
                    ['the rule set does not load: 10-comprofiler.xml is not valid'],
                    OperationalChecks::none(),
                    ReportedValue::none('no run was interrupted')
                ),
            ]);

            self::plugin($application)->onExtensionAfterInstall(new AfterInstallEvent(42));

            $messages = self::ours($application);
            self::assertCount(1, $messages);
            self::assertStringContainsString(
                'The check could not run: the rule set does not load: 10-comprofiler.xml is not valid',
                $messages[0]['message']
            );
        }

        public function testSeveralSuccessfulEventsInOneRequestLeaveOneMessage(): void
        {
            $actionable  = self::report([NotifiableReportTest::target(TargetStatus::ACTIONABLE)]);
            $application = self::site([$actionable, $actionable, $actionable]);
            $plugin      = self::plugin($application);

            $plugin->onExtensionAfterUpdate(new AfterUpdateEvent(10));
            $plugin->onExtensionAfterUpdate(new AfterUpdateEvent(11));
            $plugin->onExtensionAfterInstall(new AfterInstallEvent(12));

            self::assertCount(1, self::ours($application));
        }

        /**
         * A package's children each fire before the package itself. The message the
         * operator sees is the check after the last one.
         */
        public function testTheLastEventsCheckReplacesTheEarlierMessage(): void
        {
            $application = self::site([
                self::report([NotifiableReportTest::target(TargetStatus::ACTIONABLE)]),
                self::report([NotifiableReportTest::target(TargetStatus::BLOCKED)]),
            ]);
            $application->enqueueMessage('Installation of the package was successful.', 'message');
            $plugin = self::plugin($application);

            $plugin->onExtensionAfterUpdate(new AfterUpdateEvent(10));
            $application->enqueueMessage('Updating component was successful.', 'message');
            $plugin->onExtensionAfterUpdate(new AfterUpdateEvent(11));

            $messages = self::ours($application);
            self::assertCount(1, $messages);
            self::assertStringContainsString('(1 blocked)', $messages[0]['message']);
            self::assertSame(
                ['Installation of the package was successful.', 'Updating component was successful.'],
                \array_column(\array_slice($application->queue, 0, 2), 'message'),
                'Joomla\'s own messages stay, in their order.'
            );
        }

        public function testALaterAllOkCheckWithdrawsTheEarlierMessage(): void
        {
            $application = self::site([self::report([NotifiableReportTest::target(TargetStatus::ACTIONABLE)]), self::ok()]);
            $plugin      = self::plugin($application);

            $plugin->onExtensionAfterUpdate(new AfterUpdateEvent(10));
            $plugin->onExtensionAfterUpdate(new AfterUpdateEvent(11));

            self::assertSame([], $application->queue);
        }

        public function testAFailedEventRunsNoCheck(): void
        {
            $application = self::site([]);
            $plugin      = self::plugin($application);

            $plugin->onExtensionAfterInstall(new AfterInstallEvent(false));
            $plugin->onExtensionAfterUpdate(new AfterUpdateEvent(false));

            self::assertSame([], $application->component->runner->calls);
            self::assertSame(0, $application->boots);
            self::assertSame([], $application->queue);
        }

        public function testThePluginCallsCheckAndNothingElse(): void
        {
            $application = self::site([self::report([NotifiableReportTest::target(TargetStatus::ACTIONABLE)])]);

            self::plugin($application)->onExtensionAfterInstall(new AfterInstallEvent(42));

            self::assertSame([['check', null]], $application->component->runner->calls);
        }

        public function testAMissingComponentProducesNoMessageAndNoException(): void
        {
            $application = new InstallerApplicationDouble(new \stdClass());

            self::plugin($application)->onExtensionAfterInstall(new AfterInstallEvent(42));

            self::assertSame([], $application->queue);
            self::assertSame(['com_ecskccustom is not installed, so no check ran after extension work.'], Log::$messages);
        }

        public function testAComponentThatFailsToBootIsLoggedAndSwallowed(): void
        {
            $application = new InstallerApplicationDouble(new \RuntimeException('provider.php is broken'));

            self::plugin($application)->onExtensionAfterUpdate(new AfterUpdateEvent(42));

            self::assertSame([], $application->queue);
            self::assertSame(['The check after extension work did not run: provider.php is broken'], Log::$messages);
        }

        public function testARunnerThatThrowsIsLoggedAndSwallowed(): void
        {
            $application = self::site([]);

            self::plugin($application)->onExtensionAfterUpdate(new AfterUpdateEvent(42));

            self::assertSame([], $application->queue);
            self::assertSame(['The check after extension work did not run: no report left'], Log::$messages);
        }

        public function testOutsideTheAdministratorNothingRuns(): void
        {
            $application         = self::site([self::report([NotifiableReportTest::target(TargetStatus::ACTIONABLE)])]);
            $application->client = 'cli';

            self::plugin($application)->onExtensionAfterInstall(new AfterInstallEvent(42));

            self::assertSame(0, $application->boots);
            self::assertSame([], $application->queue);
        }
    }

    /**
     * The administrator application, as far as the plugin reaches it. Its queue keeps
     * Joomla's rule that a message already queued is not queued again.
     */
    final class InstallerApplicationDouble
    {
        /** @var list<array{message: string, type: string}> */
        public array $queue = [];

        public int $boots = 0;

        public string $client = 'administrator';

        /**
         * @param object $component What bootComponent() returns, or a Throwable it throws.
         */
        public function __construct(public readonly object $component)
        {
        }

        public function isClient(string $identifier): bool
        {
            return $identifier === $this->client;
        }

        public function bootComponent(string $component): object
        {
            ++$this->boots;

            if ($component !== 'com_ecskccustom') {
                throw new \LogicException('The plugin booted ' . $component);
            }

            if ($this->component instanceof \Throwable) {
                throw $this->component;
            }

            return $this->component;
        }

        public function enqueueMessage(string $msg, string $type = 'info'): void
        {
            $message = ['message' => $msg, 'type' => $type];

            if (!\in_array($message, $this->queue, true)) {
                $this->queue[] = $message;
            }
        }

        /**
         * @return list<array{message: string, type: string}>
         */
        public function getMessageQueue(bool $clear = false): array
        {
            $queue = $this->queue;

            if ($clear) {
                $this->queue = [];
            }

            return $queue;
        }
    }

    final class ComponentDouble extends ECSKCCustomisationComponent
    {
        public function __construct(public readonly RunnerSpy $runner)
        {
            parent::__construct(null);
        }

        public function readOnlyRunner(): ReadOnlyRunnerInterface
        {
            return $this->runner;
        }
    }

    /**
     * Hands out the given reports in turn, one per call, and records every call.
     */
    final class RunnerSpy implements ReadOnlyRunnerInterface
    {
        /** @var list<array{string, ?string}> */
        public array $calls = [];

        /**
         * @param list<RunReport> $reports
         */
        public function __construct(private array $reports)
        {
        }

        public function check(?string $targetPath = null): RunReport
        {
            $this->calls[] = ['check', $targetPath];

            return \array_shift($this->reports) ?? throw new \RuntimeException('no report left');
        }

        public function dryRun(?string $targetPath = null): RunReport
        {
            $this->calls[] = ['dryRun', $targetPath];

            return \array_shift($this->reports) ?? throw new \RuntimeException('no report left');
        }
    }
}
