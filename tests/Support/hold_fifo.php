<?php

declare(strict_types=1);

/**
 * Opens a FIFO for writing, says so, and keeps it open until it is killed.
 */

$handle = @\fopen($argv[1] ?? '', 'w');

if ($handle === false) {
    \fwrite(\STDERR, 'the FIFO at ' . ($argv[1] ?? '') . ' could not be opened for writing');
    exit(2);
}

\fwrite(\STDOUT, "opened\n");
\fflush(\STDOUT);

while (true) {
    \usleep(20000);
}
