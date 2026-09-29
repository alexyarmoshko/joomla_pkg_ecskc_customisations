<?php

declare(strict_types=1);

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
    require_once \dirname(__DIR__, 3) . '/extensions/pkg_ecskc_customisations/script.php';
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Package {

    use Joomla\CMS\Factory;
    use Joomla\CMS\Installer\InstallerAdapter;
    use Joomla\Database\DatabaseInterface;
    use Joomla\Database\ParameterType;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Pkg_Ecskc_customisationsInstallerScript;

    /**
     * An update query that records what the script built. Values bound by
     * reference are read at execute(), as Joomla reads them.
     */
    final class RecordingUpdate
    {
        public string $table = '';

        /** @var list<string> */
        public array $set = [];

        /** @var list<string> */
        public array $where = [];

        /** @var array<string, mixed> */
        public array $bound = [];

        /** @var array<string, string> */
        public array $types = [];

        public function update(string $table): self
        {
            $this->table = $table;

            return $this;
        }

        public function set(string $assignment): self
        {
            $this->set[] = $assignment;

            return $this;
        }

        /**
         * @param list<string> $conditions
         */
        public function where(array $conditions): self
        {
            \array_push($this->where, ...$conditions);

            return $this;
        }

        public function bind(string $key, mixed &$value, string $type = ParameterType::STRING): self
        {
            $this->bound[$key] = &$value;
            $this->types[$key] = $type;

            return $this;
        }
    }

    /**
     * A driver that keeps each executed query with its bound values as they
     * stood at execute().
     */
    final class RecordingDriver implements DatabaseInterface
    {
        /** @var list<array{RecordingUpdate, array<string, mixed>}> */
        public array $executed = [];

        private ?RecordingUpdate $query = null;

        public function createQuery(): RecordingUpdate
        {
            return new RecordingUpdate();
        }

        public function quoteName(string $name): string
        {
            return '`' . $name . '`';
        }

        public function setQuery(RecordingUpdate $query): self
        {
            $this->query = $query;

            return $this;
        }

        public function execute(): bool
        {
            $values = [];

            foreach ($this->query->bound as $key => $value) {
                $values[$key] = $value;
            }

            $this->executed[] = [$this->query, $values];

            return true;
        }
    }

    /**
     * The package script enables both plugins on first installation and changes
     * nothing on any other route, so an update keeps the site's enabled state.
     */
    #[CoversClass(Pkg_Ecskc_customisationsInstallerScript::class)]
    final class PackageScriptTest extends TestCase
    {
        private RecordingDriver $driver;

        protected function setUp(): void
        {
            $this->driver       = new RecordingDriver();
            Factory::$container = new class ($this->driver) {
                public function __construct(private readonly RecordingDriver $driver)
                {
                }

                public function get(string $id): RecordingDriver
                {
                    if ($id !== DatabaseInterface::class) {
                        throw new \LogicException('The script asked the container for ' . $id);
                    }

                    return $this->driver;
                }
            };
        }

        protected function tearDown(): void
        {
            Factory::$container = null;
        }

        public function testInstallEnablesTheSystemAndTheInstallerPlugin(): void
        {
            $script = new Pkg_Ecskc_customisationsInstallerScript();

            self::assertTrue($script->postflight('install', new InstallerAdapter()));
            self::assertCount(2, $this->driver->executed);

            $folders = [];

            foreach ($this->driver->executed as [$query, $values]) {
                self::assertSame('`#__extensions`', $query->table);
                self::assertSame(['`enabled` = :enabled'], $query->set);
                self::assertSame(
                    ['`type` = :type', '`folder` = :folder', '`element` = :element'],
                    $query->where
                );
                self::assertSame(1, $values[':enabled']);
                self::assertSame(ParameterType::INTEGER, $query->types[':enabled']);
                self::assertSame('plugin', $values[':type']);
                self::assertSame('ecskccustom', $values[':element']);

                $folders[] = $values[':folder'];
            }

            self::assertSame(['system', 'installer'], $folders);
        }

        /**
         * @return iterable<string, array{string}>
         */
        public static function otherRoutes(): iterable
        {
            yield 'update' => ['update'];
            yield 'discover_install' => ['discover_install'];
            yield 'uninstall' => ['uninstall'];
        }

        #[DataProvider('otherRoutes')]
        public function testEveryOtherRouteLeavesThePluginRecordsAlone(string $route): void
        {
            $script = new Pkg_Ecskc_customisationsInstallerScript();

            self::assertTrue($script->postflight($route, new InstallerAdapter()));
            self::assertSame([], $this->driver->executed);
        }

        /**
         * Joomla names the legacy script class after the package element:
         * `pkg_` plus <packagename>, then `InstallerScript`. PHP class names
         * ignore case, so the declared spelling only has to match letter for letter.
         */
        public function testTheClassNameIsTheOneJoomlaLooksFor(): void
        {
            $manifest = \simplexml_load_file(
                \dirname(__DIR__, 3) . '/extensions/pkg_ecskc_customisations/pkg_ecskc_customisations.xml'
            );
            self::assertNotFalse($manifest);

            $expected = 'pkg_' . $manifest->packagename . 'InstallerScript';

            self::assertSame(\strtolower($expected), \strtolower(Pkg_Ecskc_customisationsInstallerScript::class));
        }
    }
}
