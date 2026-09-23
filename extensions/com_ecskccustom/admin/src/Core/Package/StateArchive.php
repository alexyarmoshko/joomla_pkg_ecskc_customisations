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

use Closure;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunLock;
use ZipArchive;

/**
 * Copies the package's own history out of the tree before an uninstall deletes it.
 */
final class StateArchive
{
    /** Names the archive for a person reading a directory listing of temporary files. */
    public const PREFIX = 'com_ecskccustom-state-';

    public function __construct(private readonly PackagePaths $paths)
    {
    }

    /**
     * The archive's name.
     *
     * The timestamp is UTC and the token is random. The timestamp alone would collide
     * on two uninstalls in the same second, and, more to the point, would let anyone
     * guess the name: the ordinary temporary folder is inside the web root and is not
     * a protected folder of the package's own, so an archive of displaced site files
     * sits there under a name that must not be predictable.
     *
     * @param int    $time  Unix timestamp.
     * @param string $token Random hex.
     */
    public static function name(int $time, string $token): string
    {
        return self::PREFIX . \gmdate('Ymd-His', $time) . 'Z-' . $token . '.zip';
    }

    /**
     * What would go into the archive: entry path inside the zip => absolute source.
     *
     * Symbolic links are skipped rather than followed, at any depth. A link in this
     * tree can only have been put there by hand, and following one would copy a file
     * from outside the package into an archive that claims to hold package state.
     *
     * @return array<string, string>
     */
    public function contents(): array
    {
        $entries = [];

        foreach ([$this->paths->backup(), $this->paths->state()] as $directory) {
            $this->collect($directory, \basename($directory), true, $entries);
        }

        \ksort($entries);

        return $entries;
    }

    /**
     * Take the run lock, then save the history, or return null when there is none.
     *
     * The one archive operation. The lock comes first so that what the walk sees is
     * a tree no run is moving files through; the destination is asked for only once
     * that walk has found something to save; and the lock is released before this
     * returns, on every path out.
     *
     * @param Closure(): string $destination Where to write, resolved under the lock.
     *                                       Creating the folder belongs in here, so
     *                                       nothing is created for an archive that
     *                                       is never written.
     * @param int|null          $time        Timestamp for the name; now when null.
     *
     * @return string|null The archive's absolute path, or null when there was no
     *                     history to save.
     *
     * @throws StateArchiveFailure When the run lock is held, the lock file cannot be
     *                             opened, or the archive cannot be written.
     */
    public function archive(Closure $destination, ?int $time = null): ?string
    {
        $lock = new RunLock($this->paths);

        if (!$lock->acquire()) {
            throw new StateArchiveFailure(
                match ($lock->refusal()) {
                    RunLock::IN_PROGRESS => StateArchiveFailure::RUN_LOCKED,
                    RunLock::READING     => StateArchiveFailure::REPORT_READING,
                    default              => StateArchiveFailure::LOCK_UNAVAILABLE,
                },
                $lock->path(),
                \sprintf('%s: %s', $lock->path(), (string) $lock->refusal())
            );
        }

        try {
            $entries = $this->contents();

            if ($entries === []) {
                return null;
            }

            return $this->write($destination(), $entries, $time);
        } finally {
            $lock->release();
        }
    }

    /**
     * Write the archive into $directory.
     *
     * @param string   $directory Where to write. Outside the component tree, or the
     *                            uninstall deletes the archive along with the rest.
     *                            A destination inside that tree is refused, including
     *                            through a ".." alias. A symbolic link is refused
     *                            rather than followed: it would put the archive
     *                            somewhere nobody configured.
     * @param int|null $time      Timestamp for the name; the current time when null.
     *
     * @param array<string, string> $entries What archive() already walked, so the
     *                                        tree is read once, under the lock.
     *
     * @return string The archive's absolute path.
     *
     * @throws StateArchiveFailure When the archive cannot be written.
     */
    private function write(string $directory, array $entries, ?int $time = null): string
    {
        if (\is_link($directory) || !\is_dir($directory) || !\is_writable($directory)) {
            throw new StateArchiveFailure(
                StateArchiveFailure::UNWRITABLE,
                $directory,
                \sprintf('%s is a link, is not a directory, or is not writable', $directory)
            );
        }

        if ($this->paths->contains($directory)) {
            throw new StateArchiveFailure(
                StateArchiveFailure::UNWRITABLE,
                $directory,
                \sprintf('%s is inside the component tree', $directory)
            );
        }

        $name = self::name($time ?? \time(), \bin2hex(\random_bytes(6)));

        // ZipArchive creates files with the process umask. A private staging
        // directory prevents access before the archive's mode is restricted.
        $staging = $directory . '/.' . \bin2hex(\random_bytes(6)) . '.staging';

        if (!@\mkdir($staging, 0700)) {
            throw new StateArchiveFailure(
                StateArchiveFailure::UNWRITABLE,
                $directory,
                \sprintf('%s could not be created', $staging)
            );
        }

        $temporary = $staging . '/' . $name;
        $final     = $directory . '/' . $name;

        try {
            $this->build($entries, $temporary);
            $this->restrict($temporary);

            if (!@\rename($temporary, $final)) {
                throw new StateArchiveFailure(
                    StateArchiveFailure::UNPUBLISHED,
                    $final,
                    \sprintf('%s could not be renamed to %s', $temporary, $final)
                );
            }
        } finally {

            @\unlink($temporary);
            @\rmdir($staging);
        }

        return $final;
    }

    /**
     * @param array<string, string> $entries
     *
     * @throws StateArchiveFailure When the archive cannot be built.
     */
    private function build(array $entries, string $path): void
    {
        $zip = new ZipArchive();

        // ZipArchive also emits English warnings; checked return values become
        // translated installer failures instead.
        if (@$zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new StateArchiveFailure(
                StateArchiveFailure::UNWRITABLE,
                $path,
                \sprintf('%s could not be opened for writing', $path)
            );
        }

        foreach ($entries as $entry => $source) {
            if (!@$zip->addFile($source, $entry)) {
                @$zip->close();

                // The archive is writable; this file is not readable. Saying otherwise
                // would send the operator to check a folder that is fine.
                throw new StateArchiveFailure(
                    StateArchiveFailure::UNREADABLE,
                    $source,
                    \sprintf('%s could not be added to the archive', $entry)
                );
            }
        }

        if (!@$zip->close()) {
            // Measured on PHP 8.3: a source the process cannot read is accepted by
            // addFile() and fails here, at the point the entries are actually read. So
            // the sources are asked before the destination is blamed - the operator
            // would otherwise be sent to check a folder that is perfectly writable.
            $unreadable = self::firstUnreadable($entries);

            if ($unreadable !== null) {
                throw new StateArchiveFailure(
                    StateArchiveFailure::UNREADABLE,
                    $unreadable,
                    \sprintf('%s could not be read into the archive', $unreadable)
                );
            }

            throw new StateArchiveFailure(
                StateArchiveFailure::UNWRITABLE,
                $path,
                \sprintf('%s could not be completed', $path)
            );
        }
    }

    /**
     * The first source the process cannot read, or null when it can read them all.
     *
     * @param array<string, string> $entries
     */
    private static function firstUnreadable(array $entries): ?string
    {
        foreach ($entries as $source) {
            if (!\is_file($source) || !\is_readable($source)) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Give the archive its mode, and prove it has it.
     *
     * chmod() is checked rather than attempted. An archive published with the mode
     * the umask happened to give it, under a message telling the operator their
     * history was saved, is the failure this whole step exists to prevent: the mode
     * is part of what the message claims.
     *
     * @throws StateArchiveFailure When the mode cannot be set or is not 0600 afterwards.
     */
    private function restrict(string $path): void
    {
        if (!@\chmod($path, 0600)) {
            throw new StateArchiveFailure(
                StateArchiveFailure::UNSECURED,
                $path,
                \sprintf('%s could not be given mode 0600', $path)
            );
        }

        \clearstatcache(true, $path);
        $mode = @\fileperms($path);

        if ($mode === false || ($mode & 0777) !== 0600) {
            throw new StateArchiveFailure(
                StateArchiveFailure::UNSECURED,
                $path,
                \sprintf(
                    '%s is mode %s and not 0600',
                    $path,
                    $mode === false ? 'unreadable' : \substr(\sprintf('%o', $mode), -4)
                )
            );
        }
    }

    /**
     * Is this entry at the root of `state/` machinery rather than history?
     *
     * `run.lock` is created by taking the lock, which this very operation does, so
     * archiving it would make every tree look as though it had written something.
     * An `events.log` of zero bytes is a log that was created and never appended
     * to. The publication temporaries are a record part-way onto its final name,
     * and the complete record is either already there or was never committed.
     *
     * Asked only at the folder's own root, so a file of one of these names nested
     * under a mirrored target path is content and is archived as content.
     */
    private function isTransient(string $directory, string $name, string $path): bool
    {
        if ($directory !== $this->paths->state()) {
            return false;
        }

        if ($name === PackagePaths::LOCK) {
            return true;
        }

        if ($name === PackagePaths::EVENT_LOG) {
            \clearstatcache(true, $path);

            return @\filesize($path) === 0;
        }

        foreach ([PackagePaths::EVENT_LOG, PackagePaths::MARKER, PackagePaths::WRITE_INTENT] as $basename) {
            if (\preg_match(PackagePaths::publicationTemporaryPattern($basename), $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param bool                  $isFolderRoot Whether $directory is one of the
     *                                            archived folders itself, which is the
     *                                            only place a guard file is a guard.
     * @param array<string, string> $entries
     */
    private function collect(string $directory, string $prefix, bool $isFolderRoot, array &$entries): void
    {
        if (\is_link($directory) || !\is_dir($directory)) {
            return;
        }

        $names = @\scandir($directory);

        if ($names === false) {
            throw new StateArchiveFailure(
                StateArchiveFailure::UNREADABLE,
                $directory,
                \sprintf('%s could not be read', $directory)
            );
        }

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path  = $directory . '/' . $name;
            $entry = $prefix . '/' . $name;

            if (\is_link($path)) {
                continue;
            }

            if (\is_dir($path)) {
                $this->collect($path, $entry, false, $entries);

                continue;
            }

            if (!\is_file($path)) {
                continue;
            }

            if ($isFolderRoot && (PackagePaths::isFolderGuard($name) || $this->isTransient($directory, $name, $path))) {
                continue;
            }

            $entries[$entry] = $path;
        }
    }
}
