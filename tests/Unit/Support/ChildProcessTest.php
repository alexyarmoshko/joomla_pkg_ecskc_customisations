<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use YakShaver\ECSKCCustomisation\Tests\Support\ChildProcess;

/**
 * The subprocess helper the CLI, package and backup tests share.
 */
#[CoversNothing]
final class ChildProcessTest extends TestCase
{
    public function testBothStreamsAreReturnedApart(): void
    {
        [$status, $stdout, $stderr] = ChildProcess::run([
            \PHP_BINARY,
            '-r',
            'echo "to stdout"; fwrite(STDERR, "to stderr");',
        ]);

        self::assertSame(0, $status);
        self::assertSame('to stdout', $stdout);
        self::assertSame('to stderr', $stderr);
    }

    public function testANonZeroExitStatusIsReturned(): void
    {
        [$status, $stdout, $stderr] = ChildProcess::run([\PHP_BINARY, '-r', 'exit(3);']);

        self::assertSame(3, $status);
        self::assertSame('', $stdout);
        self::assertSame('', $stderr);
    }

    /**
     * More than a pipe buffer on stderr before anything on stdout. Read through
     * pipes, stdout first, this child would block for ever.
     */
    public function testAChildThatFillsStderrFirstDoesNotHang(): void
    {
        [$status, $stdout, $stderr] = ChildProcess::run([
            \PHP_BINARY,
            '-r',
            'fwrite(STDERR, str_repeat("e", 1 << 20)); echo "done";',
        ]);

        self::assertSame(0, $status);
        self::assertSame('done', $stdout);
        self::assertSame(1 << 20, \strlen($stderr));
    }
}
