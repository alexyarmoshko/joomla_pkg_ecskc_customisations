<?php

declare(strict_types=1);

/**
 * Opens a FIFO for writing, says so, and keeps it open until it is killed.
 *
 * This is the rendezvous the run-state contention test needs. Opening a FIFO for
 * reading finishes only once someone opens the same FIFO for writing, so this
 * process learns the exact moment the reading process is inside the read, which no
 * amount of polling from outside can tell it. Nothing is ever written: the reader
 * then blocks waiting for bytes, holding the run lock, for as long as this process
 * lives.
 *
 * Not a test itself, and not collected as one: the suites scan tests/Unit and
 * tests/Integration.
 *
 * Usage: php hold_fifo.php <fifo path>
 */

$handle = @\fopen($argv[1] ?? '', 'w');

if ($handle === false) {
    \fwrite(\STDERR, 'the FIFO at ' . ($argv[1] ?? '') . ' could not be opened for writing');
    exit(2);
}

// The parent waits for this line before it contends for the lock.
\fwrite(\STDOUT, "opened\n");
\fflush(\STDOUT);

while (true) {
    \usleep(20000);
}
