#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/hash.php <source> [<source>...]\n");
    exit(1);
}

$failures = 0;

foreach (\array_slice($argv, 1) as $source) {
    $bytes = readSource($source);

    if ($bytes === null) {
        $failures++;
        continue;
    }

    echo "--- {$source}\n";
    echo '    sha256:        ' . \hash('sha256', $bytes) . "\n";
    echo '    bytes:         ' . \strlen($bytes) . "\n";
    echo '    line endings:  ' . lineEndings($bytes) . "\n";
    echo '    final newline: ' . (endsWithNewline($bytes) ? 'yes' : 'no') . "\n";
}

exit($failures > 0 ? 1 : 0);

/**
 * Read a source, walking '#'-separated zip members from left to right.
 *
 * Every segment but the last names a zip; the last names the file whose bytes
 * are returned. A single-segment source is an ordinary path.
 */
function readSource(string $source): ?string
{
    $segments = \explode('#', $source);
    $path     = \array_shift($segments);

    if (!\is_file($path)) {
        fwrite(STDERR, "FAIL: not a file: {$path}\n");

        return null;
    }

    $bytes = \file_get_contents($path);

    if ($bytes === false) {
        fwrite(STDERR, "FAIL: cannot read: {$path}\n");

        return null;
    }

    $walked = $path;

    foreach ($segments as $member) {
        $bytes = readZipMember($bytes, $member, $walked);

        if ($bytes === null) {
            return null;
        }

        $walked .= '#' . $member;
    }

    return $bytes;
}

/**
 * Extract one member from a zip held in memory.
 *
 * ZipArchive has no in-memory reader, so a nested archive is spilled to a
 * temporary file. The bytes are never rewritten on the way through.
 */
function readZipMember(string $archiveBytes, string $member, string $describedAs): ?string
{
    $temporary = \tempnam(\sys_get_temp_dir(), 'ecskchash');

    if ($temporary === false) {
        fwrite(STDERR, "FAIL: cannot create a temporary file for {$describedAs}\n");

        return null;
    }

    try {
        if (\file_put_contents($temporary, $archiveBytes) === false) {
            fwrite(STDERR, "FAIL: cannot stage {$describedAs}\n");

            return null;
        }

        $zip    = new \ZipArchive();
        $opened = $zip->open($temporary, \ZipArchive::RDONLY);

        if ($opened !== true) {
            fwrite(STDERR, "FAIL: not a readable zip: {$describedAs} (error {$opened})\n");

            return null;
        }

        $bytes = $zip->getFromName($member);
        $zip->close();

        if ($bytes === false) {
            fwrite(STDERR, "FAIL: no such member: {$describedAs}#{$member}\n");

            return null;
        }

        return $bytes;
    } finally {
        @\unlink($temporary);
    }
}

/**
 * Name the line-ending form, the way a payload inventory entry records it.
 *
 * A lone CR is legal in a text file and is not the same fault as mixed CRLF and
 * LF, so it is named separately rather than folded into 'mixed'.
 */
function lineEndings(string $bytes): string
{
    $crlf = \substr_count($bytes, "\r\n");
    $lf   = \substr_count($bytes, "\n") - $crlf;
    $cr   = \substr_count($bytes, "\r") - $crlf;

    $forms = [];

    if ($crlf > 0) {
        $forms[] = "CRLF ({$crlf})";
    }

    if ($lf > 0) {
        $forms[] = "LF ({$lf})";
    }

    if ($cr > 0) {
        $forms[] = "bare CR ({$cr})";
    }

    if ($forms === []) {
        return 'none';
    }

    return \count($forms) === 1 ? $forms[0] : 'MIXED: ' . \implode(', ', $forms);
}

function endsWithNewline(string $bytes): bool
{

    return $bytes !== ''
        && (\str_ends_with($bytes, "\n") || \str_ends_with($bytes, "\r"));
}
