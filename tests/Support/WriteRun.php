<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Support;

use RuntimeException;

/**
 * A separate process working on the tree, for the tests that need a real one.
 *
 * Three things cannot be proved inside one process. A lock released because its
 * holder died is one: only a real death releases it that way. A run that is live
 * while another process looks at the tree is the second. A run refused because a
 * run-state read holds the lock is the third, and it needs the read to be the one
 * in the other process.
 *
 * An exclusive lock per event-log append is the fourth: two appends inside one process
 * are serial whatever the lock does.
 *
 * The children are tests/Support/write_run.php, tests/Support/state_read.php,
 * tests/Support/hold_fifo.php and tests/Support/log_append.php. The first three report
 * one line once they are where the test needs them, so a test never inspects the tree
 * before the child has finished arranging it. The appender reports nothing and runs to
 * completion instead, because what a test needs there is two children working at the
 * same time rather than one holding still; wait() is how it collects them. Nothing here
 * waits without a deadline, and kill() ends the child however it is blocked.
 */
final class WriteRun
{
    private const HELD = 'held';

    private const READING = 'reading';

    private const OPENED = 'opened';

    /** How long to wait for the child to report itself ready, in seconds. */
    private const TIMEOUT = 10.0;

    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private bool $running = true;

    /** Everything read from the child's stdout so far. */
    private string $output = '';

    /**
     * @param resource            $process
     * @param array<int, resource> $pipes
     */
    private function __construct($process, array $pipes)
    {
        $this->process = $process;
        $this->pipes   = $pipes;
    }

    /**
     * Start a run and return once it holds the lock.
     *
     * @param string      $packageRoot The package tree the run works in.
     * @param string      $mode        marker, no-marker, or marker+intent.
     * @param string|null $id          A fixed correlation id, for marker+intent.
     */
    public static function holding(string $packageRoot, string $mode = 'marker', ?string $id = null): self
    {
        $command = [\PHP_BINARY, \dirname(__DIR__) . '/Support/write_run.php', $packageRoot, $mode];

        if ($id !== null) {
            $command[] = $id;
        }

        return self::started($command, self::HELD);
    }

    /**
     * Start a run-state read that never finishes, and return once it has started.
     *
     * The tree's run marker has to be a FIFO for this: the child stops on it, inside
     * read(), for as long as the test needs. Which is the point, because whether the
     * lock is held there is what a caller in this process can then ask the lock
     * itself. This returns once the child is running, which is before it is inside
     * the read; holdingOpen() is what says it has got there.
     */
    public static function reading(string $packageRoot): self
    {
        return self::started(
            [\PHP_BINARY, \dirname(__DIR__) . '/Support/state_read.php', $packageRoot],
            self::READING
        );
    }

    /**
     * Open a FIFO's write end and hold it, returning once the open has completed.
     *
     * This is how a test knows a reading process is inside the read rather than
     * merely heading for it. Opening a FIFO for writing completes only once someone
     * has opened the same FIFO for reading, so the return here and the reader's
     * arrival in read() are the same event. Nothing is written, so the reader stays
     * in there until this child is killed, and a test has an unhurried window in
     * which to contend for the lock.
     */
    public static function holdingOpen(string $fifo): self
    {
        return self::started(
            [\PHP_BINARY, \dirname(__DIR__) . '/Support/hold_fifo.php', $fifo],
            self::OPENED
        );
    }

    /**
     * Start a run of event-log appends and return at once, without waiting.
     *
     * Returning immediately is the point: the test starts a second one behind this and
     * the two contend. A ready handshake would serialise them back into the case that
     * proves nothing.
     *
     * @param string $packageRoot The package tree holding the log.
     * @param string $tag         Carried as each record's reason, so the parent can
     *                            count this child's records apart from the other's.
     * @param int    $count       How many records to append.
     */
    public static function appending(string $packageRoot, string $tag, int $count): self
    {
        return self::open([
            \PHP_BINARY,
            \dirname(__DIR__) . '/Support/log_append.php',
            $packageRoot,
            $tag,
            (string) $count,
        ]);
    }

    /**
     * Wait for a child that runs to completion, and return its exit code.
     *
     * Anything on stderr is raised rather than swallowed: a child that could not append
     * says why there, and that is worth more to a failing test than a count that is
     * merely wrong.
     */
    public function wait(): int
    {
        if (!$this->running) {
            throw new RuntimeException('The child process has already been collected.');
        }

        $deadline = \microtime(true) + self::TIMEOUT;
        $error    = '';

        while (\microtime(true) < $deadline) {
            $status = \proc_get_status($this->process);
            $error .= (string) \fread($this->pipes[2], 1024);

            if (!$status['running']) {
                // Drained after the exit, not before it: a message written in the
                // child's last moments is the one a failing test most needs, and the
                // read above can easily have run just before it.
                $error .= (string) \stream_get_contents($this->pipes[2]);

                foreach ($this->pipes as $pipe) {
                    \fclose($pipe);
                }

                \proc_close($this->process);
                $this->running = false;

                if ($error !== '') {
                    throw new RuntimeException('The child process failed: ' . $error);
                }

                return $status['exitcode'];
            }

            \usleep(10000);
        }

        $this->kill();

        throw new RuntimeException('The child process did not finish within the timeout.');
    }

    /**
     * @param list<string> $command
     */
    private static function started(array $command, string $token): self
    {
        $run = self::open($command);
        $run->waitFor($token);

        return $run;
    }

    /**
     * @param list<string> $command
     */
    private static function open(array $command): self
    {
        $pipes   = [];
        $process = \proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!\is_resource($process)) {
            throw new RuntimeException('Could not start ' . $command[1] . '.');
        }

        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        return new self($process, [1 => $pipes[1], 2 => $pipes[2]]);
    }

    /**
     * Kill the run outright, the way a crash ends one, and reap it.
     *
     * SIGKILL rather than SIGTERM: the property under test is that the operating
     * system releases the lock however the holder dies, and a handled signal would
     * let the process release it on the way out instead.
     */
    public function kill(): void
    {
        if (!$this->running) {
            return;
        }

        \proc_terminate($this->process, \SIGKILL);

        // proc_close waits for the process, so the lock is released by the time
        // this returns and a test does not have to poll for it.
        foreach ($this->pipes as $pipe) {
            \fclose($pipe);
        }

        \proc_close($this->process);
        $this->running = false;
    }

    /**
     * Read the child's output until $token shows up in it.
     *
     * Anything on stderr is the child saying it could not do its job, and is worth
     * more to a failing test than the timeout that would otherwise follow it.
     */
    private function waitFor(string $token): void
    {
        $deadline = \microtime(true) + self::TIMEOUT;

        while (\microtime(true) < $deadline) {
            $this->output .= (string) \fread($this->pipes[1], 64);

            if (\str_contains($this->output, $token)) {
                return;
            }

            $error = (string) \fread($this->pipes[2], 1024);

            if ($error !== '') {
                $this->kill();

                throw new RuntimeException('The child process refused to start: ' . $error);
            }

            \usleep(10000);
        }

        $this->kill();

        throw new RuntimeException(
            \sprintf('The child process did not report "%s" within the timeout.', $token)
        );
    }
}
