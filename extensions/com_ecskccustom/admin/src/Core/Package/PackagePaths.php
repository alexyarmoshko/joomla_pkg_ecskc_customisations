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

use InvalidArgumentException;

/**
 * Where the installed package keeps its own files.
 *
 * One source for the layout, so the installer that creates these folders and the
 * runner that reads them cannot drift apart. The runner reads the event log, lock,
 * marker and write intent by the fixed names below rather than discovering them.
 */
final class PackagePaths
{
    /**
     * The files the package puts in its own folders: the shipped folder index, and
     * the deny-all guard the installer writes.
     *
     * Named here because two places have to agree about them. The installer writes
     * the guard; the runner scans payload/ for payloads and must not mistake either
     * file for one. They are not payloads, not rules, and never a target.
     */
    public const FOLDER_INDEX = 'index.html';
    public const HTACCESS     = '.htaccess';

    /** The name protect() writes before it renames the file over the guard. */
    public const HTACCESS_TEMPORARY = '/\A\.htaccess\.[0-9a-f]+\.tmp\z/';

    /**
     * The suffix that marks a target frozen, written beside that target's backups.
     */
    public const FREEZE_MARKER = '.frozen';

    public const EVENT_LOG    = 'events.log';
    public const LOCK         = 'run.lock';
    public const MARKER       = 'run.marker';
    public const WRITE_INTENT = 'write.intent';

    /**
     * The name a state file is written under before it is renamed into place.
     */
    public const PUBLICATION_TEMPORARY_BYTES = 6;

    /**
     * Folders the installer creates. payload/ and rules/ ship with the package, so
     * Joomla creates those; these three are made at install time.
     */
    public const CREATED_FOLDERS = ['backup', 'staging', 'state'];

    /**
     * Every folder the installer puts a deny-all .htaccess into.
     *
     * The plan says "all four folders", written before rules/ became an installed
     * folder of its own. It is protected on the same grounds as the rest: a rule
     * file names every managed path and its expected hash, which is a map of the
     * site's customisations and no more fit to serve than backup/ or state/.
     */
    public const ALL_FOLDERS = ['payload', 'rules', 'backup', 'staging', 'state'];

    /**
     * Is this file one the package put in the folder, rather than content?
     *
     * @param string $relativePath The path relative to the folder's own root, so a
     *                             file of the same name nested inside it is content
     *                             and is judged as content.
     */
    public static function isFolderGuard(string $relativePath): bool
    {
        return $relativePath === self::FOLDER_INDEX
            || $relativePath === self::HTACCESS
            || \preg_match(self::HTACCESS_TEMPORARY, $relativePath) === 1;
    }

    public function __construct(private readonly string $root)
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Does $path resolve inside this tree, including through "." and ".." aliases?
     *
     * The uninstall archive asks before it writes. A configured temporary folder
     * that sits under the component looks like a destination and is deleted with
     * the rest of the tree a moment later. A path that does not exist yet is
     * judged by where mkdir would put it, so a spelling with ".." is not a way
     * around the check.
     *
     * A path that cannot be resolved is not inside. The archive callers still
     * refuse a missing or unwritable destination on their own terms.
     */
    public function contains(string $path): bool
    {
        $root      = self::resolve($this->root);
        $candidate = self::resolve($path);

        if ($root === null || $candidate === null) {
            return false;
        }

        $root      = \rtrim($root, '/');
        $candidate = \rtrim($candidate, '/');

        return $candidate === $root || \str_starts_with($candidate, $root . '/');
    }

    /**
     * Canonicalise as much of the path as exists, then apply the rest, including "..".
     *
     * A dangling symbolic link is not guessed at. realpath() fails the same way
     * for a missing name and for a link whose target does not exist, and writing
     * through one would land wherever the link points.
     */
    private static function resolve(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $existing = $path;
        $missing  = [];

        while (($resolved = \realpath($existing)) === false) {
            if (\is_link($existing)) {
                return null;
            }

            $missing[] = \basename($existing);
            $parent    = \dirname($existing);

            if ($parent === $existing) {
                return null;
            }

            $existing = $parent;
        }

        foreach (\array_reverse($missing) as $part) {
            if ($part === '.' || $part === '') {
                continue;
            }

            if ($part === '..') {
                $parent = \dirname($resolved);

                if ($parent === $resolved) {
                    return null;
                }

                $resolved = $parent;
                continue;
            }

            $resolved .= '/' . $part;
        }

        return $resolved;
    }

    public function rules(): string
    {
        return $this->root . '/rules';
    }

    public function payload(): string
    {
        return $this->root . '/payload';
    }

    /** The extension registry the Plugins screen lists, staged by the build. */
    public function registry(): string
    {
        return $this->root . '/registry.xml';
    }

    public function registrySchema(): string
    {
        return $this->root . '/schema/registry.xsd';
    }

    public function backup(): string
    {
        return $this->root . '/backup';
    }

    public function staging(): string
    {
        return $this->root . '/staging';
    }

    public function state(): string
    {
        return $this->root . '/state';
    }

    /**
     * Where the marker for one target lives.
     *
     * @param string $targetPath The canonical root-relative target path, as
     *                           RuleObject carries it.
     *
     * @throws InvalidArgumentException On a path that could leave the backup tree.
     *                                  Callers reach this after path policy has
     *                                  already decided the target, so this cannot
     *                                  fire in the runner; it is here so that a
     *                                  later caller which skips that step fails
     *                                  loudly rather than testing some other file
     *                                  for existence.
     */
    public function freezeMarker(string $targetPath): string
    {
        if (!self::isCanonicalTargetPath($targetPath)) {
            throw new InvalidArgumentException(
                \sprintf('%s is not a canonical root-relative target path', $targetPath)
            );
        }

        return $this->backup() . '/' . $targetPath . self::FREEZE_MARKER;
    }

    /**
     * Where the backup of one target's displaced content lives.
     *
     * @param string $targetPath The canonical root-relative target path.
     * @param int    $version    The version of the object whose write displaces the
     *                           content.
     * @param string $digest     The hex digest of the displaced content. The name
     *                           carries it in lower case, the form `hash()` answers
     *                           in, so one content has one name.
     *
     * @throws InvalidArgumentException On a path that could leave the backup tree, a
     *                                  version below 1, or a digest that is not hex.
     *                                  A validated rule produces none of them.
     */
    public function backupFile(string $targetPath, int $version, string $digest): string
    {
        if (!self::isCanonicalTargetPath($targetPath)) {
            throw new InvalidArgumentException(
                \sprintf('%s is not a canonical root-relative target path', $targetPath)
            );
        }

        if ($version < 1) {
            throw new InvalidArgumentException(\sprintf('%d is not an object version', $version));
        }

        if (\preg_match('/\A[0-9a-fA-F]+\z/', $digest) !== 1) {
            throw new InvalidArgumentException(\sprintf('%s is not a hex digest', $digest));
        }

        return $this->backup() . '/' . $targetPath . '.v' . $version . '.' . \strtolower($digest);
    }

    /**
     * Version and digest a backup basename carries, or null if backupFile() could
     * not have written that name.
     *
     * The inverse of backupFile() for a directory listing, and only that. A name
     * this returns a version for is one backupFile() could have written, because
     * revert and redo read a listing as package state and a name they accept is
     * acted on.
     *
     * @return array{version: int, digest: string}|null
     */
    public static function parseBackupName(string $fileName, string $targetPath): ?array
    {
        if (!self::isCanonicalTargetPath($targetPath)) {
            return null;
        }

        $pattern = '/\A' . \preg_quote(\basename($targetPath), '/') . '\.v(\d+)\.([0-9a-f]+)\z/';

        if (\preg_match($pattern, $fileName, $match) !== 1) {
            return null;
        }

        $version = (int) $match[1];

        if ($version < 1 || (string) $version !== $match[1]) {
            return null;
        }

        return ['version' => $version, 'digest' => $match[2]];
    }

    /**
     * Is this the shape RuleObject carries a target path in?
     *
     * Relative, forward slashes, and no segment that could climb out of the tree it
     * is joined to. Here rather than beside one caller because two places build a
     * path from a target and a third records one: a backup file and a marker under
     * `backup/`, and an event-log record that names the target it happened at. A
     * record whose target field held `../../etc/passwd` would claim the package
     * wrote outside the site.
     */
    public static function isCanonicalTargetPath(string $targetPath): bool
    {
        if ($targetPath === ''
            || \str_starts_with($targetPath, '/')
            || \str_contains($targetPath, '\\')) {
            return false;
        }

        foreach (\explode('/', $targetPath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * The name to publish $basename under, before the rename onto it.
     *
     * @param string $token Hex, PUBLICATION_TEMPORARY_BYTES bytes of it.
     */
    public static function publicationTemporaryName(string $basename, string $token): string
    {
        return $basename . '.' . $token . '.tmp';
    }

    /**
     * The pattern matching a publication temporary of $basename, and only those.
     *
     * The token length is the one publicationTemporaryName() writes, so a sweep
     * cannot reach a name this package did not put there.
     */
    public static function publicationTemporaryPattern(string $basename): string
    {
        return '/\\A' . \preg_quote($basename, '/')
            . '\\.[0-9a-f]{' . (self::PUBLICATION_TEMPORARY_BYTES * 2) . '}\\.tmp\\z/';
    }

    public function eventLog(): string
    {
        return $this->state() . '/' . self::EVENT_LOG;
    }

    public function lock(): string
    {
        return $this->state() . '/' . self::LOCK;
    }

    public function marker(): string
    {
        return $this->state() . '/' . self::MARKER;
    }

    public function writeIntent(): string
    {
        return $this->state() . '/' . self::WRITE_INTENT;
    }
}
