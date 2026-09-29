<?php

declare(strict_types=1);

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
}

namespace Joomla\CMS\Pagination {
    /** Records what the footer was built from; only this test builds one. */
    final class Pagination
    {
        public function __construct(public int $total, public int $limitstart, public int $limit)
        {
        }
    }
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Model {

    use Joomla\CMS\Factory;
    use Joomla\CMS\Language\Text;
    use LogicException;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use ReflectionProperty;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\CommunityBuilderReaderInterface;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\ExtensionReaderInterface;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\ExtensionRow;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryEntry;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryLoadResult;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryReadFailure;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryResolver;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryRow;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Extension\ECSKCCustomisationComponent;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Model\PluginsModel;

    /**
     * The model reaches the registry and its resolver only through the booted
     * component, and hands the resolver the list state as Joomla's ListModel left it.
     */
    #[CoversClass(PluginsModel::class)]
    #[CoversClass(ECSKCCustomisationComponent::class)]
    final class PluginsModelTest extends TestCase
    {
        /** Three installed plugins, named so Name order differs from registry order. */
        private const PLUGINS = ['bravo' => 'Bravo', 'alpha' => 'alpha', 'charlie' => 'Charlie'];

        private int $registryLoads = 0;

        /** @var list<string> */
        private array $reads = [];

        private RegistryLoadResult $registry;

        private bool $failRead = false;

        protected function setUp(): void
        {
            $entries = [];

            foreach (\array_keys(self::PLUGINS) as $element) {
                $entries[] = new RegistryEntry('plugin', $element, 'system', 0, '', 'https://github.com/o/' . $element);
            }

            $this->registry = RegistryLoadResult::loaded($entries);

            $component = new ECSKCCustomisationComponent(null);
            $component->setRegistryFactories(
                function (): RegistryLoadResult {
                    ++$this->registryLoads;

                    return $this->registry;
                },
                fn (): RegistryResolver => $this->resolver()
            );

            Factory::$application = new class ($component) {
                /** @var array<string, mixed> The request, as Joomla's input holds it. */
                public array $request = [];

                /** @var array<string, mixed> */
                public array $userState = [];

                public function __construct(private readonly object $component)
                {
                }

                public function bootComponent(string $component): object
                {
                    return $component === 'com_ecskccustom' ? $this->component : throw new LogicException($component);
                }

                public function getUserState(string $key, mixed $default = null): mixed
                {
                    return $this->userState[$key] ?? $default;
                }

                public function getInput(): object
                {
                    $request = &$this->request;

                    return new class ($request) {
                        /** @param array<string, mixed> $request */
                        public function __construct(private array &$request)
                        {
                        }

                        public function get(string $name, mixed $default = null, string $filter = 'cmd'): mixed
                        {
                            return $this->request[$name] ?? $default;
                        }

                        public function set(string $name, mixed $value): void
                        {
                            $this->request[$name] = $value;
                        }
                    };
                }
            };
        }

        protected function tearDown(): void
        {
            Factory::$application = null;
            Text::$strings         = [];
        }

        public function testTheSortableColumnsAreTheResolversAndTheDefaultIsNameAscending(): void
        {
            $fields = new ReflectionProperty(PluginsModel::class, 'filter_fields');
            self::assertSame(RegistryResolver::SORTABLE, $fields->getValue(new PluginsModel()));

            $populate = new ReflectionMethod(PluginsModel::class, 'populateState');
            self::assertSame('name', $populate->getParameters()[0]->getDefaultValue());
            self::assertSame('ASC', $populate->getParameters()[1]->getDefaultValue());
        }

        public function testAnEmptyStateListsEveryRowByNameAscending(): void
        {
            $model = new PluginsModel();

            self::assertSame(['alpha', 'Bravo', 'Charlie'], self::names($model->getItems()));
            self::assertSame(3, $model->getTotal());
            self::assertSame(0, $model->getStart());
        }

        public function testTheListStateReachesTheResolver(): void
        {
            $model = self::model(['list.ordering' => 'name', 'list.direction' => 'desc', 'list.start' => 0, 'list.limit' => 2]);

            self::assertSame(['Charlie', 'Bravo'], self::names($model->getItems()));
            self::assertSame(3, $model->getTotal(), 'The total is the unsliced count.');
            self::assertSame('DESC', $model->getPage()->direction);
        }

        public function testTheSearchNarrowsTheListAndTheTotal(): void
        {
            $model = self::model(['filter.search' => 'CH', 'list.limit' => 20]);

            self::assertSame(['Charlie'], self::names($model->getItems()));
            self::assertSame(1, $model->getTotal());
        }

        public function testAStartPastTheEndIsTheLastPageAndTheFooterUsesIt(): void
        {
            $model = self::model(['filter.search' => 'a', 'list.start' => 20, 'list.limit' => 2]);

            self::assertSame(2, $model->getStart());
            self::assertSame(['Charlie'], self::names($model->getItems()));

            $footer = $model->getPagination();

            self::assertSame([3, 2, 2], [$footer->total, $footer->limitstart, $footer->limit]);
            self::assertSame($footer, $model->getPagination(), 'One footer per request.');
        }

        public function testTheFooterTotalIsTheSearchedCount(): void
        {
            $footer = self::model(['filter.search' => 'ALPHA', 'list.limit' => 20])->getPagination();

            self::assertSame([1, 0, 20], [$footer->total, $footer->limitstart, $footer->limit]);
        }

        /**
         * ListModel saves the filters without comparing them, and the footer posts the
         * current start back, so the model resets it for a changed search.
         */
        public function testANewSearchReturnsToTheFirstPage(): void
        {
            $app = Factory::$application;
            $app->userState['com_ecskccustom.plugins.filter'] = ['search' => 'old'];
            $app->request = ['filter' => ['search' => 'new'], 'limitstart' => 20];

            self::populate();

            self::assertSame(0, $app->request['limitstart']);
        }

        public function testTheStoredFiltersMayBeAnObject(): void
        {
            $app = Factory::$application;
            $app->userState['com_ecskccustom.plugins.filter'] = (object) ['search' => 'old'];
            $app->request = ['filter' => ['search' => 'new'], 'limitstart' => 20];

            self::populate();

            self::assertSame(0, $app->request['limitstart']);
        }

        public function testTheSameSearchKeepsThePage(): void
        {
            $app = Factory::$application;
            $app->userState['com_ecskccustom.plugins.filter'] = (object) ['search' => 'same'];
            $app->request = ['filter' => ['search' => 'same'], 'limitstart' => 20];

            self::populate();

            self::assertSame(20, $app->request['limitstart']);
        }

        /**
         * A page link sends no filters, so paging through a search keeps its start.
         */
        public function testARequestWithoutFiltersKeepsThePage(): void
        {
            $app = Factory::$application;
            $app->userState['com_ecskccustom.plugins.filter'] = ['search' => 'old'];
            $app->request = ['limitstart' => 20];

            self::populate();

            self::assertSame(20, $app->request['limitstart']);
        }

        public function testAFirstSearchOnAnEmptyStateReturnsToTheFirstPage(): void
        {
            $app = Factory::$application;
            $app->request = ['filter' => ['search' => 'a'], 'limitstart' => 20];

            self::populate();

            self::assertSame(0, $app->request['limitstart']);
        }

        private static function populate(): void
        {
            $model = new PluginsModel(['context' => 'com_ecskccustom.plugins']);

            (new ReflectionMethod(PluginsModel::class, 'populateState'))->invoke($model);
        }

        public function testAStateValueThatIsNotTextIsTheDefault(): void
        {
            $model = self::model(['filter.search' => ['x'], 'list.ordering' => ['version'], 'list.direction' => null]);

            self::assertSame(['alpha', 'Bravo', 'Charlie'], self::names($model->getItems()));
            self::assertSame('name', $model->getPage()->ordering);
            self::assertSame('ASC', $model->getPage()->direction);
        }

        /**
         * Type sorts on the text the screen shows, which is the language string.
         */
        public function testTypeSortsOnTheTranslatedLabel(): void
        {
            $this->registry = RegistryLoadResult::loaded([
                new RegistryEntry('plugin', 'alpha', 'system', 0, '', 'https://github.com/o/a'),
                new RegistryEntry('package', 'bravo', '', 0, '', 'https://github.com/o/b'),
            ]);
            Text::$strings = [
                'COM_ECSKCCUSTOM_PLUGINS_TYPE_PLUGIN'  => 'A plugin',
                'COM_ECSKCCUSTOM_PLUGINS_TYPE_PACKAGE' => 'Z package',
            ];

            $model = self::model(['list.ordering' => 'type', 'list.direction' => 'DESC']);

            self::assertSame(['bravo'], \array_slice(\array_map(static fn (RegistryRow $row): string => $row->entry->element, $model->getItems()), 0, 1));
        }

        public function testTheRegistryIsLoadedAndReadOncePerRequest(): void
        {
            $model = self::model(['list.limit' => 2]);

            $model->getItems();
            $model->getTotal();
            $model->getStart();
            $model->getResolution();

            self::assertSame(1, $this->registryLoads);
            self::assertSame(['extensions', 'updates', 'updateSites'], $this->reads);
        }

        public function testARegistryFaultIsThePageLevelFaultWithNoRows(): void
        {
            $this->registry = RegistryLoadResult::fault(['/site/registry.xml: the registry file does not exist']);

            $model = new PluginsModel();

            self::assertSame(['/site/registry.xml: the registry file does not exist'], $model->getResolution()->faults);
            self::assertSame([], $model->getItems());
            self::assertSame(0, $model->getTotal());
            self::assertSame([], $this->reads, 'Nothing is read for a registry that did not load.');
        }

        public function testAReadFailureIsThePageLevelFaultWithNoRows(): void
        {
            $this->failRead = true;

            $model = new PluginsModel();

            self::assertSame(['extensions: the query failed'], $model->getResolution()->faults);
            self::assertSame([], $model->getItems());
        }

        public function testAComponentWithoutRegistryFactoriesRefuses(): void
        {
            $component = new ECSKCCustomisationComponent(null);

            try {
                $component->registry();
                self::fail('registry() must refuse without a factory.');
            } catch (LogicException) {
            }

            $this->expectException(LogicException::class);
            $component->registryResolver();
        }

        /**
         * @param array<string, mixed> $state
         */
        private static function model(array $state): PluginsModel
        {
            $model = new PluginsModel();

            foreach ($state as $property => $value) {
                $model->setState($property, $value);
            }

            return $model;
        }

        /**
         * @param list<RegistryRow> $rows
         *
         * @return list<string>
         */
        private static function names(array $rows): array
        {
            return \array_map(static fn (RegistryRow $row): string => $row->name, $rows);
        }

        private function resolver(): RegistryResolver
        {
            $rows = [];
            $id   = 100;

            foreach (self::PLUGINS as $element => $name) {
                $rows[] = new ExtensionRow(++$id, 'plugin', $element, 'system', 0, 0, 1, 0, '{"name":"x","version":"1.0.0"}', $name, $name, '');
            }

            $rows[] = new ExtensionRow(++$id, 'package', 'bravo', '', 0, 0, 1, 0, '{"name":"x","version":"1.0.0"}', 'bravo', 'bravo', '');

            $reads = &$this->reads;
            $fail  = $this->failRead;

            $extensions = new class ($rows, $reads, $fail) implements ExtensionReaderInterface {
                /**
                 * @param list<ExtensionRow> $rows
                 * @param list<string>       $reads
                 */
                public function __construct(private array $rows, private array &$reads, private bool $fail)
                {
                }

                public function extensions(array $elementsByType): array
                {
                    $this->reads[] = 'extensions';

                    if ($this->fail) {
                        throw new RegistryReadFailure('extensions: the query failed');
                    }

                    return $this->rows;
                }

                public function extensionsById(array $extensionIds): array
                {
                    $this->reads[] = 'extensionsById';

                    return [];
                }

                public function updates(array $extensionIds): array
                {
                    $this->reads[] = 'updates';

                    return [];
                }

                public function updateSites(array $extensionIds): array
                {
                    $this->reads[] = 'updateSites';

                    return [];
                }
            };

            $communityBuilder = new class () implements CommunityBuilderReaderInterface {
                public function plugins(array $entries): array
                {
                    return [];
                }
            };

            return new RegistryResolver($extensions, $communityBuilder);
        }
    }
}
