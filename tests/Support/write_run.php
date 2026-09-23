<?php

declare(strict_types=1);

/**
 * A write run that takes the lock and then waits to be killed.
 */

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunLock;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunMarker;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntent;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntentStore;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = $argv[1] ?? '';
$mode = $argv[2] ?? 'marker';
$id   = $argv[3] ?? null;

$paths = new PackagePaths($root);
$lock  = new RunLock($paths);

if (!$lock->acquire()) {
    \fwrite(\STDERR, (string) $lock->refusal());
    exit(2);
}

if ($mode !== 'no-marker' && !(new RunMarker($paths))->publish()) {
    \fwrite(\STDERR, 'the run marker could not be published');
    exit(3);
}

if ($mode === 'marker+intent') {
    $intent = $id === null
        ? WriteIntent::begin(['event' => 'write'])
        : WriteIntent::of($id, ['event' => 'write']);

    if (!(new WriteIntentStore($paths))->publish($intent)) {
        \fwrite(\STDERR, 'the write intent could not be published');
        exit(4);
    }
}

\fwrite(\STDOUT, "held\n");
\fflush(\STDOUT);

while (true) {
    \usleep(20000);
}
