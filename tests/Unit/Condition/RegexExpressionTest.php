<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Condition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition\RegexExpression;

#[CoversClass(RegexExpression::class)]
final class RegexExpressionTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function delimiterChoices(): array
    {
        return [
            'no delimiter in the operand'  => ['^ecskc\.eu$', '/^ecskc\.eu$/D'],
            'a slash moves to the tilde'   => ['^https://ecskc\.eu$', '~^https://ecskc\.eu$~D'],
            'the first two both appear'    => ['^a/b~c$', '#^a/b~c$#D'],
            'only the last one is free'    => ['/~#%!@;,|+=', ':/~#%!@;,|+=:D'],
        ];
    }

    #[DataProvider('delimiterChoices')]
    public function testTheFirstDelimiterAbsentFromTheOperandIsUsed(string $operand, string $expected): void
    {
        self::assertSame($expected, RegexExpression::of($operand));
    }

    public function testAnOperandUsingEveryDelimiterHasNoExpression(): void
    {
        self::assertNull(RegexExpression::of('/~#%!@;,|+=:'));
    }

    /**
     * Without D, `$` also matches before a final newline, and an exclusion of
     * `ecskc.eu` would hold for "ecskc.eu\n".
     */
    public function testDollarAnchorsAtTheEndOfTheSubjectAndNotBeforeAFinalNewline(): void
    {
        $expression = (string) RegexExpression::of('^ecskc\.eu$');

        self::assertSame(1, \preg_match($expression, 'ecskc.eu'));
        self::assertSame(0, \preg_match($expression, "ecskc.eu\n"));
    }
}
