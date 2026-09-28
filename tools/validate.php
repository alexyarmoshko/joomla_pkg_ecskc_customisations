#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build gate: validate the authored rule set, payload tree and extension registry.
 *
 * Warnings print and the process exits 0, so the artefact is still produced.
 * Errors print and the process exits 1, and the Makefile removes any previous
 * artefact before calling this, so a failed check leaves no zip behind.
 *
 * Usage: php tools/validate.php [--rules DIR] [--payload DIR] [--registry FILE]
 *
 * Defaults are data/rules, data/payload and data/registry.xml under the repository root.
 */

$root = \dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (!\is_file($autoload)) {
    \fwrite(\STDERR, "FAIL: vendor/autoload.php is missing - run composer install\n");
    exit(1);
}

require $autoload;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Build\BuildValidator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\SchemaValidator;

$rules    = $root . '/data/rules';
$payload  = $root . '/data/payload';
$registry = $root . '/data/registry.xml';

for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--rules' && isset($argv[$i + 1])) {
        $rules = $argv[++$i];
        continue;
    }

    if ($argv[$i] === '--payload' && isset($argv[$i + 1])) {
        $payload = $argv[++$i];
        continue;
    }

    if ($argv[$i] === '--registry' && isset($argv[$i + 1])) {
        $registry = $argv[++$i];
        continue;
    }

    \fwrite(\STDERR, "Usage: php tools/validate.php [--rules DIR] [--payload DIR] [--registry FILE]\n");
    exit(1);
}

$check = (new BuildValidator($root . '/extensions/com_ecskccustom/admin/schema/patch.xsd'))
    ->check($rules, $payload);

// Keeps a registry the Plugins screen would refuse out of the package.
if (!\is_file($registry)) {
    $registryErrors = [$registry . ': the registry file does not exist'];
} elseif (($registryBytes = \file_get_contents($registry)) === false) {
    $registryErrors = [$registry . ': the registry file could not be read'];
} else {
    $registryErrors = (new SchemaValidator($root . '/extensions/com_ecskccustom/admin/schema/registry.xsd'))
        ->validateString($registryBytes, $registry);
}

$errors = \array_merge($check->errors, $registryErrors);

foreach ($check->warnings as $warning) {
    \fwrite(\STDERR, 'WARNING: ' . $warning . "\n");
}

foreach ($errors as $error) {
    \fwrite(\STDERR, 'FAIL: ' . $error . "\n");
}

if ($errors !== []) {
    \fwrite(\STDERR, 'build: ' . \count($errors) . " error(s); no artefact\n");
    exit(1);
}

if ($check->warnings !== []) {
    \fwrite(\STDERR, 'build: ' . \count($check->warnings) . " warning(s), artefact permitted\n");
    exit(0);
}

echo "build: ok\n";
exit(0);
