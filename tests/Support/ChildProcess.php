<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Support;

use RuntimeException;

/**
 * Runs a command to completion and returns its exit status and both output streams.
 *
 * The streams go to temporary files, not pipes. A child that fills the stderr pipe
 * while the parent is still reading stdout to its end blocks for ever, and a file
 * has no buffer to fill.
 */
final class ChildProcess
{
    /**
     * @param list<string> $command The program and its arguments, run without a shell.
     *
     * @return array{int, string, string} The exit status, stdout and stderr.
     */
    public static function run(array $command): array
    {
        $stdout = \tmpfile();
        $stderr = \tmpfile();

        if ($stdout === false || $stderr === false) {
            throw new RuntimeException('Could not create the temporary files for a child\'s output');
        }

        try {
            $pipes   = [];
            $process = \proc_open(
                $command,
                [0 => ['file', '/dev/null', 'r'], 1 => $stdout, 2 => $stderr],
                $pipes
            );

            if ($process === false) {
                throw new RuntimeException(\sprintf('Could not start %s', $command[0] ?? 'the child'));
            }

            $status = \proc_close($process);

            \rewind($stdout);
            \rewind($stderr);

            return [$status, (string) \stream_get_contents($stdout), (string) \stream_get_contents($stderr)];
        } finally {
            \fclose($stdout);
            \fclose($stderr);
        }
    }
}
