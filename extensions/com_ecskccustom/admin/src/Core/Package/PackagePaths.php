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
 * Where the installed package keeps its own files.
 *
 * One source for the layout, so the installer that creates these folders and the
 * runner that reads them cannot drift apart. The runner reads the event log, lock
 * and marker by the fixed names below rather than discovering them.
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
 * | state/     | events.log, run.lock, run.marker                   | no              |
 *
 * The three undeclared folders survive a package update because Joomla removes
 * files by comparing manifests and ignores what appears in neither. None survives
 * uninstall; that is the accepted, documented data-loss boundary.
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

    public const EVENT_LOG = 'events.log';
    public const LOCK      = 'run.lock';
    public const MARKER    = 'run.marker';

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
}
