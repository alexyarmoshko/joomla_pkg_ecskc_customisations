<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalCheck;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalChecks;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\ReportedValue;

/**
 * The two report values that carry no state of their own, tested directly.
 */
#[CoversClass(ReportedValue::class)]
#[CoversClass(OperationalCheck::class)]
#[CoversClass(OperationalChecks::class)]
final class ReportValuesTest extends TestCase
{
    public function testAKnownValueRendersAsItself(): void
    {
        $value = ReportedValue::known('applied version 2 on 2026-09-02');

        self::assertTrue($value->isKnown());
        self::assertTrue($value->isDetermined());
        self::assertSame('applied version 2 on 2026-09-02', $value->describe());
        self::assertNull($value->reason);
    }

    /**
     * The distinction the whole class exists for. "There is no write recorded" and
     * "the record could not be read" both render as a reason, and only one of them
     * is an answer.
     */
    public function testADeterminedNegativeIsNotTheSameAsAFault(): void
    {
        $none        = ReportedValue::none('no write is recorded at this target');
        $unavailable = ReportedValue::unavailable('the event log could not be read');

        self::assertFalse($none->isKnown());
        self::assertTrue($none->isDetermined());
        self::assertSame('no write is recorded at this target', $none->describe());

        self::assertFalse($unavailable->isKnown());
        self::assertFalse($unavailable->isDetermined());
        self::assertSame('the event log could not be read', $unavailable->describe());
    }

    public function testAnEmptySectionIsPresentAndHoldsNothing(): void
    {
        $checks = OperationalChecks::none();

        self::assertTrue($checks->isEmpty());
        self::assertSame(0, $checks->count());
        self::assertSame([], $checks->all());
        self::assertSame([], $checks->notifiable());
    }

    public function testASectionSeparatesTheChecksWorthSayingSomethingAbout(): void
    {
        $enabled = new OperationalCheck('system_plugin_enabled', 'enabled', false, 'the site plugin is enabled');
        $unknown = new OperationalCheck(
            'system_plugin_enabled',
            'unknown',
            true,
            'the extensions row is absent, so the package cannot tell'
        );

        $checks = OperationalChecks::of($enabled, $unknown);

        self::assertFalse($checks->isEmpty());
        self::assertSame(2, $checks->count());
        self::assertSame([$unknown], $checks->notifiable());
        self::assertSame(
            'system_plugin_enabled: unknown, the extensions row is absent, so the package cannot tell',
            $unknown->describe()
        );
    }
}
