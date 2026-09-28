#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build gate: check the payload bytes inside a built package.
 *
 * tools/validate.php checks the authored tree before staging. This runs the same
 * BuildValidator on the rules and payloads the component archive inside the
 * package actually holds, so a byte changed by staging or packaging fails the
 * build: a payload whose hash is not its sourceHash, a payload a rule names that
 * is missing, and a payload no rule names.
 *
 * Errors print and the process exits 1. The Makefile then deletes the package.
 * Warnings are not repeated here; validate.php printed them before the build.
 *
 * Usage: php tools/verify_package.php PACKAGE.zip
 */

$root = \dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (!\is_file($autoload)) {
    \fwrite(\STDERR, "FAIL: vendor/autoload.php is missing - run composer install\n");
    exit(1);
}

require $autoload;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Build\BuildValidator;

const COMPONENT_ARCHIVE = 'packages/com_ecskccustom.zip';

/** The component archive folders the check reads, each extracted as it is. */
const CHECKED_FOLDERS = ['admin/rules/', 'admin/payload/'];

if ($argc !== 2) {
    \fwrite(\STDERR, "Usage: php tools/verify_package.php PACKAGE.zip\n");
    exit(1);
}

$package = $argv[1];

function fail(string $message): never
{
    \fwrite(\STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

function removeTree(string $path): void
{
    if (!\is_dir($path) || \is_link($path)) {
        @\unlink($path);

        return;
    }

    foreach (\scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            removeTree($path . '/' . $name);
        }
    }

    @\rmdir($path);
}

$outer = new ZipArchive();

if ($outer->open($package, ZipArchive::RDONLY) !== true) {
    fail(\sprintf('%s cannot be opened as a ZIP', $package));
}

$component = $outer->getFromName(COMPONENT_ARCHIVE);
$outer->close();

if ($component === false) {
    fail(\sprintf('%s holds no %s', $package, COMPONENT_ARCHIVE));
}

$work = \sys_get_temp_dir() . '/ecskc-verify-package-' . \bin2hex(\random_bytes(8));

if (!@\mkdir($work, 0o700)) {
    fail(\sprintf('cannot create the working directory %s', $work));
}

// exit() skips finally blocks, and every failure below exits, so clean up at shutdown.
\register_shutdown_function(static fn () => removeTree($work));

$inner = $work . '/com_ecskccustom.zip';

if (\file_put_contents($inner, $component) !== \strlen($component)) {
    fail(\sprintf('cannot write %s', $inner));
}

$archive = new ZipArchive();

if ($archive->open($inner, ZipArchive::RDONLY) !== true) {
    fail(\sprintf('%s in %s cannot be opened as a ZIP', COMPONENT_ARCHIVE, $package));
}

$entries = [];

for ($i = 0; $i < $archive->numFiles; $i++) {
    $name = (string) $archive->getNameIndex($i);

    foreach (CHECKED_FOLDERS as $folder) {
        if (\str_starts_with($name, $folder)) {
            // The packager writes plain relative names. Anything that could
            // climb out of the working directory is not an archive it wrote.
            if (\str_contains('/' . $name . '/', '/../')) {
                fail(\sprintf('%s in %s names %s, which climbs out of its folder', COMPONENT_ARCHIVE, $package, $name));
            }

            $entries[] = $name;
        }
    }
}

$extracted = $entries !== [] && $archive->extractTo($work . '/com', $entries);
$archive->close();

if (!$extracted) {
    fail(\sprintf('cannot extract the rules and payloads of %s in %s', COMPONENT_ARCHIVE, $package));
}

foreach (CHECKED_FOLDERS as $folder) {
    if (!\is_dir($work . '/com/' . $folder)) {
        fail(\sprintf('%s in %s holds no %s', COMPONENT_ARCHIVE, $package, $folder));
    }
}

$check = (new BuildValidator($root . '/extensions/com_ecskccustom/admin/schema/patch.xsd'))
    ->check($work . '/com/admin/rules', $work . '/com/admin/payload');

foreach ($check->errors as $error) {
    \fwrite(\STDERR, 'FAIL: ' . $package . ': ' . $error . "\n");
}

if (!$check->ok()) {
    exit(1);
}

echo $package . ": payload hashes verified\n";
exit(0);
