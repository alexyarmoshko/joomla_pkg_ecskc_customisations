<?php

declare(strict_types=1);

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Model {

    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use stdClass;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\ReportedValue;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\Reverter;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Model\WriteResult;
    use YakShaver\ECSKCCustomisation\Tests\Unit\Runner\RunnerHarness;

    /**
     * The copy of a write report the session holds, taken from real runner reports.
     */
    #[CoversClass(WriteResult::class)]
    final class WriteResultTest extends RunnerHarness
    {
        protected function setUp(): void
        {
            parent::setUp();

            $this->writeFixture();
        }

        /**
         * Every value at every depth, with the path to it.
         *
         * @return iterable<string, mixed>
         */
        private static function leaves(mixed $value, string $at = ''): iterable
        {
            if (!\is_array($value)) {
                yield $at => $value;

                return;
            }

            foreach ($value as $key => $item) {
                yield from self::leaves($item, $at . '/' . $key);
            }
        }

        /**
         * @return iterable<string, array{string, string|null}>
         */
        public static function writes(): iterable
        {
            yield 'whole-set apply' => [WriteResult::APPLY_ALL, null];
            yield 'single-path apply' => [WriteResult::APPLY, self::CHAIN];
            yield 'revert' => [WriteResult::REVERT, self::CHAIN];
            yield 'redo' => [WriteResult::REDO, self::CHAIN];
            yield 'freeze' => [WriteResult::FREEZE, self::CHAIN];
            yield 'unfreeze' => [WriteResult::UNFREEZE, self::CHAIN];
        }

        private function write(string $operation, ?string $path): RunReport
        {
            $runner = $this->runner();

            return match ($operation) {
                WriteResult::APPLY_ALL => $runner->apply(null),
                WriteResult::APPLY     => $runner->apply($path),
                WriteResult::REVERT    => $runner->revert((string) $path),
                WriteResult::REDO      => $runner->redo((string) $path),
                WriteResult::FREEZE    => $runner->freeze((string) $path),
                WriteResult::UNFREEZE  => $runner->unfreeze((string) $path),
            };
        }

        #[DataProvider('writes')]
        public function testTheStoredCopyHoldsNoObjectAndReadsBackAsItWasWritten(string $operation, ?string $path): void
        {
            $this->runner()->apply(null);

            $result = WriteResult::fromReport($operation, $path, $this->write($operation, $path));
            $stored = $result->toArray();

            foreach (self::leaves($stored) as $at => $leaf) {
                self::assertTrue($leaf === null || \is_scalar($leaf), 'Not a scalar at ' . $at);
            }

            // The session round trip, with no class allowed to come back.
            $read = WriteResult::fromArray(\unserialize(\serialize($stored), ['allowed_classes' => false]));

            self::assertEquals($result, $read);
            self::assertNotSame([], $read?->targets);
        }

        public function testTheRowsCarryTheReportsOwnText(): void
        {
            $report = $this->runner()->apply(null);
            $result = WriteResult::fromReport(WriteResult::APPLY_ALL, null, $report);

            self::assertSame($report->counts->byStatus, $result->counts);
            self::assertSame($report->counts->targets, $result->total);
            self::assertSame($report->faults(), $result->faults);
            self::assertCount(\count($report->targets()), $result->targets);

            foreach ($report->targets() as $index => $target) {
                $row = $result->targets[$index];

                self::assertSame($target->targetPath(), $row['path']);
                self::assertSame($target->status(), $row['status']);
                self::assertSame($target->describe(), $row['state']);
                self::assertSame($target->reason(), $row['reason']);
                self::assertSame($target->requiredFields(), $row['required']);
                self::assertSame($target->backup->describe(), $row['backup']);
                self::assertSame($target->outcome?->describe(), $row['outcome']);
                self::assertCount(\count($target->plan()), $row['plan']);
            }
        }

        public function testARevertThatMovedTheFileLiftsOutTheReapplyWarning(): void
        {
            $this->runner()->apply(self::CHAIN);

            $result = WriteResult::fromReport(WriteResult::REVERT, self::CHAIN, $this->runner()->revert(self::CHAIN));

            self::assertSame(\sprintf(Reverter::REAPPLY_WARNING, self::CHAIN), $result->warning);
        }

        /**
         * The warning travels as its own field, so the outcome's wording can change
         * without the page losing it.
         */
        public function testTheWarningSurvivesARewordedOutcome(): void
        {
            $this->runner()->apply(self::CHAIN);

            $report  = $this->runner()->revert(self::CHAIN);
            $targets = [];

            foreach ($report->targets() as $target) {
                $targets[] = new TargetReport(
                    $target->state,
                    $target->lastPackageWrite,
                    $target->backup,
                    $target->plan(),
                    ReportedValue::known('reworded'),
                    $target->warning
                );
            }

            $reworded = RunReport::of($targets, $report->checks, $report->interruption, $report->faults());
            $result   = WriteResult::fromReport(WriteResult::REVERT, self::CHAIN, $reworded);

            self::assertSame(\sprintf(Reverter::REAPPLY_WARNING, self::CHAIN), $result->warning);
        }

        public function testOnlyAMovedRevertCarriesTheWarning(): void
        {
            // Nothing applied yet, so there is nothing to step back from.
            $refused = WriteResult::fromReport(WriteResult::REVERT, self::CHAIN, $this->runner()->revert(self::CHAIN));
            self::assertNull($refused->warning);

            $this->runner()->apply(self::CHAIN);
            $reverted = $this->runner()->revert(self::CHAIN);

            // The same report, filed as another operation, says nothing extra.
            self::assertNull(WriteResult::fromReport(WriteResult::APPLY, self::CHAIN, $reverted)->warning);
            self::assertNull(WriteResult::fromReport(WriteResult::REVERT, self::VENDOR, $reverted)->warning);
        }

        public function testAFaultedRunKeepsItsFaultsVerbatim(): void
        {
            $path   = self::VENDOR . '<script>';
            $report = $this->runner()->apply($path);
            $result = WriteResult::fromReport(WriteResult::APPLY, $path, $report);

            self::assertTrue($report->isFaulted());
            self::assertSame($report->faults(), $result->faults);
            self::assertSame([], $result->targets);
        }

        /**
         * @return iterable<string, array{mixed}>
         */
        public static function malformed(): iterable
        {
            $valid = [
                'operation'    => WriteResult::REVERT,
                'path'         => 'a.css',
                'faults'       => [],
                'interruption' => null,
                'counts'       => ['ok' => 1],
                'total'        => 1,
                'targets'      => [[
                    'path'     => 'a.css',
                    'status'   => 'ok',
                    'state'    => 'ok',
                    'reason'   => 'r',
                    'required' => ['target path' => 'a.css'],
                    'backup'   => 'b',
                    'objects'  => [],
                    'plan'     => [],
                    'outcome'  => null,
                ]],
                'warning'      => null,
            ];

            yield 'nothing stored' => [null];
            yield 'a string' => ['revert'];
            yield 'an object' => [(object) $valid];
            yield 'unknown operation' => [['operation' => 'delete'] + $valid];
            yield 'operation in another case' => [['operation' => 'applyAll'] + $valid];
            yield 'missing path' => [\array_diff_key($valid, ['path' => true])];
            yield 'path not text' => [['path' => ['a.css']] + $valid];
            yield 'fault not text' => [['faults' => [new stdClass()]] + $valid];
            yield 'faults not a list' => [['faults' => ['x' => 'y']] + $valid];
            yield 'count not an integer' => [['counts' => ['ok' => '1']] + $valid];
            yield 'total not an integer' => [['total' => '1'] + $valid];
            yield 'targets not a list' => [['targets' => ['a' => $valid['targets'][0]]] + $valid];
            yield 'row an object' => [['targets' => [(object) $valid['targets'][0]]] + $valid];
            yield 'row missing its plan' => [['targets' => [\array_diff_key($valid['targets'][0], ['plan' => true])]] + $valid];
            yield 'row field not text' => [['targets' => [['reason' => 1] + $valid['targets'][0]]] + $valid];
            yield 'required value not text' => [['targets' => [['required' => ['target path' => null]] + $valid['targets'][0]]] + $valid];
            yield 'warning not text' => [['warning' => true] + $valid];
        }

        #[DataProvider('malformed')]
        public function testAnythingElseReadsBackAsNoResult(mixed $stored): void
        {
            self::assertNull(WriteResult::fromArray($stored));
        }
    }
}
