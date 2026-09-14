<?php

declare(strict_types=1);

/**
 * A process that copies one backup, then exits.
 *
 * A backup interrupted part-way has to be a real interruption: planting a leftover
 * temporary and then running preserve() to completion only proves the next run
 * ignores that leftover. The tests start this under `ulimit -f`. One run lets
 * SIGXFSZ kill the copy while it is writing. Another ignores the signal so the
 * write fails with EFBIG and preserve() reports the incomplete copy.
 *
 * Not a test itself, and not collected as one: the suites scan tests/Unit and
 * tests/Integration.
 *
 * Usage: php preserve_backup.php <package root> <target> <version> <hash type> <digest> <source>
 */

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BackupStore;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

$root     = $argv[1] ?? '';
$target   = $argv[2] ?? '';
$version  = (int) ($argv[3] ?? 0);
$hashType = $argv[4] ?? '';
$digest   = $argv[5] ?? '';
$source   = $argv[6] ?? '';

$outcome = (new BackupStore(new PackagePaths($root)))->preserve(
    $target,
    $version,
    $hashType,
    $digest,
    $source
);

if (!$outcome->isPreserved()) {
    \fwrite(\STDERR, $outcome->reason);
    exit(2);
}

exit(0);
