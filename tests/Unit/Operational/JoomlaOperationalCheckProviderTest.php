<?php

declare(strict_types=1);

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
}

namespace Joomla\Database {
    interface DatabaseInterface
    {
    }
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Operational {

    use Joomla\CMS\Factory;
    use Joomla\Database\DatabaseInterface;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Operational\JoomlaOperationalCheckProvider;

    /**
     * A query that records what the provider built. Values bound by reference are
     * read when the driver runs the query, as Joomla reads them.
     */
    final class RecordingQuery
    {
        /** @var list<array{string, mixed}> */
        public array $calls = [];

        /** @var array<string, mixed> */
        public array $bound = [];

        public function select(mixed $columns): self
        {
            $this->calls[] = ['select', $columns];

            return $this;
        }

        public function from(mixed $table): self
        {
            $this->calls[] = ['from', $table];

            return $this;
        }

        public function where(mixed $conditions): self
        {
            $this->calls[] = ['where', $conditions];

            return $this;
        }

        public function bind(string $key, mixed &$value): self
        {
            $this->bound[$key] = &$value;

            return $this;
        }
    }

    /**
     * A driver that returns one stubbed value, or throws, and quotes names the way
     * MySQL does so the test can read the query.
     */
    final class StubDriver implements DatabaseInterface
    {
        public ?RecordingQuery $query = null;

        /** @var array<string, mixed> */
        public array $boundAtLoad = [];

        public function __construct(
            private readonly mixed $result,
            private readonly ?\Throwable $failure = null,
        ) {
        }

        public function createQuery(): RecordingQuery
        {
            return $this->query = new RecordingQuery();
        }

        public function quoteName(string $name): string
        {
            return '`' . $name . '`';
        }

        public function setQuery(RecordingQuery $query): self
        {
            $this->query = $query;

            return $this;
        }

        public function loadResult(): mixed
        {
            if ($this->failure !== null) {
                throw $this->failure;
            }

            foreach ($this->query->bound as $key => $value) {
                $this->boundAtLoad[$key] = $value;
            }

            return $this->result;
        }
    }

    #[CoversClass(JoomlaOperationalCheckProvider::class)]
    final class JoomlaOperationalCheckProviderTest extends TestCase
    {
        protected function tearDown(): void
        {
            Factory::$container = null;
        }

        private static function withDriver(StubDriver $driver): void
        {
            Factory::$container = new class ($driver) {
                public function __construct(private readonly StubDriver $driver)
                {
                }

                public function get(string $id): StubDriver
                {
                    if ($id !== DatabaseInterface::class) {
                        throw new \LogicException('The provider asked the container for ' . $id);
                    }

                    return $this->driver;
                }
            };
        }

        /**
         * @return iterable<string, array{mixed}>
         */
        public static function driverValues(): iterable
        {
            yield 'integer 1' => [1];
            yield 'integer 0' => [0];
            yield 'string "1"' => ['1'];
            yield 'absent row' => [null];
        }

        #[DataProvider('driverValues')]
        public function testTheDriversValueComesBackUnchanged(mixed $value): void
        {
            self::withDriver(new StubDriver($value));

            $read = (new JoomlaOperationalCheckProvider())->systemPluginEnabled();

            self::assertFalse($read->failed);
            self::assertSame($value, $read->value);
            self::assertSame('', $read->reason);
        }

        public function testADatabaseExceptionBecomesAReadFailureWithItsMessage(): void
        {
            // Joomla's ExecutionFailureException extends RuntimeException.
            self::withDriver(new StubDriver(1, new \RuntimeException("Table 'db.jos_extensions' doesn't exist")));

            $read = (new JoomlaOperationalCheckProvider())->systemPluginEnabled();

            self::assertTrue($read->failed);
            self::assertNull($read->value);
            self::assertSame("Table 'db.jos_extensions' doesn't exist", $read->reason);
        }

        public function testAnErrorThatIsNotADatabaseExceptionIsNotSwallowed(): void
        {
            self::withDriver(new StubDriver(1, new \LogicException('a bug')));

            $this->expectException(\LogicException::class);

            (new JoomlaOperationalCheckProvider())->systemPluginEnabled();
        }

        public function testAContainerThatCannotBuildTheDriverIsNotAFailedRead(): void
        {
            // Joomla's DependencyResolutionException extends RuntimeException, as the
            // driver's exceptions do, so only where it is thrown tells them apart.
            Factory::$container = new class () {
                public function get(string $id): never
                {
                    throw new \RuntimeException('"Joomla\\Database\\DatabaseInterface" cannot be instantiated.');
                }
            };

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('cannot be instantiated');

            (new JoomlaOperationalCheckProvider())->systemPluginEnabled();
        }

        public function testTheQueryNamesThePluginByTypeFolderAndElementWithBoundValues(): void
        {
            $driver = new StubDriver(1);
            self::withDriver($driver);

            (new JoomlaOperationalCheckProvider())->systemPluginEnabled();

            self::assertSame(
                [
                    ['select', '`enabled`'],
                    ['from', '`#__extensions`'],
                    ['where', ['`type` = :type', '`folder` = :folder', '`element` = :element']],
                ],
                $driver->query->calls
            );
            self::assertSame(
                [':type' => 'plugin', ':folder' => 'system', ':element' => 'ecskccustom'],
                $driver->boundAtLoad
            );
        }
    }
}
