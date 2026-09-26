<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Support;

use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use YakShaver\ECSKCCustomisation\Tests\Support\Crash;

/**
 * The crash simulation the writer and freezer tests share.
 */
#[CoversNothing]
final class CrashTest extends TestCase
{
    public function testACrashAtTheNamedPointIsReached(): void
    {
        $checkpoint = Crash::at('mutated');

        Crash::assertReached(static function () use ($checkpoint): void {
            $checkpoint('intent published');
            $checkpoint('mutated');
        });
    }

    public function testAnAssertionFailingInsideTheOperationKeepsItsOwnMessage(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('the target changed');

        Crash::assertReached(static function (): void {
            self::fail('the target changed');
        });
    }

    public function testAMissedCrashPointFails(): void
    {
        $checkpoint = Crash::at('mutated');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('did not reach its crash point');

        Crash::assertReached(static function () use ($checkpoint): void {
            $checkpoint('intent published');
        });
    }

    public function testAnUnrelatedRuntimeExceptionPropagatesAsItWasThrown(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the disk is full');

        Crash::assertReached(static function (): void {
            throw new RuntimeException('the disk is full');
        });
    }

    public function testAnUnrelatedExceptionOfAnotherTypeIsNotSwallowed(): void
    {
        $this->expectException(LogicException::class);

        Crash::assertReached(static function (): void {
            throw new LogicException('a bug in the operation');
        });
    }
}
