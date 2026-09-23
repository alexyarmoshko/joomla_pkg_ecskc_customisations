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
 *
 * Joomla removes a component's directories on uninstall, and `backup/` and `state/`
 * live inside them. That takes every displaced original, every freeze marker and the
 * whole event log - the history decision 4 reverts from, which nothing can rebuild.
 * `InstallerAdapter::uninstall()` triggers the script's `uninstall` hook before
 * `ComponentAdapter::finaliseUninstall()` deletes anything, so this runs while the
 * files are still there.
 *
 * `staging/` is not archived. It holds files part-way through an atomic move and
 * nothing that survives a completed run, so a copy of it would preserve no history.
 *
 * The archive is one zip written outside the component tree. It is evidence, not
 * package state: nothing reads it back, and reinstalling does not restore it. An
 * operator who wants the history keeps the file.
 *
 * ## One operation, under the run lock
 *
 * Decisions 34, 36 and 39. `archive()` is the whole of it: take `run.lock`, then
 * decide whether there is history, then ask for the destination, then write. A
 * caller that walked first and locked afterwards would decide against a tree a
 * live apply was still moving files through, and would copy a torn snapshot.
 *
 * The acquisition is non-blocking and a refusal fails the archive: `RUN_LOCKED`
 * for a write run, `REPORT_READING` for a check or dry run's read, and
 * `LOCK_UNAVAILABLE` for a lock file that cannot be opened. Waiting would stall an
 * uninstall behind a run that may never end.
 * The uninstall itself still proceeds, decision 26: it is deliberate, and Joomla
 * discards a false return from the hook.
 *
 * The destination provider is called only once history has been found, so a fresh
 * install that never wrote anything creates no folder to hold an archive it will
 * not write.
 *
 * The flock is released before the operation returns, whatever happened. It is not
 * held into uninstall `postflight`: `run.lock` lives in the tree Joomla is about to
 * unlink, and ticket 28 forbids unlinking a held lock. So the lock guarantees a
 * consistent zip, not the survival of a live run - uninstall may delete the tree
 * under one, decision 38.
 *
 * No Joomla dependency, so the tests build one of these over a temporary tree.
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
     * The folder guards are left out. `.htaccess` and `index.html` at a folder's own
     * root are the installer's, not content, and the folder they would protect does
     * not exist inside a zip.
     *
     * So are `state/run.lock`, an `events.log` with nothing in it, and the
     * publication temporaries of the state files. None of them is history, decision
     * 35: taking the lock creates the lock file, and a log that has been opened and
     * never appended to holds no record. A tree that has only those is a tree that
     * never wrote, and an archive of it would tell the operator the opposite.
     *
     * @return array<string, string>
     */
    public function contents(): array
    {
        $entries = [];

        // Paths come from PackagePaths, not a second list of names. Each folder
        // becomes the top-level folder of the same name inside the zip, so the
        // layout matches the installed tree and a restore is a plain copy.
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

            // Nothing to save, so nothing is asked of the destination provider and
            // no folder appears in the site's temporary directory.
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
     * The zip is built inside a private staging directory, given its mode, and
     * renamed into place, so no reader sees a half-written archive and no other user
     * sees a complete one before its mode is set and checked.
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

        // The configured temporary path can sit under the component. Writing there
        // succeeds, the operator is told the history was saved, and Joomla then
        // deletes the archive with the tree.
        if ($this->paths->contains($directory)) {
            throw new StateArchiveFailure(
                StateArchiveFailure::UNWRITABLE,
                $directory,
                \sprintf('%s is inside the component tree', $directory)
            );
        }

        $name = self::name($time ?? \time(), \bin2hex(\random_bytes(6)));

        // The archive is built inside a private directory of its own rather than
        // beside its final name.
        //
        // ZipArchive creates the file during close(), with the mode the process umask
        // decides - 0644 under the usual 022, and 0666 under 000, both measured. A
        // chmod() afterwards closes the file's permissions but not the interval
        // between the two calls, during which a complete archive of displaced site
        // files sits there readable. A 0700 directory closes the interval instead: no
        // other user can reach the file at any point, whatever mode it is created
        // with. The mode is then set and checked before the archive is published, and
        // the rename carries it across.
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
            // Whatever happened, nothing of the package's is left in a folder the
            // package does not own. An archive that reached $final has been renamed
            // out of $staging already.
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

        // The three calls below are error-suppressed because each is already checked.
        // ZipArchive reports failure twice: through the return value, and through a PHP
        // warning in English - "Read error: No such file or directory" from close(),
        // most often. Installer output is captured and shown to the operator, so that
        // warning would land on the screen beside the translated message this class
        // exists to make possible. The typed failure carries the same information.
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

        // close() is where the entries are actually read and written, so a failure
        // here is a failure of the whole archive and not of tidying up after it.
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
