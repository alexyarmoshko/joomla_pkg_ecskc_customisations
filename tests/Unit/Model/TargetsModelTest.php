<?php

declare(strict_types=1);

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Model {

    use Joomla\CMS\Factory;
    use LogicException;
    use PHPUnit\Framework\Attributes\CoversClass;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Extension\ECSKCCustomisationComponent;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Model\TargetsModel;
    use YakShaver\ECSKCCustomisation\Tests\Unit\Runner\RunnerHarness;

    /**
     * The model reaches the runner only through the booted component.
     *
     * Runner is final, so the double is the component's runner factory: it records
     * each call and hands back a runner built over the harness tree.
     */
    #[CoversClass(TargetsModel::class)]
    #[CoversClass(ECSKCCustomisationComponent::class)]
    final class TargetsModelTest extends RunnerHarness
    {
        /** @var list<string> */
        private array $booted = [];

        private int $runnerCalls = 0;

        protected function setUp(): void
        {
            parent::setUp();

            $this->writeFixture();

            $component = new ECSKCCustomisationComponent(null);
            $component->setRunnerFactory(function (): Runner {
                ++$this->runnerCalls;

                return $this->runner();
            });

            $booted              = &$this->booted;
            Factory::$application = new class ($component, $booted) {
                /**
                 * @param list<string> $booted
                 */
                public function __construct(private readonly object $component, private array &$booted)
                {
                }

                public function bootComponent(string $component): object
                {
                    $this->booted[] = $component;

                    return $this->component;
                }
            };
        }

        protected function tearDown(): void
        {
            Factory::$application = null;

            parent::tearDown();
        }

        /**
         * @param list<TargetReport> $targets
         *
         * @return list<string>
         */
        private static function targetPaths(array $targets): array
        {
            return \array_map(static fn (TargetReport $target): string => $target->targetPath(), $targets);
        }

        public function testTheReportIsAWholeSetCheckFromTheComponentsRunner(): void
        {
            $before = $this->snapshot();
            $report = (new TargetsModel())->getReport();

            self::assertSame(['com_ecskccustom'], $this->booted);
            self::assertSame(1, $this->runnerCalls);
            self::assertEquals($this->runner()->check(), $report);
            self::assertEqualsCanonicalizing(
                [self::VENDOR, self::CREATED, self::CHAIN, self::FOREIGN_TARGET, self::REMOVED],
                self::targetPaths($report->targets())
            );
            self::assertSame($before, $this->snapshot(), 'Opening the report writes nothing.');
        }

        public function testTheCheckRunsOnceHoweverOftenTheViewAsks(): void
        {
            $model = new TargetsModel();

            $model->getReport();
            $model->getItems();
            $model->getTotal();

            self::assertSame(1, $this->runnerCalls);
        }

        public function testTheFiltersNarrowTheItemsAndNotTheReport(): void
        {
            $model = new TargetsModel();
            $model->setState('filter.search', 'CHAIN');

            self::assertSame([self::CHAIN], self::targetPaths($model->getItems()));
            self::assertSame(1, $model->getTotal());
            self::assertCount(5, $model->getReport()->targets());

            $model->setState('filter.search', '');
            $model->setState('filter.status', TargetStatus::BLOCKED);

            self::assertSame([self::FOREIGN_TARGET], self::targetPaths($model->getItems()));
            self::assertSame(5, $model->getReport()->counts->targets);
        }

        public function testAComponentWithoutARunnerFactoryRefuses(): void
        {
            $this->expectException(LogicException::class);

            (new ECSKCCustomisationComponent(null))->runner();
        }
    }
}
