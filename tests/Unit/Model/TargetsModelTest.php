<?php

declare(strict_types=1);

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Model {

    use Joomla\CMS\Factory;
    use LogicException;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use ReflectionMethod;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Extension\ECSKCCustomisationComponent;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Model\RunRequest;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Model\TargetsModel;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Model\WriteResult;
    use YakShaver\ECSKCCustomisation\Tests\Unit\Runner\RunnerHarness;

    /**
     * The model reaches the runner only through the booted component.
     *
     * Runner is final, so the double is the component's runner factory: it records
     * each call and hands back a runner built over the harness tree.
     */
    #[CoversClass(TargetsModel::class)]
    #[CoversClass(ECSKCCustomisationComponent::class)]
    #[CoversClass(RunRequest::class)]
    #[CoversClass(WriteResult::class)]
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

        /**
         * The installer plugin's way in: the same runner, typed down to its reads.
         */
        public function testTheReadOnlyRunnerIsTheFactorysRunner(): void
        {
            $runner    = $this->runner();
            $component = new ECSKCCustomisationComponent(null);
            $component->setRunnerFactory(static fn (): Runner => $runner);

            self::assertSame($runner, $component->readOnlyRunner());
        }

        /**
         * @return iterable<string, array{mixed, mixed, string, string|null}>
         */
        public static function readOnlyRuns(): iterable
        {
            yield 'whole-set check' => ['check', null, 'check', null];
            yield 'single-path check' => ['check', self::CHAIN, 'check', self::CHAIN];
            yield 'whole-set dry run' => ['dryrun', null, 'dryRun', null];
            yield 'single-path dry run' => ['dryrun', self::CHAIN, 'dryRun', self::CHAIN];
            yield 'no run means check' => [null, self::CHAIN, 'check', self::CHAIN];
        }

        /**
         * Runner is final, so the call is identified by its report: the model's report
         * equals the named call's and differs from the other read's.
         */
        #[DataProvider('readOnlyRuns')]
        public function testEachReadOnlyRunReachesTheMatchingRunnerCall(mixed $run, mixed $path, string $method, ?string $argument): void
        {
            $before = $this->snapshot();
            $model  = new TargetsModel();
            $model->setState('run.request', RunRequest::fromInput($run, $path));

            $report = $model->getReport();
            $other  = $method === 'check' ? 'dryRun' : 'check';

            self::assertEquals($this->runner()->$method($argument), $report);
            self::assertNotEquals($this->runner()->$other($argument), $report);
            self::assertCount($argument === null ? 5 : 1, $report->targets());
            self::assertNull($model->getRunRequest()->notice);
            self::assertSame($before, $this->snapshot(), 'A read-only run writes nothing.');
        }

        /**
         * @return iterable<string, array{mixed}>
         */
        public static function unknownRuns(): iterable
        {
            foreach (['apply', 'freeze', 'unfreeze', 'revert', 'redo', 'CHECK', 'dryRun', '', ' check'] as $run) {
                yield \var_export($run, true) => [$run];
            }

            yield 'array' => [['check']];
            yield 'integer' => [1];
        }

        #[DataProvider('unknownRuns')]
        public function testAnUnknownRunIsTheWholeSetCheckWithANotice(mixed $run): void
        {
            $before = $this->snapshot();
            $model  = new TargetsModel();
            $model->setState('run.request', RunRequest::fromInput($run, self::VENDOR));

            self::assertEquals($this->runner()->check(), $model->getReport());
            self::assertSame(RunRequest::NOTICE_UNKNOWN_RUN, $model->getRunRequest()->notice);
            self::assertSame(1, $this->runnerCalls);
            self::assertSame($before, $this->snapshot(), 'An unknown run never falls through to a write.');
        }

        /**
         * @return iterable<string, array{string}>
         */
        public static function rawPaths(): iterable
        {
            yield 'dot dot' => ['templates/et_tennis/css/../css/chain.css'];
            yield 'spaces' => ['templates/et_tennis/css/chain copy.css'];
            yield 'entity' => ['templates/et_tennis/css/a&amp;b.css'];
            yield 'markup' => [self::VENDOR . '<script>'];
        }

        #[DataProvider('rawPaths')]
        public function testAPathReachesTheRunnerByteForByte(string $path): void
        {
            foreach (['check', 'dryrun'] as $run) {
                $model = new TargetsModel();
                $model->setState('run.request', RunRequest::fromInput($run, $path));

                $report = $model->getReport();

                self::assertSame($path, $model->getRunRequest()->path);
                self::assertSame([], $report->targets(), 'An undeclared path is not narrowed to a declared one.');
                self::assertSame(
                    ['the package does not manage ' . $path . ', so nothing was done'],
                    $report->faults()
                );
            }
        }

        public function testAnArrayPathMakesNoSinglePathCall(): void
        {
            $model = new TargetsModel();
            $model->setState('run.request', RunRequest::fromInput('dryrun', [self::VENDOR]));

            self::assertEquals($this->runner()->check(), $model->getReport());
            self::assertSame(RunRequest::NOTICE_PATH_NOT_TEXT, $model->getRunRequest()->notice);
            self::assertNull($model->getRunRequest()->path);
        }

        public function testNoRunRequestInTheStateIsTheWholeSetCheck(): void
        {
            self::assertEquals($this->runner()->check(), (new TargetsModel())->getReport());
        }

        public function testTheStateReadsRunAndPathRaw(): void
        {
            $input = new class () {
                /** @var list<array{string, mixed, string}> */
                public array $reads = [];

                public function get(string $name, mixed $default = null, string $filter = 'cmd'): mixed
                {
                    $this->reads[] = [$name, $default, $filter];

                    return ['run' => 'dryrun', 'path' => ' a&amp;b/../c<script> '][$name];
                }
            };
            $application = Factory::$application;
            Factory::$application = new class ($application, $input) {
                public function __construct(private readonly object $application, private readonly object $input)
                {
                }

                public function getInput(): object
                {
                    return $this->input;
                }

                public function bootComponent(string $component): object
                {
                    return $this->application->bootComponent($component);
                }
            };

            $model = new TargetsModel();
            (new ReflectionMethod($model, 'populateState'))->invoke($model);

            self::assertSame([['run', null, 'raw'], ['path', null, 'raw']], $input->reads);
            self::assertSame(RunRequest::DRY_RUN, $model->getRunRequest()->run);
            self::assertSame(' a&amp;b/../c<script> ', $model->getRunRequest()->path);
        }

        /**
         * Replaces the application with one that also holds user state, as the
         * session does between the write task and the page.
         *
         * @param array<string, mixed> $state
         */
        private function withUserState(array &$state): void
        {
            $application          = Factory::$application;
            Factory::$application = new class ($application, $state) {
                /**
                 * @param array<string, mixed> $state
                 */
                public function __construct(private readonly object $application, private array &$state)
                {
                }

                public function getUserState($key, $default = null)
                {
                    return $this->state[$key] ?? $default;
                }

                public function setUserState($key, $value)
                {
                    $this->state[$key] = $value;
                }

                public function bootComponent(string $component): object
                {
                    return $this->application->bootComponent($component);
                }
            };
        }

        public function testAStoredResultIsShownOnceAndTheNextLoadRunsAFreshCheck(): void
        {
            $written = WriteResult::fromReport(WriteResult::APPLY, self::VENDOR, $this->runner()->apply(self::VENDOR));
            $state   = [WriteResult::STATE_KEY => $written->toArray()];
            $this->withUserState($state);
            $this->runnerCalls = 0;

            $first = new TargetsModel();

            self::assertEquals($written, $first->takeWriteResult());
            self::assertEquals($written, $first->takeWriteResult(), 'The same page asks again and gets the same result.');
            self::assertNull($state[WriteResult::STATE_KEY], 'Taking the result drops the stored copy.');
            self::assertSame(0, $this->runnerCalls, 'Showing a stored result runs nothing.');

            $second = new TargetsModel();

            self::assertNull($second->takeWriteResult());
            self::assertEquals($this->runner()->check(), $second->getReport());
            self::assertSame(1, $this->runnerCalls);
        }

        public function testAStoredValueThatIsNotAResultIsDroppedForAFreshCheck(): void
        {
            $state = [WriteResult::STATE_KEY => ['operation' => 'revert']];
            $this->withUserState($state);

            $model = new TargetsModel();

            self::assertNull($model->takeWriteResult());
            self::assertNull($state[WriteResult::STATE_KEY]);
        }
    }
}
