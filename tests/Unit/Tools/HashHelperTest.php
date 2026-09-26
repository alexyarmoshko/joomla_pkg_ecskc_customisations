<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\ECSKCCustomisation\Tests\Support\ChildProcess;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

/**
 * The authoring helper's reported byte shape, not package hashing.
 *
 * `lineEndings()` names a lone CR as a legal form. A file that terminates on CR
 * therefore has a final newline, the same way one that terminates on LF or CRLF
 * does. Review s1.v43 found `endsWithNewline()` looking only for a terminal LF.
 */
#[CoversNothing]
final class HashHelperTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-hash-helper-');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function textShapesAndTheirReportedNewline(): array
    {
        return [
            'LF' => [
                "line\n",
                'LF (1)',
                'yes',
            ],
            'CRLF' => [
                "line\r\n",
                'CRLF (1)',
                'yes',
            ],
            'bare CR' => [
                "line\r",
                'bare CR (1)',
                'yes',
            ],
            'empty' => [
                '',
                'none',
                'no',
            ],
            'unterminated' => [
                'line',
                'none',
                'no',
            ],
        ];
    }

    #[DataProvider('textShapesAndTheirReportedNewline')]
    public function testFinalNewlineFollowsTheLastByteNotOnlyLf(
        string $bytes,
        string $lineEndings,
        string $finalNewline
    ): void {
        $path   = $this->tree->write('sample.bin', $bytes);
        $report = $this->reportFor($path);

        self::assertSame($lineEndings, $report['line endings'], $report['raw']);
        self::assertSame($finalNewline, $report['final newline'], $report['raw']);
        self::assertSame((string) \strlen($bytes), $report['bytes'], $report['raw']);
    }

    /**
     * @return array{bytes: string, line endings: string, final newline: string, raw: string}
     */
    private function reportFor(string $path): array
    {
        $script = \dirname(__DIR__, 3) . '/tools/hash.php';

        [$status, $stdout, $stderr] = ChildProcess::run([\PHP_BINARY, $script, $path]);

        self::assertSame(0, $status, $stderr === '' ? $stdout : $stderr);
        self::assertSame('', $stderr, $stdout);

        $fields = [];

        foreach (['bytes', 'line endings', 'final newline'] as $name) {
            $pattern = '/^\s*' . \preg_quote($name, '/') . ':\s*(.+)$/m';
            self::assertSame(
                1,
                \preg_match($pattern, $stdout, $match),
                \sprintf("The helper did not report %s.\n%s", $name, $stdout)
            );
            $fields[$name] = $match[1];
        }

        $fields['raw'] = $stdout;

        return $fields;
    }
}
