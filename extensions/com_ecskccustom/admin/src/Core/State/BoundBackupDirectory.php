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
     * @param Closure(): void|null $beforeCreate Called immediately before a missing
     *                                           segment is created. A test seam.
     *
     * @return self|string The bound directory, or why it could not be bound.
     */
    public static function open(
        PackagePaths $paths,
        string $targetPath,
        bool $create,
        ?Closure $beforeCreate = null,
    ): self|string {
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

                return \sprintf(
                    '%s is a symbolic link, and nothing under backup/ is written through one',
                    $logical
                );
            }

            if (!\is_dir($childPath)) {
                if (!$create) {
                    $opened->close();

                    return \sprintf('the backup directory %s could not be opened', $logical);
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
            return \sprintf(
                '%s is a symbolic link, and nothing under backup/ is written through one',
                $path
            );
        }

        if (!\is_dir($path)) {
            return \sprintf('the backup folder %s is not a directory', $path);
        }

        $named = @\lstat($path);

        if ($named === false) {
            return \sprintf('%s could not be opened', $path);
        }

        // The descriptor this object holds is the one its own opendir() adds. An
        // earlier handle on the same inode has its own number, and taking that one
        // would leave this object naming a path its owner can close and the process
        // can reuse for another file.
        $before = ProcDescriptor::pathsFor($named);
        $handle = @\opendir($path);

        if ($handle === false) {
            return \sprintf('%s could not be opened', $path);
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
}
