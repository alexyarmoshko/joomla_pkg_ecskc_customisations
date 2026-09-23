<?php

declare(strict_types=1);

/**
 * A process that copies one backup, then exits.
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
