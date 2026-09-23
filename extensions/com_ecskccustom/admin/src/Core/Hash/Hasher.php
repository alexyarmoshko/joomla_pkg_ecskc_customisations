<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash;

/**
 * Hashes payload bytes and files on disk, under the algorithm the rule declared.
 */
final class Hasher
{
    /**
     * The digest of bytes already in memory - a payload the build is checking
     * against its declared `sourceHash`, or content staged for a write.
     */
    public function hashBytes(string $algorithm, string $bytes): HashResult
    {
        if (!HashAlgorithm::isSupported($algorithm)) {
            return HashResult::unavailable(HashAlgorithm::unsupportedReason($algorithm));
        }

        return HashResult::digest(\hash($algorithm, $bytes));
    }

    /**
     * Whether a directory on the way to the path cannot be searched.
     *
     * The immediate parent is not enough to ask about. Searching a directory needs
     * the execute bit on every component above it too, and without it PHP cannot
     * establish that the parent is a directory at all - `is_dir()` on it answers
     * false, exactly as it does for a parent that is not there. So the walk climbs
     * to the nearest component PHP can still see and asks its question there. That
     * component is a directory, and if it cannot be searched, nothing below it can
     * be said to be absent. Review s1.v21, finding 1.
     */
    private function isBehindAnUnsearchableDirectory(string $path): bool
    {
        $directory = \dirname($path);

        while (!\is_dir($directory)) {
            $parent = \dirname($directory);

            if ($parent === $directory) {

                return false;
            }

            $directory = $parent;
        }

        return !\is_executable($directory);
    }

    /**
     * The digest of a file's current bytes.
     *
     * The checks below run in the order that gives the most specific reason: a
     * directory and an absent file both fail `is_file()`, and telling an operator
     * "the file is absent" when a directory sits at the path would send them
     * looking for the wrong fault.
     */
    public function hashFile(string $algorithm, string $path): HashResult
    {
        if (!HashAlgorithm::isSupported($algorithm)) {
            return HashResult::unavailable(HashAlgorithm::unsupportedReason($algorithm));
        }

        if (\str_contains($path, "\0")) {
            // Every path function below raises a ValueError on a NUL byte rather
            // than answering, and clearstatcache() gets there first. This class
            // answers with a reason or a digest and never throws, so the byte is
            // refused here rather than reaching them.
            return HashResult::unavailable('the path contains a NUL byte, so it names no file');
        }

        // Stale metadata would answer for a file that has since been written, and a
        // run reads a path it may have staged moments earlier.
        \clearstatcache(true, $path);

        if (\is_dir($path)) {
            return HashResult::unavailable('a directory is at this path, not a file');
        }

        if (!\file_exists($path)) {
            // file_exists() answers false for a file behind a directory the account
            // cannot search, so the file may be sitting there intact. Telling an
            // operator it is absent sends them to restore a file that was never gone
            // instead of to the permission that hid it.
            if ($this->isBehindAnUnsearchableDirectory($path)) {
                return HashResult::unavailable(
                    'a directory on the way to the file cannot be searched, so whether the file '
                    . 'is there cannot be told'
                );
            }

            return HashResult::absent('the file is absent');
        }

        if (!\is_file($path)) {
            return HashResult::unavailable('the path names something that is not a regular file');
        }

        if (!\is_readable($path)) {
            return HashResult::unavailable('the file is unreadable');
        }

        $digest = @\hash_file($algorithm, $path);

        if ($digest === false) {
            return HashResult::unavailable('the file could not be read');
        }

        return HashResult::digest($digest);
    }
}
