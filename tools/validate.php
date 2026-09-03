#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build gate: validate the authored rule set and payload tree.
 *
 * Warnings print and the process exits 0, so the artefact is still produced.
 * Errors print and the process exits 1, and the Makefile removes any previous
 * artefact before calling this, so a failed check leaves no zip behind.
 *
 * Usage: php tools/validate.php [--rules DIR] [--payload DIR]
 *
 * Defaults are data/rules and data/payload under the repository root.
 */

$root = \dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (!\is_file($autoload)) {
    \fwrite(\STDERR, "FAIL: vendor/autoload.php is missing - run composer install\n");
    exit(1);
}

require $autoload;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Build\BuildValidator;

$rules   = $root . '/data/rules';
$payload = $root . '/data/payload';

for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--rules' && isset($argv[$i + 1])) {
        $rules = $argv[++$i];
        continue;
    }

    if ($argv[$i] === '--payload' && isset($argv[$i + 1])) {
        $payload = $argv[++$i];
        continue;
    }

    \fwrite(\STDERR, "Usage: php tools/validate.php [--rules DIR] [--payload DIR]\n");
    exit(1);
}

$check = (new BuildValidator($root . '/extensions/com_ecskccustom/admin/schema/patch.xsd'))
    ->check($rules, $payload);

foreach ($check->warnings as $warning) {
    \fwrite(\STDERR, 'WARNING: ' . $warning . "\n");
}

foreach ($check->errors as $error) {
    \fwrite(\STDERR, 'FAIL: ' . $error . "\n");
}

if (!$check->ok()) {
    \fwrite(\STDERR, 'build: ' . \count($check->errors) . " error(s); no artefact\n");
    exit(1);
}

if ($check->warnings !== []) {
    \fwrite(\STDERR, 'build: ' . \count($check->warnings) . " warning(s), artefact permitted\n");
    exit(0);
}

echo "build: ok\n";
exit(0);
