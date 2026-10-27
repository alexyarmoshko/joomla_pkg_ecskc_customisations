<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Payload;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\ECSKCCustomisation\Tests\Support\CbActivityController;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

/**
 * getRows() refills a page by calling itself. Each refill must keep the caller's
 * date filter and advance the loop counter. The vendor passes the counter in the
 * date's place, so the second refill filters on a.date >= '1'.
 *
 * The controller needs CB to load, so the probe runs the shipped method signature
 * and recursive statement, copied out of the file, inside a stand-in method body.
 * The probe file has no strict_types, like the vendor file, so PHP coerces the
 * integer counter into the string date exactly as it does on the site.
 */
#[CoversNothing]
final class CbActivityRefillTest extends TestCase
{
    private TempTree $tree;

    private static int $probes = 0;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-cbactivity-');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dates(): array
    {
        return [
            'no date filter' => [''],
            'a date filter'  => ['2026-10-01'],
        ];
    }

    #[DataProvider('dates')]
    public function testTwoRefillsOfThePayloadKeepTheDateAndAdvanceTheLoop(string $date): void
    {
        self::assertSame(
            [[$date, 0], [$date, 1], [$date, 2]],
            $this->calls(CbActivityController::payload(), $date)
        );
    }

    #[DataProvider('dates')]
    public function testTwoRefillsOfTheVendorOriginalReplaceTheDateWithTheLoop(string $date): void
    {
        self::assertSame(
            [[$date, 0], ['0', 1], ['1', 2]],
            $this->calls(CbActivityController::original(), $date)
        );
    }

    public function testTheBytesAreTheOnesTheRuleNames(): void
    {
        self::assertSame(CbActivityController::PAYLOAD_SHA256, \hash('sha256', CbActivityController::payload()));
        self::assertSame(CbActivityController::ORIGINAL_SHA256, \hash('sha256', CbActivityController::original()));
    }

    /**
     * Every getRows() call the controller bytes make from one page load, as
     * [date, loop] pairs, stopping after the second refill.
     *
     * @return list<array{string, int}>
     */
    private function calls(string $controller, string $date): array
    {
        self::assertSame(1, \preg_match('/^\s*(private function getRows\(.*\): array)\s*$/m', $controller, $signature));
        self::assertSame(1, \preg_match('/^\s*(\$rows\s*\+=\s*\\\\array_slice\( \$this->getRows\(.*;)\s*$/m', $controller, $refill));

        $class = 'CbActivityProbe' . ++self::$probes;
        $file  = $this->tree->write($class . '.php', <<<PHP
            <?php

            namespace YakShaver\\ECSKCCustomisation\\Tests\\Probe;

            final class {$class}
            {
                public array \$calls = [];

                public function load(string \$date): void
                {
                    \$hasMore = false;
                    \$this->getRows('0', '<', \$hasMore, 0, '', '', '', 0, \$date);
                }

                {$signature[1]}
                {
                    \$this->calls[] = [\$date, \$loop];

                    if (\$loop >= 2) {
                        return [];
                    }

                    \$lastPage = '1';
                    \$nextPage = 1;
                    \$rows     = [];

                    {$refill[1]}

                    return \$rows;
                }
            }
            PHP);

        require $file;

        $fqcn  = 'YakShaver\\ECSKCCustomisation\\Tests\\Probe\\' . $class;
        $probe = new $fqcn();
        $probe->load($date);

        return $probe->calls;
    }
}
