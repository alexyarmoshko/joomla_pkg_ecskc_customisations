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
 * Four folders, from the plan's package-owned state table:
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
