<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package;

/**
 * `/proc/self/fd/N` paths for an inode this process already holds.
 *
 * PHP has no `openat`, `O_NOFOLLOW` or `fchmod`. On Linux, a `/proc/self/fd/N`
 * path names the opened inode even if the original path was replaced. EventLog
 * chmods the log that way. BoundBackupDirectory writes freeze markers and
 * mirrored directories the same way, so a later symbolic link in an ancestor
 * cannot redirect the mutation.
 *
 * The lookup is the scan and the device-and-inode match. Each caller decides
 * what to do with a matching descriptor. A caller that opens a handle and keeps
 * it takes the descriptor that open added, not the first one that matches.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class ProcDescriptor
{
    /**
     * Every `/proc/self/fd/N` whose inode is the one `$named` describes.
     *
     * More than one descriptor can name the same inode. EventLog tries each
     * until `chmod` succeeds. A freeze or backup walk compares two scans to find
     * the one its own open added.
     *
     * @param array<string, mixed> $named `lstat()` or `fstat()` of the inode.
     *
     * @return list<string>
     */
    public static function pathsFor(array $named): array
    {
        $entries = @\scandir('/proc/self/fd');

        if ($entries === false) {
            return [];
        }

        $found = [];

        foreach ($entries as $entry) {
            if (!\ctype_digit($entry)) {
                continue;
            }

            $candidate = '/proc/self/fd/' . $entry;
            $got       = @\stat($candidate);

            if ($got === false
                || $got['dev'] !== $named['dev']
                || $got['ino'] !== $named['ino']
            ) {
                continue;
            }

            $found[] = $candidate;
        }

        return $found;
    }

    /**
     * The one `/proc/self/fd/N` an open between two scans added for that inode.
     *
     * A descriptor that matched before the open belongs to an earlier handle.
     * Closing that handle invalidates its path, and the number can then be
     * reused for an unrelated file, so a caller that keeps its own handle open
     * must hold the descriptor that handle created. PHP runs one thread, so
     * exactly one descriptor appears between the scans when the open succeeded
     * on the named inode. Anything else answers null.
     *
     * @param array<string, mixed> $named  `lstat()` of the inode before the open.
     * @param list<string>         $before `pathsFor($named)` taken before the open.
     */
    public static function pathOpenedSince(array $named, array $before): ?string
    {
        $added = \array_values(\array_diff(self::pathsFor($named), $before));

        return \count($added) === 1 ? $added[0] : null;
    }
}
