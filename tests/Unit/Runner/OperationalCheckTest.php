<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Runner;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Operational\ExtensionRecordRead;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Operational\SystemPluginCheck;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalCheck;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;

/**
 * `system_plugin_enabled`, derived by the core from the raw `enabled` value a stub
 * provider returns, with no Joomla database.
 *
 * Both the integer and the string forms are here because the contract accepts both:
 * Joomla's driver returns `int(1)`, a plain mysqli read returns `"1"`.
 */
#[CoversClass(Runner::class)]
#[CoversClass(SystemPluginCheck::class)]
#[CoversClass(ExtensionRecordRead::class)]
final class OperationalCheckTest extends RunnerHarness
{
    /**
     * @return iterable<string, array{ExtensionRecordRead, string, bool, string}>
     */
    public static function rawReads(): iterable
    {
        // The plan's table, in its order.
        yield 'integer 1' => [ExtensionRecordRead::value(1), 'enabled', false, 'is enabled'];
        yield 'integer 0' => [ExtensionRecordRead::value(0), 'disabled', true, 'missing every rule in override.css'];
        yield 'string 1' => [ExtensionRecordRead::value('1'), 'enabled', false, 'is enabled'];
        yield 'string 0' => [ExtensionRecordRead::value('0'), 'disabled', true, 'missing every rule in override.css'];
        yield 'absent row' => [ExtensionRecordRead::value(null), 'unknown', true, 'no extension record exists'];
        yield 'read failure' => [
            ExtensionRecordRead::failure('Table jos_extensions is locked'),
            'unknown',
            true,
            'could not be read, so whether the site loads override.css cannot be determined: '
                . 'Table jos_extensions is locked',
        ];
        yield 'string 01' => [ExtensionRecordRead::value('01'), 'unknown', true, 'holds the string "01"'];
        yield 'integer 2' => [ExtensionRecordRead::value(2), 'unknown', true, 'holds the integer 2'];

        // What a loose comparison or a cast would let through as enabled.
        yield 'boolean true' => [ExtensionRecordRead::value(true), 'unknown', true, 'holds the boolean true'];
        yield 'float 1.0' => [ExtensionRecordRead::value(1.0), 'unknown', true, 'holds the float 1.0'];
        yield 'string space 1' => [ExtensionRecordRead::value(' 1'), 'unknown', true, 'holds the string " 1"'];
        yield 'string nonsense' => [ExtensionRecordRead::value('nonsense'), 'unknown', true, 'holds the string "nonsense"'];
    }

    #[DataProvider('rawReads')]
    public function testACheckDerivesTheOutcomeAndLeavesEveryTargetAsItWas(
        ExtensionRecordRead $read,
        string $outcome,
        bool $notifiable,
        string $reason,
    ): void {
        $this->writeFixture();
        $baseline = $this->runner()->check();

        $this->operational->read = $read;
        $report = $this->runner()->check();

        $this->assertTheCheck($report, $outcome, $notifiable, $reason);
        self::assertSame($this->lines($baseline), $this->lines($report), 'no per-target state changes');
        self::assertSame($baseline->counts->byStatus, $report->counts->byStatus);
        self::assertSame($baseline->counts->targets, $report->counts->targets);
        self::assertSame(
            $report->counts->targets,
            \array_sum($report->counts->byStatus),
            'the check is not counted as a target'
        );
    }

    #[DataProvider('rawReads')]
    public function testADryRunDerivesTheSameOutcome(
        ExtensionRecordRead $read,
        string $outcome,
        bool $notifiable,
        string $reason,
    ): void {
        $this->writeFixture();
        $this->operational->read = $read;

        $this->assertTheCheck($this->runner()->dryRun(), $outcome, $notifiable, $reason);
    }

    /**
     * The dashboard's entry point: the same outcome, without a rule set to load.
     */
    #[DataProvider('rawReads')]
    public function testTheStandaloneCheckDerivesTheSameOutcomeWithoutRules(
        ExtensionRecordRead $read,
        string $outcome,
        bool $notifiable,
        string $reason,
    ): void {
        $this->tree->write(self::PACKAGE . '/rules/05-broken.xml', '<patch hashType="sha256"><nonsense/></patch>');
        $this->operational->read = $read;

        $check = $this->runner()->systemPluginCheck();

        self::assertSame(SystemPluginCheck::NAME, $check->name);
        self::assertSame($outcome, $check->outcome);
        self::assertSame($notifiable, $check->notifiable);
        self::assertStringContainsString($reason, $check->reason);
        self::assertSame(1, $this->operational->reads);
    }

    public function testASinglePathCheckCarriesTheSection(): void
    {
        $this->writeFixture();
        $this->operational->read = ExtensionRecordRead::value(0);

        $this->assertTheCheck($this->runner()->check(self::VENDOR), 'disabled', true, 'missing every rule');
    }

    /**
     * A disabled plugin is still worth knowing when the rules are broken: the check
     * does not read the rule set.
     */
    public function testAFaultedRuleSetStillCarriesTheSection(): void
    {
        $this->writeFixture();
        $this->tree->write(self::PACKAGE . '/rules/05-broken.xml', '<patch hashType="sha256"><nonsense/></patch>');
        $this->operational->read = ExtensionRecordRead::value(0);

        foreach ([$this->runner()->check(), $this->runner()->dryRun()] as $report) {
            self::assertTrue($report->isFaulted());
            self::assertSame([], $report->targets());
            $this->assertTheCheck($report, 'disabled', true, 'missing every rule');
        }
    }

    public function testAnUndeclaredPathStillCarriesTheSection(): void
    {
        $this->writeFixture();
        $this->operational->read = ExtensionRecordRead::value(null);

        $report = $this->runner()->check('images/not-managed.css');

        self::assertTrue($report->isFaulted());
        $this->assertTheCheck($report, 'unknown', true, 'no extension record exists');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writeOperations(): iterable
    {
        foreach (['apply', 'freeze', 'unfreeze', 'revert', 'redo'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('writeOperations')]
    public function testAWriteRunLeavesTheSectionEmptyAndNeverReadsTheRecord(string $operation): void
    {
        $this->writeFixture();
        $this->operational->read = ExtensionRecordRead::value(0);

        $report = $this->runner()->{$operation}(self::VENDOR);

        self::assertTrue($report->checks->isEmpty());
        self::assertSame(0, $report->counts->checks);
        self::assertSame(0, $this->operational->reads);
    }

    public function testAReadFailureReasonStaysOnOneLine(): void
    {
        $this->writeFixture();
        $this->operational->read = ExtensionRecordRead::failure("connection lost\nat line 2");

        $check = $this->onlyCheck($this->runner()->check());

        self::assertStringNotContainsString("\n", $check->reason);
        self::assertStringContainsString('connection lost\\nat line 2', $check->reason);
    }

    private function assertTheCheck(RunReport $report, string $outcome, bool $notifiable, string $reason): void
    {
        $check = $this->onlyCheck($report);

        self::assertSame(SystemPluginCheck::NAME, $check->name);
        self::assertSame($outcome, $check->outcome);
        self::assertSame($notifiable, $check->notifiable);
        self::assertStringContainsString($reason, $check->reason);
        self::assertSame(1, $report->counts->checks);
        self::assertSame($notifiable ? 1 : 0, $report->counts->notifiableChecks);
    }

    private function onlyCheck(RunReport $report): OperationalCheck
    {
        self::assertSame(1, $report->checks->count());

        return $report->checks->all()[0];
    }

    /**
     * @return array<string, string>
     */
    private function lines(RunReport $report): array
    {
        $lines = [];

        foreach ($report->targets() as $target) {
            $lines[$target->targetPath()] = $target->describe() . ' | ' . $target->reason();
        }

        return $lines;
    }
}
