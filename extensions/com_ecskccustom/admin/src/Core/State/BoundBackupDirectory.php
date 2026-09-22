<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\State;

use Closure;
use Throwable;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\ProcDescriptor;

/**
 * The parent directory under `backup/`, opened from `backup/` down.
 *
 * Each level is opened through the handle of the one above it. A missing
 * segment is created the same way when the caller asked for that. PHP has no
 * `openat` or `O_NOFOLLOW`, so a pathname `mkdir` after an `is_dir` check can
 * follow a symbolic link swapped into an ancestor and land outside `backup/`.
 * A create through `/proc/self/fd/N` cannot: it names the inode already bound.
 *
 * A symbolic link at a name is seen with `lstat` of that last component, and a
 * follow between that check and the open cannot match the inode that was
 * verified.
 *
 * Freezer and BackupStore both walk this tree. One routine keeps the bind and
 * the create on the same descriptors.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class BoundBackupDirectory
{
    /**
     * @param resource $handle
     */
    private function __construct(
        private $handle,
        public readonly string $proc,
    ) {
    }

    public function close(): void
    {
        \closedir($this->handle);
    }

    /**
     * `$name` resolved against this opened directory, not against a pathname.
     */
    public function child(string $name): string
    {
        return $this->proc . '/' . $name;
    }

    /**
     * Open the target's parent from `backup/` down.
     *
     * `$missing` separates the one failure that is an answer rather than a fault:
     * a target nothing has ever displaced has no mirrored directory, and a reader
     * asked what is under it is entitled to say "nothing". Every other failure -
     * `backup/` itself gone, a symbolic link at a segment, a file where a directory
     * should be, a directory that cannot be listed or bound - means the caller could
     * not see what is there, which is not the same as seeing that there is nothing.
     * A caller that only writes has no use for the distinction and passes nothing.
     *
     * @param Closure(): void|null $beforeCreate Called immediately before a missing
     *                                           segment is created. A test seam.
     * @param bool|null            $missing      Set to true when the failure is only
     *                                           that the directory is not there.
     * @param string|null          $destination  The backup file a create would
     *                                           publish. The create path classifies
     *                                           it first, with the same rules the
     *                                           dry run uses.
     *
     * @return self|string The bound directory, or why it could not be bound.
     */
    public static function open(
        PackagePaths $paths,
        string $targetPath,
        bool $create,
        ?Closure $beforeCreate = null,
        ?bool &$missing = null,
        ?string $destination = null,
    ): self|string {
        $missing = false;

        if ($create) {
            $parent      = \dirname($targetPath);
            $directory   = $paths->backup() . ($parent === '.' ? '' : '/' . $parent);
            $destination ??= $directory . '/held';
            $refusal     = self::publishRefusal($paths, $destination);

            if ($refusal !== null) {
                return $refusal;
            }
        }

        $logical = $paths->backup();
        $opened  = self::openReal($logical);

        if (\is_string($opened)) {
            return $opened;
        }

        $parent = \dirname($targetPath);

        if ($parent === '.') {
            return $opened;
        }

        foreach (\explode('/', $parent) as $segment) {
            $logical  .= '/' . $segment;
            $childPath = $opened->child($segment);
            \clearstatcache(true, $childPath);

            if (\is_link($childPath)) {
                $opened->close();

                return self::linkRefusal($logical);
            }

            if (!\is_dir($childPath)) {
                if (!$create) {
                    // Asked through the bound parent, so a name that is not there is
                    // not there: an ancestor that could have been swapped was refused
                    // above, and this last component was seen not to be a link.
                    $missing = !\file_exists($childPath);

                    $opened->close();

                    return \sprintf('the backup directory %s could not be opened', $logical);
                }

                if (\file_exists($childPath)) {
                    $opened->close();

                    return self::fileRefusal($logical, $destination ?? $logical);
                }

                if ($beforeCreate !== null) {
                    try {
                        $beforeCreate();
                    } catch (Throwable $thrown) {
                        $opened->close();

                        throw $thrown;
                    }
                }

                if (!@\mkdir($childPath, 0o755) && !\is_dir($childPath)) {
                    $opened->close();

                    return \sprintf('the backup directory %s could not be created', $logical);
                }
            }

            $child = self::openReal($childPath);
            $opened->close();

            if (\is_string($child)) {
                return $child;
            }

            $opened = $child;
        }

        return $opened;
    }

    /**
     * @return self|string
     */
    private static function openReal(string $path): self|string
    {
        \clearstatcache(true, $path);

        if (\is_link($path)) {
            return self::linkRefusal($path);
        }

        if (!\is_dir($path)) {
            return \sprintf('the backup folder %s is not a directory', $path);
        }

        $named = @\lstat($path);

        if ($named === false) {
            return self::openRefusal($path);
        }

        // The descriptor this object holds is the one its own opendir() adds. An
        // earlier handle on the same inode has its own number, and taking that one
        // would leave this object naming a path its owner can close and the process
        // can reuse for another file.
        $before = ProcDescriptor::pathsFor($named);
        $handle = @\opendir($path);

        if ($handle === false) {
            return self::openRefusal($path);
        }

        $proc = ProcDescriptor::pathOpenedSince($named, $before);

        if ($proc === null) {
            \closedir($handle);

            return \sprintf(
                '%s could not be bound, so nothing under backup/ is written through a path that '
                . 'might follow a later symbolic link',
                $path
            );
        }

        return new self($handle, $proc);
    }

    /**
     * Why a backup cannot be published at `$destination`, without creating anything.
     *
     * This is the classification `open()` applies before it binds a descriptor or
     * creates a directory. The dry run asks it directly. A symbolic link, a file
     * where a directory has to be, and a directory that cannot be opened, searched,
     * or written are the same refusals on both paths.
     */
    public static function publishRefusal(PackagePaths $paths, string $destination): ?string
    {
        $backup    = $paths->backup();
        $directory = \dirname($destination);
        $existing  = null;

        foreach (self::components($backup, $directory) as $cursor) {
            \clearstatcache(true, $cursor);

            if (\is_link($cursor)) {
                return self::linkRefusal($cursor);
            }

            if (\is_dir($cursor)) {
                if (!\is_readable($cursor)) {
                    return self::openRefusal($cursor);
                }

                if (!\is_executable($cursor)) {
                    return self::searchRefusal($cursor, $destination);
                }

                $existing = $cursor;

                continue;
            }

            if (\file_exists($cursor)) {
                return self::fileRefusal($cursor, $destination);
            }

            break;
        }

        if ($existing === null || !\is_writable($existing)) {
            return self::writeRefusal($existing ?? $backup, $destination);
        }

        \clearstatcache(true, $destination);

        if (\is_dir($destination) && !\is_link($destination)) {
            return \sprintf('a directory is at the backup name %s', $destination);
        }

        return null;
    }

    /**
     * From `$backup` through `$directory`, when `$directory` is under `$backup`.
     *
     * @return list<string>
     */
    private static function components(string $backup, string $directory): array
    {
        $components = [$backup];

        if ($directory === $backup || !\str_starts_with($directory, $backup . '/')) {
            return $components;
        }

        $cursor = $backup;

        foreach (\explode('/', \substr($directory, \strlen($backup) + 1)) as $segment) {
            $cursor .= '/' . $segment;
            $components[] = $cursor;
        }

        return $components;
    }

    private static function linkRefusal(string $path): string
    {
        return \sprintf(
            '%s is a symbolic link, and nothing under backup/ is written through one',
            $path
        );
    }

    private static function fileRefusal(string $path, string $destination): string
    {
        return \sprintf(
            '%s is not a directory, so the backup folder for %s could not be made',
            $path,
            $destination
        );
    }

    private static function openRefusal(string $path): string
    {
        return \sprintf('%s could not be opened', $path);
    }

    private static function searchRefusal(string $path, string $destination): string
    {
        return \sprintf(
            '%s is not a searchable directory, so the backup at %s could not be published',
            $path,
            $destination
        );
    }

    private static function writeRefusal(string $path, string $destination): string
    {
        return \sprintf(
            '%s is not a writable directory, so the backup at %s could not be published',
            $path,
            $destination
        );
    }
}
