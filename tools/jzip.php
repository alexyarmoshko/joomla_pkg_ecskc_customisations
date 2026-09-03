<?php
/**
 * jzip.php - a deterministic ZIP writer for Joomla extension packages.
 *
 * Reusable across Joomla projects: no extensions, no `zip` binary, no state. It emits every field of
 * the archive itself, so the bytes depend on exactly three things - the file contents, their names,
 * and the timestamp given - and on nothing about the machine. The same inputs produce the same
 * archive on Windows and on Linux, byte for byte, which is what makes a published <sha256> something
 * anyone can re-derive.
 *
 * The alternatives do not have that property: `zip -X` writes a host-OS byte and host-shaped
 * permission bits - NTFS 0666 plus the archive bit on Windows, Unix 0644 on Linux - so the same
 * inputs give different archives on different machines.
 *
 * Entries are STORED by default and DEFLATED on request; see $level on jzipWrite(). Storing is
 * reproducible by construction, because every byte written is chosen here. Deflating is reproducible
 * in practice but not by construction: it hands the bytes to zlib. Measured identical output from
 * zlib 1.2.12 and 1.3 at every level, so ordinary version drift is not the risk - a different
 * implementation is (zlib-ng, which some distributions ship as the zlib provider, compresses
 * differently while staying a valid deflate stream). Store when a third party must be able to
 * re-derive the hash; deflate when size matters more, which for this repo's own packages is 2.5x.
 *
 * Deflating also needs ext-zlib. Storing needs no extension at all.
 *
 * Timestamps are always UTC. ZIP dates are MS-DOS local time with no zone, so reading them in the
 * builder's timezone would put the builder's location into the hash.
 *
 * The caller passes an explicit file list. This tool never walks a directory - what ships is the
 * caller's decision, and a packager that discovers its own input is how a stray file gets published.
 *
 * Dev-only: never shipped in an extension package.
 *
 * Usage: php jzip.php [--level=0-9] <archive> <mtime-epoch> <root> <file> [<file>...]
 */

declare(strict_types=1);

/** Thrown for anything that would produce an archive this writer cannot honestly claim to have made. */
class JzipError extends RuntimeException
{
}

/**
 * MS-DOS date and time, in UTC.
 *
 * @return array{0: int, 1: int} [time, date] as the two 16-bit fields ZIP stores, in that order.
 */
function jzipDosTime(int $timestamp): array
{
    $year = (int) gmdate('Y', $timestamp);

    // The DOS epoch is 1980 and the year field is 7 bits. Outside that range the value silently
    // wraps into a different date, so refuse instead.
    if ($year < 1980 || $year > 2107) {
        throw new JzipError("timestamp {$timestamp} is outside the MS-DOS range (1980-2107)");
    }

    $date = ($year - 1980) << 9 | (int) gmdate('n', $timestamp) << 5 | (int) gmdate('j', $timestamp);

    // Seconds are stored halved: DOS time has two-second resolution.
    $time = (int) gmdate('G', $timestamp) << 11
        | (int) gmdate('i', $timestamp) << 5
        | (int) gmdate('s', $timestamp) >> 1;

    return [$time, $date];
}

/**
 * The general-purpose bit flag for one entry name.
 *
 * Bit 11 declares the name to be UTF-8. It is set only when the name actually needs it: a pure-ASCII
 * name is valid as both CP437 and UTF-8, so leaving the flag clear there is understood by every
 * extractor ever written, while setting it gains nothing.
 */
function jzipNameFlag(string $name): int
{
    if (preg_match('/[\x80-\xFF]/', $name) !== 1) {
        return 0;
    }

    // A name with high bytes that is not valid UTF-8 has come from some other encoding, and there is
    // no flag for "unknown". Naming it here beats writing an archive whose names cannot be read back.
    if (preg_match('//u', $name) !== 1) {
        throw new JzipError("entry name is neither ASCII nor valid UTF-8: {$name}");
    }

    return 0x0800;
}

/**
 * Reject anything the simple format cannot represent, rather than emitting a broken archive.
 */
function jzipCheckEntry(string $name, int $size): void
{
    if ($name === '' || strlen($name) > 0xFFFF) {
        throw new JzipError("entry name is empty or longer than 65535 bytes: {$name}");
    }

    // Zip64 is not implemented. Both the per-entry sizes and the offsets below are 32-bit.
    if ($size > 0xFFFFFFFF) {
        throw new JzipError("{$name} is larger than 4 GiB; this writer does not implement Zip64");
    }

    if (str_contains($name, '\\')) {
        throw new JzipError("entry name must use forward slashes: {$name}");
    }

    if (str_starts_with($name, '/') || str_contains($name, '../')) {
        throw new JzipError("entry name must be relative and must not traverse: {$name}");
    }
}

/**
 * Compress one entry, or hand it back untouched.
 *
 * Every entry in an archive uses the same method. Falling back to STORE per entry when deflating
 * failed to help is what general-purpose zippers do, but it would make the method depend on the
 * content, and a reader of the archive could no longer tell at a glance what was asked for.
 *
 * @return array{0: string, 1: int} [bytes to write, ZIP compression method]
 */
function jzipCompress(string $data, int $level): array
{
    if ($level === 0) {
        return [$data, 0];
    }

    if (!function_exists('gzdeflate')) {
        throw new JzipError('deflate needs ext-zlib; use level 0 to store instead');
    }

    $deflated = gzdeflate($data, $level);

    if ($deflated === false) {
        throw new JzipError("gzdeflate failed at level {$level}");
    }

    return [$deflated, 8];
}

/**
 * Write $files (relative to $root, in the order given) to $archive, all stamped $mtime.
 *
 * @param  list<string>  $files
 * @param  int           $level  0 stores; 1-9 deflates. See the file header for which to pick.
 *
 * @return int Bytes written.
 */
function jzipWrite(string $archive, array $files, int $mtime, string $root, int $level = 0): int
{
    if ($files === []) {
        throw new JzipError('refusing to write an archive with no entries');
    }

    if ($level < 0 || $level > 9) {
        throw new JzipError("compression level must be 0-9, got {$level}");
    }

    // The entry count is a 16-bit field in the end-of-central-directory record.
    if (count($files) > 0xFFFF) {
        throw new JzipError('more than 65535 entries; this writer does not implement Zip64');
    }

    [$time, $date] = jzipDosTime($mtime);

    $local   = '';
    $central = '';

    foreach ($files as $name) {
        $path = $root . '/' . $name;

        if (!is_file($path)) {
            throw new JzipError("not a file: {$path}");
        }

        $data = file_get_contents($path);

        if ($data === false) {
            throw new JzipError("cannot read: {$path}");
        }

        $size = strlen($data);
        jzipCheckEntry($name, $size);

        [$body, $method] = jzipCompress($data, $level);
        $packed          = strlen($body);

        // The CRC is always of the ORIGINAL bytes, never of the compressed ones.
        $flag    = jzipNameFlag($name);
        $crc     = crc32($data);
        $offset  = strlen($local);
        $needs   = $method === 0 ? 10 : 20;

        if ($packed > 0xFFFFFFFF) {
            throw new JzipError("{$name} exceeds 4 GiB compressed; this writer does not implement Zip64");
        }

        $local .= pack('VvvvvvVVVvv', 0x04034b50, $needs, $flag, $method, $time, $date, $crc, $packed, $size, strlen($name), 0)
            . $name . $body;

        // "Version made by" 0x031e is Unix / 3.0, and the external attributes carry 0100644. Declaring
        // one host on every platform is the point: it is what stops the archive describing its builder.
        $central .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            0x031e,
            $needs,
            $flag,
            $method,
            $time,
            $date,
            $crc,
            $packed,
            $size,
            strlen($name),
            0,
            0,
            0,
            0,
            0100644 << 16,
            $offset
        ) . $name;
    }

    if (strlen($local) > 0xFFFFFFFF) {
        throw new JzipError('archive exceeds 4 GiB; this writer does not implement Zip64');
    }

    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($local), 0);

    $written = file_put_contents($archive, $local . $central . $end);

    // A failed write LEAVES WHAT IT MANAGED TO WRITE. That is the part worth handling: the bytes on
    // disk are then a valid-looking prefix of an archive, missing its tail, and a caller that hashes
    // whatever is at this path publishes a checksum certifying the corruption - which passes the
    // downloader's integrity check and fails at install time. Removing it makes the failure look like
    // what it is, an archive that was never written.
    //
    // There is deliberately no separate short-write branch: file_put_contents() does not return a
    // short count. It raises "Only %d of %d bytes written" and then returns FALSE (php-src converts
    // the count to -1), so a disk that fills mid-write arrives here as false like any other failure.
    // Verified against a stream wrapper that accepts 16 of 100 bytes; the test below pins it, because
    // a reader who assumes the C-library convention would otherwise add an unreachable branch.
    if ($written === false) {
        @unlink($archive);

        throw new JzipError("cannot write (any partial archive has been removed): {$archive}");
    }

    return $written;
}

// CLI entry point. Guarded so the functions above can be required by a test suite.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $args  = array_slice($argv, 1);
    $level = 0;

    if (isset($args[0]) && str_starts_with($args[0], '--level=')) {
        $level = (int) substr(array_shift($args), 8);
    }

    if (count($args) < 4) {
        fwrite(STDERR, "usage: php jzip.php [--level=0-9] <archive> <mtime-epoch> <root> <file> [<file>...]\n");
        exit(2);
    }

    [$archive, $mtime, $root] = $args;
    $entries                  = array_slice($args, 3);

    try {
        $bytes = jzipWrite($archive, $entries, (int) $mtime, $root, $level);
    } catch (JzipError $e) {
        fwrite(STDERR, 'jzip: ' . $e->getMessage() . "\n");
        exit(1);
    }

    printf(
        "%s: %d entries, %d bytes (%s), sha256 %s\n",
        $archive,
        count($entries),
        $bytes,
        $level === 0 ? 'stored' : "deflate level {$level}",
        hash_file('sha256', $archive)
    );
}
