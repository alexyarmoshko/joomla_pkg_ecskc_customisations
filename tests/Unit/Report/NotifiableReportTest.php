<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\HashResult;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalCheck;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalChecks;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\ReportedValue;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetState;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;

/**
 * Which reports the installer plugin tells the operator about, decisions 7, 8, 22 and 51.
 *
 * This is core logic so the component and the installer plugin cannot disagree.
 */
#[CoversClass(RunReport::class)]
#[CoversClass(TargetStatus::class)]
final class NotifiableReportTest extends TestCase
{
    public static function target(string $status, string $path = 'templates/et_tennis/options.xml'): TargetReport
    {
        return new TargetReport(
            TargetState::of($path, $status, 'a reason', HashResult::digest('aa'), HashResult::digest('bb')),
            ReportedValue::none('no write is recorded at this target'),
            ReportedValue::none('no backup is recorded at this target')
        );
    }

    public static function frozen(string $underlying): TargetReport
    {
        return new TargetReport(
            TargetState::frozen(
                TargetState::of('templates/et_tennis/options.xml', $underlying, 'a reason', HashResult::digest('aa'), HashResult::digest('bb'))
            ),
            ReportedValue::none('no write is recorded at this target'),
            ReportedValue::none('no backup is recorded at this target')
        );
    }

    private static function report(TargetReport ...$targets): RunReport
    {
        return RunReport::of(\array_values($targets), OperationalChecks::none(), ReportedValue::none('no run was interrupted'));
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
    public function testEachStatusIsNotifiableExactlyWhenTheDecisionsSaySo(string $status, bool $notifiable): void
    {
        $target = self::target($status);
        $report = self::report($target);

        self::assertSame($notifiable, $report->needsAttention());
        self::assertSame($notifiable ? [$target] : [], $report->notifiableTargets());
    }

    public function testTheNotifiableSetIsFourOfTheSevenAndLeavesOutFrozen(): void
    {
        self::assertSame(
            [TargetStatus::ACTIONABLE, TargetStatus::INCOMPLETE, TargetStatus::BLOCKED, TargetStatus::FAULTED],
            TargetStatus::NOTIFIABLE
        );
        self::assertSame([], \array_diff(TargetStatus::NOTIFIABLE, TargetStatus::ALL));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notifiableStatuses(): iterable
    {
        foreach (TargetStatus::NOTIFIABLE as $status) {
            yield $status => [$status];
        }
    }

    #[DataProvider('notifiableStatuses')]
    public function testAFrozenPathStaysSilentWhateverItWouldOtherwiseBe(string $underlying): void
    {
        $report = self::report(self::frozen($underlying));

        self::assertFalse($report->needsAttention());
        self::assertSame([], $report->notifiableTargets());
    }

    public function testOnlyTheNotifiableTargetsAreListedInReportOrder(): void
    {
        $blocked    = self::target(TargetStatus::BLOCKED, 'b.php');
        $actionable = self::target(TargetStatus::ACTIONABLE, 'a.php');

        $report = self::report(
            self::target(TargetStatus::OK, 'ok.php'),
            $blocked,
            self::frozen(TargetStatus::ACTIONABLE),
            $actionable
        );

        self::assertSame([$blocked, $actionable], $report->notifiableTargets());
    }

    public function testANotifiableCheckNeedsAttentionWhenEveryTargetIsOk(): void
    {
        $report = RunReport::of(
            [self::target(TargetStatus::OK)],
            OperationalChecks::of(new OperationalCheck('system_plugin_enabled', 'disabled', true, 'off')),
            ReportedValue::none('no run was interrupted')
        );

        self::assertTrue($report->needsAttention());
        self::assertSame([], $report->notifiableTargets());
    }

    public function testACheckThatIsNotNotifiableAddsNothing(): void
    {
        $report = RunReport::of(
            [self::target(TargetStatus::OK)],
            OperationalChecks::of(new OperationalCheck('system_plugin_enabled', 'enabled', false, 'on')),
            ReportedValue::none('no run was interrupted')
        );

        self::assertFalse($report->needsAttention());
    }

    public function testARunLevelFaultWithNoTargetsNeedsAttention(): void
    {
        $report = RunReport::faulted(
            ['the rule set does not load'],
            OperationalChecks::none(),
            ReportedValue::none('no run was interrupted')
        );

        self::assertTrue($report->needsAttention());
    }

    public function testAnEmptyHealthyReportNeedsNothing(): void
    {
        self::assertFalse(self::report()->needsAttention());
    }
}
