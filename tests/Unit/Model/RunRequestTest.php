<?php

declare(strict_types=1);

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Model {

    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\ReadOnlyRunnerInterface;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalChecks;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\ReportedValue;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Model\RunRequest;

    /**
     * Which runner read a request makes, with which argument, seen by a double that
     * records each call. TargetsModelTest proves the same requests end to end over a
     * real Runner.
     */
    #[CoversClass(RunRequest::class)]
    final class RunRequestTest extends TestCase
    {
        private const VENDOR = 'templates/et_tennis/css/vendor.css';

        /**
         * @return list<array{string, string|null}> Each call as [method, argument].
         */
        private static function calls(mixed $run, mixed $path): array
        {
            $runner = new class () implements ReadOnlyRunnerInterface {
                /** @var list<array{string, string|null}> */
                public array $calls = [];

                public function check(?string $targetPath = null): RunReport
                {
                    $this->calls[] = ['check', $targetPath];

                    return self::report();
                }

                public function dryRun(?string $targetPath = null): RunReport
                {
                    $this->calls[] = ['dryRun', $targetPath];

                    return self::report();
                }

                private static function report(): RunReport
                {
                    return RunReport::of([], OperationalChecks::none(), ReportedValue::none('none'));
                }
            };

            RunRequest::fromInput($run, $path)->execute($runner);

            return $runner->calls;
        }

        public function testRunnerImplementsTheInterfaceAndTheInterfaceHasOnlyTheTwoReads(): void
        {
            self::assertTrue(\is_subclass_of(Runner::class, ReadOnlyRunnerInterface::class));
            self::assertSame(
                ['check', 'dryRun'],
                \array_map(
                    static fn (\ReflectionMethod $method): string => $method->getName(),
                    (new \ReflectionClass(ReadOnlyRunnerInterface::class))->getMethods()
                )
            );
        }

        /**
         * @return iterable<string, array{mixed, mixed, list<array{string, string|null}>}>
         */
        public static function readOnlyRuns(): iterable
        {
            yield 'whole-set check' => ['check', null, [['check', null]]];
            yield 'single-path check' => ['check', self::VENDOR, [['check', self::VENDOR]]];
            yield 'whole-set dry run' => ['dryrun', null, [['dryRun', null]]];
            yield 'single-path dry run' => ['dryrun', self::VENDOR, [['dryRun', self::VENDOR]]];
            yield 'no run means check' => [null, self::VENDOR, [['check', self::VENDOR]]];
        }

        /**
         * @param list<array{string, string|null}> $expected
         */
        #[DataProvider('readOnlyRuns')]
        public function testEachRunMakesExactlyTheMatchingCall(mixed $run, mixed $path, array $expected): void
        {
            self::assertSame($expected, self::calls($run, $path));
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
        public function testAnUnknownRunMakesOnlyTheWholeSetCheck(mixed $run): void
        {
            self::assertSame([['check', null]], self::calls($run, self::VENDOR));
            self::assertSame(RunRequest::NOTICE_UNKNOWN_RUN, RunRequest::fromInput($run, self::VENDOR)->notice);
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
            yield 'empty' => [''];
        }

        #[DataProvider('rawPaths')]
        public function testAPathReachesTheRunnerByteForByte(string $path): void
        {
            self::assertSame([['check', $path]], self::calls('check', $path));
            self::assertSame([['dryRun', $path]], self::calls('dryrun', $path));
        }

        public function testANonStringPathMakesNoSinglePathCall(): void
        {
            foreach ([[self::VENDOR], 1, true] as $path) {
                self::assertSame([['check', null]], self::calls('dryrun', $path));
                self::assertSame(RunRequest::NOTICE_PATH_NOT_TEXT, RunRequest::fromInput('dryrun', $path)->notice);
            }
        }
    }
}
