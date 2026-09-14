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
 *
 * Five folders. The plan's package-owned state table names four; rules/ became an
 * installed folder of its own after it was written, and is treated as one here:
 *
 * | Folder     | Holds                                            | In the manifest |
 * |------------|--------------------------------------------------|-----------------|
 * | payload/   | files shipped by the package                      | yes             |
 * | rules/     | the rule set shipped by the package                | yes             |
 * | backup/    | displaced files and the freeze markers beside them| no              |
 * | staging/   | files prepared before an atomic move               | no              |
 * | state/     | events.log, run.lock, run.marker, write.intent     | no              |
 *
 * The three undeclared folders survive a package update because Joomla removes
 * files by comparing manifests and ignores what appears in neither. Uninstall
 * still deletes all three directories. The uninstall hook copies the contents
 * of backup/ and state/ to a guarded archive outside the component first.
 * staging/ is not archived: it holds nothing that outlives a completed run.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI, and tests build
 * one of these over a temporary tree.
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
     *
     * Matched exactly, and only at a folder's own root, because that is where the
     * installer writes and where the manifest ships. A prefix match would be simpler
     * and would quietly swallow a real file: `.htaccess.css` and `.htaccessible.css`
     * are ordinary names an author may use, and a payload hidden from the
     * unreferenced-payload check is a check that stopped running.
     *
     * The transient pattern takes any hex length rather than the twelve characters
     * `script.php` currently writes, so changing the token's size cannot silently
     * turn a leftover into a reported payload.
     */
    public const FOLDER_INDEX = 'index.html';
    public const HTACCESS     = '.htaccess';

    /** The name protect() writes before it renames the file over the guard. */
    public const HTACCESS_TEMPORARY = '/\A\.htaccess\.[0-9a-f]+\.tmp\z/';

    /**
     * The suffix that marks a target frozen, written beside that target's backups.
     *
     * Decision 19 of the brief puts the marker under `backup/`, beside the path's
     * backup chain, rather than beside the payload: `payload/` is declared in the
     * manifest and a later manifest that stopped declaring it would take the markers
     * with it. `backup/` is declared nowhere, so Joomla's update never walks it.
     *
     * The name mirrors the site tree the same way a backup does, so
     * `templates/et_tennis/css/override.css` freezes at
     * `backup/templates/et_tennis/css/override.css.frozen`. One directory listing
     * then shows a target's whole history and whether it is held.
     *
     * `.frozen` is outside the path policy's allowed extensions, so no rule can name
     * a marker as its target even if the deny list on the package tree were lifted.
     */
    public const FREEZE_MARKER = '.frozen';

    public const EVENT_LOG    = 'events.log';
    public const LOCK         = 'run.lock';
    public const MARKER       = 'run.marker';
    public const WRITE_INTENT = 'write.intent';

    /**
     * The name a state file is written under before it is renamed into place.
     *
     * `run.marker` and `write.intent` are published the way protect() publishes the
     * folder guard: a complete record is written to a unique temporary name in the
     * same directory and renamed onto the final path, so a crash during the write
     * never leaves truncated content where a reader expects a complete record.
     *
     * The token is twelve hex characters, the shape brief 5.6 names, and the pattern
     * matches that length and no other. The sweep unlinks what it matches, so the
     * pattern is the whole of what protects a file that merely looks like a
     * temporary: widening it to any hex length would put `run.marker.a.tmp`, a name
     * this package never writes, inside a delete.
     *
     * Both halves live here so the name a publish writes and the pattern a sweep
     * matches cannot drift apart.
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
     * realpath() on the whole path returns false when the destination does not
     * exist yet, which is the archive folder's usual state at the moment the
     * installer is about to create it. The deepest existing ancestor is what can
     * be canonicalised; the missing suffix is then applied with "." skipped and
     * ".." popping, so an alias is judged by where it lands.
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
     * Is this the shape RuleObject carries a target path in?
     *
     * Relative, forward slashes, and no segment that could climb out of the tree it
     * is joined to. Here rather than beside one caller because two places build a
     * path from a target and a third records one: a marker under `backup/`, and an
     * event-log record that names the target it happened at. A record whose target
     * field held `../../etc/passwd` would claim the package wrote outside the site.
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
