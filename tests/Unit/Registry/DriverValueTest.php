<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryReadFailure;
use YakShaver\Component\ECSKCCustomisation\Administrator\Registry\DriverValue;

#[CoversClass(DriverValue::class)]
final class DriverValueTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function integers(): iterable
    {
        yield 'int' => [7, 7];
        yield 'int 0' => [0, 0];
        yield 'numeric string' => ['42', 42];
        yield 'string 0' => ['0', 0];
        yield 'negative string' => ['-1', -1];
    }

    #[DataProvider('integers')]
    public function testAnIntegerOrItsDigitsAreAccepted(mixed $value, int $expected): void
    {
        self::assertSame($expected, DriverValue::integer((object) ['enabled' => $value], 'enabled', '#__extensions.enabled'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function refusedIntegers(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'empty string' => ['', "''"];
        yield 'decimal' => ['1.0', "'1.0'"];
        yield 'leading zero' => ['01', "'01'"];
        yield 'padded' => [' 1', "' 1'"];
        yield 'word' => ['yes', "'yes'"];
        yield 'bool' => [true, 'bool'];
        yield 'float' => [1.0, 'float'];
        yield 'past PHP_INT_MAX' => ['99999999999999999999', "'99999999999999999999'"];
    }

    #[DataProvider('refusedIntegers')]
    public function testAnythingElseIsAReadFailureNamingTheColumnAndValue(mixed $value, string $shown): void
    {
        $this->expectException(RegistryReadFailure::class);
        $this->expectExceptionMessage('#__extensions.enabled: the database returned ' . $shown . ' where an integer is expected');

        DriverValue::integer((object) ['enabled' => $value], 'enabled', '#__extensions.enabled');
    }

    public function testTextIsReturnedAsItIs(): void
    {
        self::assertSame(' 1.0 ', DriverValue::text((object) ['version' => ' 1.0 '], 'version', '#__updates.version'));
    }

    public function testNullTextReadsAsEmpty(): void
    {
        self::assertSame('', DriverValue::text((object) ['version' => null], 'version', '#__updates.version'));
    }

    public function testTextThatIsNotAStringIsAReadFailure(): void
    {
        $this->expectException(RegistryReadFailure::class);
        $this->expectExceptionMessage('#__updates.version: the database returned int where text is expected');

        DriverValue::text((object) ['version' => 1], 'version', '#__updates.version');
    }

    public function testAMissingColumnIsAReadFailure(): void
    {
        $this->expectException(RegistryReadFailure::class);
        $this->expectExceptionMessage('#__extensions.state: the database returned no such column');

        DriverValue::integer((object) [], 'state', '#__extensions.state');
    }
}
