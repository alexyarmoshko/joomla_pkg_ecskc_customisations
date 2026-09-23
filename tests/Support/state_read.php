<?php

declare(strict_types=1);

/**
 * A run-state read that never finishes, for the test that contends with one.
 *
 * The reader takes the run lock and keeps it while it reads the run marker and the
 * write intent, so that the lock and the records answer for one point in time. That
 * is only observable from another process, and only while the read is still going.
 * So the tree this is pointed at has `run.marker` as a FIFO, and the read stops
 * there, holding the lock, until the parent kills this process.
 */

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunStateReader;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

$reader = new RunStateReader(new PackagePaths($argv[1] ?? ''));

\fwrite(\STDOUT, "reading\n");
\fflush(\STDOUT);

$reader->read();

// If the parent lets the read finish, stay alive holding nothing, so that a test
// asking whether the lock is free is answered by the read having ended rather than
// by this process having exited.
while (true) {
    \usleep(20000);
}
