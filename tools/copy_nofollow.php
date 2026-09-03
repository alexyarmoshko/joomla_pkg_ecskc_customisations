#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Copy one file into the staging tree without following a symbolic link.
 *
 * Usage: php tools/copy_nofollow.php <source> <destination>
 */

$root = \dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (!\is_file($autoload)) {
    \fwrite(\STDERR, "FAIL: vendor/autoload.php is missing - run composer install\n");
    exit(1);
}

require $autoload;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Build\LinkSafeCopy;

if ($argc !== 3) {
    \fwrite(\STDERR, "Usage: php tools/copy_nofollow.php <source> <destination>\n");
    exit(1);
}

try {
    LinkSafeCopy::copy($argv[1], $argv[2]);
} catch (RuntimeException $e) {
    \fwrite(\STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
