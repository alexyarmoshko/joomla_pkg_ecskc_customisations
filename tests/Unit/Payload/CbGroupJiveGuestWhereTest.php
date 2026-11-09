<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Payload;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\ECSKCCustomisation\Tests\Support\CbGroupJiveFiles;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

/**
 * For a guest, GroupJive 4.0.1 pushes two group conditions onto $queryWhere and
 * joins the array with " AND ". The vendor strings still begin with " AND ", left
 * over from 3.7.0, where they were appended to the query directly.
 *
 * The files need CB to load, so the probe runs each shipped guest branch, copied
 * out of the file, between the condition the vendor puts before it and the one it
 * puts after it, and joins the result the way the vendor does.
 */
#[CoversNothing]
final class CbGroupJiveGuestWhereTest extends TestCase
{
    private const BEFORE = '( e.`user_id` = 0 OR e.`published` = 1 )';
    private const AFTER  = '( ( c.`published` = 1 AND c.`access` IN (1,5) ) OR g.`category` = 0 )';

    private TempTree $tree;

    private static int $probes = 0;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-cbgroupjive-');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function targets(): array
    {
        return [
            'events component' => [CbGroupJiveFiles::EVENTS],
            'module'           => [CbGroupJiveFiles::MODULE],
        ];
    }

    #[DataProvider('targets')]
    public function testEveryGuestBranchOfThePayloadBuildsValidConditions(string $target): void
    {
        $wheres = $this->guestWheres(CbGroupJiveFiles::payload($target));

        self::assertSame(\array_fill(0, \count($wheres), self::valid()), $wheres);
    }

    #[DataProvider('targets')]
    public function testTheVendorOriginalDoublesTheAndInEachFixedBranch(string $target): void
    {
        $wheres  = $this->guestWheres(CbGroupJiveFiles::original($target));
        $doubled = self::BEFORE . ' AND  AND g.`published` = 1 AND  AND g.`type` != 3 AND ' . self::AFTER;

        self::assertCount(
            \intdiv(\count(CbGroupJiveFiles::FILES[$target][2]), 2),
            \array_filter($wheres, static fn (string $where): bool => $where === $doubled)
        );
        self::assertSame(
            [],
            \array_values(\array_filter($wheres, static fn (string $where): bool => $where !== $doubled && $where !== self::valid()))
        );
    }

    #[DataProvider('targets')]
    public function testTheBytesAreTheOnesTheRuleNames(string $target): void
    {
        [$original, $payload] = CbGroupJiveFiles::FILES[$target];

        self::assertSame($payload, \hash('sha256', CbGroupJiveFiles::payload($target)));
        self::assertSame($original, \hash('sha256', CbGroupJiveFiles::original($target)));
    }

    /**
     * The WHERE body each guest branch in the file produces.
     *
     * @return list<string>
     */
    private function guestWheres(string $source): array
    {
        $count = \preg_match_all(
            '/\}\s*else\s*\{\s*(\$queryWhere\[\][^;]*published[^;]*;\s*\$queryWhere\[\][^;]*type[^;]*;)\s*\}/',
            $source,
            $branches
        );
        self::assertGreaterThan(0, $count, 'The file has a guest branch.');

        $wheres = [];

        foreach ($branches[1] as $branch) {
            $class = 'CbGroupJiveProbe' . ++self::$probes;
            $file  = $this->tree->write($class . '.php', <<<PHP
                <?php

                namespace YakShaver\\ECSKCCustomisation\\Tests\\Probe;

                final class {$class}
                {
                    public function NameQuote(string \$name): string
                    {
                        return '`' . \$name . '`';
                    }

                    public function where(): string
                    {
                        \$_CB_database = \$this;
                        \$queryWhere   = [\\YakShaver\\ECSKCCustomisation\\Tests\\Unit\\Payload\\CbGroupJiveGuestWhereTest::before()];

                        {$branch}

                        \$queryWhere[] = \\YakShaver\\ECSKCCustomisation\\Tests\\Unit\\Payload\\CbGroupJiveGuestWhereTest::after();

                        return \\implode(' AND ', \$queryWhere);
                    }
                }
                PHP);

            require $file;

            $fqcn     = 'YakShaver\\ECSKCCustomisation\\Tests\\Probe\\' . $class;
            $wheres[] = (new $fqcn())->where();
        }

        return $wheres;
    }

    private static function valid(): string
    {
        return self::BEFORE . ' AND g.`published` = 1 AND g.`type` != 3 AND ' . self::AFTER;
    }

    public static function before(): string
    {
        return self::BEFORE;
    }

    public static function after(): string
    {
        return self::AFTER;
    }
}
