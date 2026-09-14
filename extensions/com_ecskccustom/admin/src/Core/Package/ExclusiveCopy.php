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
 * Copy one regular file onto a path this call creates exclusively.
 *
 * BackupStore and TargetWriter both need the same ownership rule. A link or
 * non-file is refused before it is opened: a FIFO would block. The source is
 * opened as `rb`, the destination as `xb`, so the copy cannot land in a file or
 * through a link somebody else put at that name. Bytes are streamed, flushed and
 * closed. An incomplete copy unlinks the destination this call created. A source
 * that cannot be opened, or a name that cannot be created exclusively, is left
 * alone, because this call never created it.
 *
 * Callers own the failure text. This class returns a kind, and reports a leftover
 * destination only when the exclusive create succeeded and the incomplete-copy
 * unlink did not.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class ExclusiveCopy
{
    public const NOT_A_FILE  = 'not-a-file';
    public const UNREADABLE  = 'unreadable';
    public const UNCREATABLE = 'uncreatable';
    public const INCOMPLETE  = 'incomplete';

    /**
     * Copy $source onto $destination, which this call creates exclusively.
     *
     * @param string      $source      The regular file to read.
     * @param string      $destination The exclusive path this call creates.
     * @param string|null $leftover    Set to $destination when an incomplete copy
     *                                 could not be removed.
     *
     * @return string|null A failure kind, or null when the copy completed.
     */
    public static function copy(string $source, string $destination, ?string &$leftover = null): ?string
    {
        $leftover = null;
        \clearstatcache(true, $source);

        if (\is_link($source) || !\is_file($source)) {
            return self::NOT_A_FILE;
        }

        $in = @\fopen($source, 'rb');

        if ($in === false) {
            return self::UNREADABLE;
        }

        $out = @\fopen($destination, 'xb');

        if ($out === false) {
            @\fclose($in);

            return self::UNCREATABLE;
        }

        $copied  = @\stream_copy_to_stream($in, $out);
        $flushed = @\fflush($out);
        $closed  = @\fclose($out);
        @\fclose($in);

        if ($copied === false || !$flushed || !$closed) {
            if (self::discard($destination) !== null) {
                $leftover = $destination;
            }

            return self::INCOMPLETE;
        }

        return null;
    }

    /**
     * Remove a path this call created.
     *
     * @return string|null Why the path is still there, or null when it is gone.
     */
    public static function discard(string $path): ?string
    {
        \clearstatcache(true, $path);

        if (!\is_file($path) && !\is_link($path)) {
            return null;
        }

        if (@\unlink($path)) {
            return null;
        }

        \clearstatcache(true, $path);

        if (!\is_file($path) && !\is_link($path)) {
            return null;
        }

        return \sprintf('%s could not be removed', $path);
    }
}
