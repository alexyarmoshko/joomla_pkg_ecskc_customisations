<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Support;

use Closure;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * A crash simulated at a write's named checkpoint, and the check that it happened.
 */
final class Crash
{
    private const MESSAGE = 'simulated crash at ';

    /**
     * A checkpoint closure that throws when the write reaches `$point`.
     */
    public static function at(string $point): Closure
    {
        return static function (string $reached) use ($point): void {
            if ($reached === $point) {
                throw new RuntimeException(self::MESSAGE . $point);
            }
        };
    }

    /**
     * Run `$operation` and fail unless it stopped at its simulated crash.
     *
     * Any other exception propagates as it was thrown. PHPUnit's own assertion
     * failures are runtime exceptions too, and must keep their message and trace.
     *
     * @param Closure(): mixed $operation
     */
    public static function assertReached(Closure $operation): void
    {
        $crash = null;

        try {
            $operation();
        } catch (RuntimeException $thrown) {
            if (!\str_starts_with($thrown->getMessage(), self::MESSAGE)) {
                throw $thrown;
            }

            $crash = $thrown;
        }

        Assert::assertNotNull($crash, 'The operation did not reach its crash point.');
    }
}
