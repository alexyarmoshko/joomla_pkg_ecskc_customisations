<?php

/**
 * ECSKC Customisations installer script.
 *
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\StateArchive;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\StateArchiveFailure;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunLock;

/**
 * Creates and protects the package's own state folders.
 *
 * **This script never touches a managed customisation.** Brief decision 2 requires
 * the operator to ask for an apply; installation only prepares the component's own
 * storage. Phase 3 adds the message that asks.
 *
 * **Failure throws, and does not return false.** Joomla discards a false return
 * from postflight - `InstallerAdapter::triggerManifestScript()` guards the abort
 * with `if ($method !== 'postflight')` - so returning false reported a successful
 * install with unprotected folders. A RuntimeException is caught one level up and
 * rolls the installation back, which is the right outcome: an unprotected backup/
 * discloses displaced site files, and an unprotected state/ discloses the event
 * log. No component is better than an unprotected one.
 */
class Com_EcskccustomInstallerScript
{
    /**
     * A deny-all .htaccess.
     *
     * Brief section 5.6 records that this goes silently inert on an origin not
     * served by Apache, which is why Phase 1 proves over HTTP that the folders are
     * actually unreachable rather than trusting that the file exists.
     */
    private const DENY_ALL = <<<'HTACCESS'
        # Files here belong to the ECSKC Customisations component and are never served.
        #
        # backup/ holds displaced site files, state/ holds the event log: both would
        # disclose site contents and history if they were reachable.
        #
        # This is inert on an origin not served by Apache. It is a second line; the
        # first is that these folders hold nothing a request should ever ask for.
        <IfModule mod_authz_core.c>
            Require all denied
        </IfModule>
        <IfModule !mod_authz_core.c>
            Deny from all
        </IfModule>

        HTACCESS;

    /** The folder inside the site's temporary folder that holds uninstall archives. */
    private const ARCHIVE_FOLDER = 'com_ecskccustom-state';

    /**
     * Runs after install, update and discover_install - and after uninstall, which
     * this returns from at once.
     *
     * Idempotent: an update finds the folders already there and must leave their
     * contents alone. They carry a site's backups, freeze markers and event log,
     * and they survive an update precisely because the manifest does not mention
     * them.
     *
     * @param   string            $type     Install type.
     * @param   InstallerAdapter  $adapter  Installer adapter.
     *
     * @return  boolean
     *
     * @throws  RuntimeException  when a folder cannot be created or protected.
     */
    public function postflight($type, InstallerAdapter $adapter): bool
    {
        // Joomla runs postflight after an uninstall too - InstallerAdapter::uninstall()
        // triggers it once finaliseUninstall() has deleted the tree - and $type is
        // 'uninstall' there. Creating the folders again would put three empty
        // directories back where the component used to be, and the protection pass
        // that follows would then throw on payload/, which no longer exists: the
        // operator was told "Extension not removed" after a successful uninstall.
        if ($type === 'uninstall') {
            return true;
        }

        $root = JPATH_ADMINISTRATOR . '/components/com_ecskccustom';
        $this->loadPackagePaths($root);

        foreach (PackagePaths::CREATED_FOLDERS as $folder) {
            $path = $root . '/' . $folder;

            if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
                throw new RuntimeException(
                    Text::sprintf('COM_ECSKCCUSTOM_ERROR_STATE_FOLDER', $folder)
                );
            }
        }

        foreach (PackagePaths::ALL_FOLDERS as $folder) {
            $path = $root . '/' . $folder;

            // A missing folder here is a build fault, not something to paper over:
            // reporting a successful install with an unprotected folder is the
            // failure this whole step exists to prevent.
            if (!is_dir($path) || !$this->protect($path)) {
                throw new RuntimeException(
                    Text::sprintf('COM_ECSKCCUSTOM_ERROR_STATE_PROTECTION', $folder)
                );
            }
        }

        return true;
    }

    /**
     * Runs before Joomla deletes the component's directories.
     *
     * `InstallerAdapter::uninstall()` triggers this hook and only then reaches
     * `ComponentAdapter::finaliseUninstall()`, which removes the administrator tree
     * and with it `backup/`, `staging/` and `state/`. Until now that loss was silent:
     * the operator was told the component was removed and not that every displaced
     * original, every freeze marker and the whole event log went with it.
     *
     * So the history is copied to a zip in the temporary folder first. The uninstall
     * itself is not obstructed - it is deliberate, and Joomla discards a false return
     * from this hook anyway (`triggerManifestScript()` guards the abort with
     * `if ($method !== 'uninstall')`). Only `preflight` can refuse an uninstall.
     *
     * **Never throws.** A failure here must not leave the component half-removed, so
     * the reason is enqueued and the uninstall carries on.
     *
     * @param   InstallerAdapter  $adapter  Installer adapter.
     *
     * @return  boolean
     */
    public function uninstall(InstallerAdapter $adapter): bool
    {
        $root = JPATH_ADMINISTRATOR . '/components/com_ecskccustom';

        try {
            $this->loadCoreClass($root, PackagePaths::class, 'src/Core/Package/PackagePaths.php');
            $this->loadCoreClass(
                $root,
                StateArchiveFailure::class,
                'src/Core/Package/StateArchiveFailure.php'
            );
            $this->loadCoreClass($root, RunLock::class, 'src/Core/State/RunLock.php');
            $this->loadCoreClass($root, StateArchive::class, 'src/Core/Package/StateArchive.php');

            $paths   = new PackagePaths($root);
            $history = new StateArchive($paths);

            // One call, and the hook asks nothing of the tree before it. The archive
            // takes the run lock, decides whether there is history under it, and only
            // then calls back for the destination - so a walk never sees a tree a live
            // run is moving files through, and a fresh install that never wrote
            // history leaves no empty guarded directory in tmp/. Resolving tmp_path
            // here rather than in the closure would run that lookup, and raise its
            // failure, for an archive that is never written.
            $archive = $history->archive(fn (): string => $this->archiveFolder($paths));

            if ($archive !== null) {
                $this->enqueue('COM_ECSKCCUSTOM_UNINSTALL_ARCHIVED', 'warning', $archive);
            }
        } catch (StateArchiveFailure $failure) {
            // A run lock that could not be taken names no path the operator can act
            // on. The lock file is the package's own and is about to be deleted with
            // the tree, so its path would only point at something that no longer
            // exists. The exception still carries it for a developer reading a stack
            // trace.
            $path = \in_array($failure->reason, StateArchiveFailure::LOCK_REASONS, true)
                ? null
                : $failure->path;

            $this->enqueue($this->reasonKey($failure->reason), 'error', $path);
        } catch (\Throwable $error) {
            // Nothing the core raises reaches here: everything it knows how to fail at
            // arrives above with a reason and a path. What is left is unforeseen, and
            // its message is English from a stack trace, so the operator gets the
            // translated wrapper and the detail goes to a log file.
            $this->enqueue('COM_ECSKCCUSTOM_UNINSTALL_ARCHIVE_FAILED', 'error');
            $this->log($error->getMessage());
        }

        return true;
    }

    /**
     * Where the archive goes: a guarded folder of the package's own inside the
     * site's temporary folder.
     *
     * Outside the component tree, which is the whole point - anywhere under it is
     * deleted moments later. Not the temporary folder itself: measured on the DDEV
     * reference site, a file written there is served over HTTP with status 200, and
     * this archive holds displaced site files and the event log. It gets the same
     * deny-all treatment as backup/ and state/ had, in a folder of its own so that
     * nothing else in tmp/ is affected.
     *
     * A folder that cannot be created or protected raises, and the hook above turns
     * that into the message that the history could not be saved. An unprotected dump
     * of displaced site files is worse than no dump.
     *
     * @throws StateArchiveFailure When the folder is a link, sits inside the
     *                              component, or cannot be created or protected.
     */
    private function archiveFolder(PackagePaths $paths): string
    {
        $folder = $this->temporaryFolder() . '/' . self::ARCHIVE_FOLDER;

        // A link here is refused rather than followed. is_dir() says yes to a link to
        // a directory, so without this check a link left in the temporary folder would
        // send the archive somewhere the administrator never configured, and protect()
        // would write the deny-all guard over whatever .htaccess that directory has.
        // The package makes this folder itself; a link with its name is not it.
        if (is_link($folder)) {
            throw new StateArchiveFailure(
                StateArchiveFailure::UNWRITABLE,
                $folder,
                sprintf('%s is a symbolic link', $folder)
            );
        }

        // tmp_path is taken as configured. If that setting is the component tree, or
        // aliases into it with "..", mkdir would put the archive where Joomla is
        // about to delete it, and the operator would be told the history was saved.
        if ($paths->contains($folder)) {
            throw new StateArchiveFailure(
                StateArchiveFailure::UNWRITABLE,
                $folder,
                sprintf('%s is inside the component tree', $folder)
            );
        }

        if (!is_dir($folder) && !@mkdir($folder, 0755, true) && !is_dir($folder)) {
            throw new StateArchiveFailure(
                StateArchiveFailure::UNWRITABLE,
                $folder,
                sprintf('%s could not be created', $folder)
            );
        }

        if (!$this->protect($folder)) {
            throw new StateArchiveFailure(
                StateArchiveFailure::UNSECURED,
                $folder,
                sprintf('%s could not be given its deny-all guard', $folder)
            );
        }

        return $folder;
    }

    /**
     * The language key for one archive failure.
     *
     * A match rather than a built string, so every key appears here as a literal that
     * `InstallerLanguageTest` can find in this file and check against the .sys language
     * file. A key assembled from a code would pass that test while missing at run time.
     */
    private function reasonKey(string $reason): string
    {
        return match ($reason) {
            StateArchiveFailure::RUN_LOCKED       => 'COM_ECSKCCUSTOM_UNINSTALL_ARCHIVE_RUN_LOCKED',
            StateArchiveFailure::REPORT_READING   => 'COM_ECSKCCUSTOM_UNINSTALL_ARCHIVE_REPORT_READING',
            StateArchiveFailure::LOCK_UNAVAILABLE => 'COM_ECSKCCUSTOM_UNINSTALL_ARCHIVE_LOCK_UNAVAILABLE',
            StateArchiveFailure::UNREADABLE       => 'COM_ECSKCCUSTOM_UNINSTALL_ARCHIVE_UNREADABLE',
            StateArchiveFailure::UNSECURED        => 'COM_ECSKCCUSTOM_UNINSTALL_ARCHIVE_UNSECURED',
            StateArchiveFailure::UNPUBLISHED      => 'COM_ECSKCCUSTOM_UNINSTALL_ARCHIVE_UNPUBLISHED',
            default                               => 'COM_ECSKCCUSTOM_UNINSTALL_ARCHIVE_UNWRITABLE',
        };
    }

    /**
     * The site's configured temporary folder.
     *
     * Taken as configured, and not checked here. A configured folder that is missing
     * or unwritable is reported by archiveFolder() as the failure it is; quietly
     * writing somewhere else instead would put an archive of displaced site files in
     * a folder the administrator did not choose, and the message would name a path
     * they never configured.
     *
     * The default applies only when Joomla returns an empty setting, which names no
     * folder at all. It is Joomla's own default location, not a second guess at the
     * setting. A failure to reach the application or its configuration is not caught
     * here either: it reaches the hook's error path, because a lookup that failed is
     * not the same as a site configured with no temporary folder.
     */
    private function temporaryFolder(): string
    {
        $configured = \trim((string) Factory::getApplication()->get('tmp_path', ''));

        return $configured !== '' ? $configured : JPATH_ROOT . '/tmp';
    }

    /**
     * Write one line to a log file of the package's own.
     *
     * The category has to bring its own logger. Joomla registers loggers for `system`
     * and `deprecated`, and covers everything else only when `log_everything` is on,
     * which it is not by default - so a category-only `Log::add()` would be discarded
     * and the message telling the operator to look in the log would be a lie.
     *
     * Runs after the operator has been told, and swallows its own failures. The hook
     * this serves must not throw, and a diagnostic that cannot be written is not worth
     * leaving a component half-removed for.
     */
    private function log(string $message): void
    {
        try {
            Log::addLogger(['text_file' => 'com_ecskccustom.php'], Log::ALL, ['com_ecskccustom']);
            Log::add($message, Log::ERROR, 'com_ecskccustom');
        } catch (\Throwable) {
            // Nothing left to try, and nothing that would help the operator.
        }
    }

    /**
     * Translate and tell the operator, without letting either step stop the uninstall.
     *
     * Text::sprintf() delegates to PHP sprintf(), which raises ValueError on a
     * malformed format string. A broken language override would otherwise escape
     * the catch handlers that call this, and Joomla's uninstall caller catches
     * RuntimeException rather than Throwable, so the removal would stop before
     * file cleanup.
     */
    private function enqueue(string $key, string $type, ?string $path = null): void
    {
        try {
            $message = $path === null ? Text::_($key) : Text::sprintf($key, $path);
            Factory::getApplication()->enqueueMessage($message, $type);
        } catch (\Throwable $error) {
            $this->log($error->getMessage());
        }
    }

    /**
     * Write the deny-all file so it is never briefly absent or half-written.
     *
     * A direct write truncates the existing file first, so an interrupted or short
     * write would leave the folder exposed with a valid-looking .htaccess in place.
     * Writing a complete temporary file in the same directory and renaming over the
     * target makes the replacement atomic: the folder is protected by the old file
     * or the new one, never by a fragment of either.
     */
    private function protect(string $path): bool
    {
        $file = $path . '/' . PackagePaths::HTACCESS;

        // Never write through a link: the target could be anywhere.
        if (is_link($file)) {
            return false;
        }

        if (is_file($file) && file_get_contents($file) === self::DENY_ALL) {
            return true;
        }

        $temporary = $path . '/' . PackagePaths::HTACCESS . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (file_put_contents($temporary, self::DENY_ALL) !== strlen(self::DENY_ALL)) {
            @unlink($temporary);

            return false;
        }

        // Set the mode before the rename, so the file is never briefly readable on
        // terms decided by the process umask.
        @chmod($temporary, 0644);

        if (!@rename($temporary, $file)) {
            @unlink($temporary);

            return false;
        }

        return is_file($file) && file_get_contents($file) === self::DENY_ALL;
    }

    /**
     * Make PackagePaths available, so the folder layout has one definition.
     *
     * The component's namespace is not registered yet while this runs, so the class
     * is loaded by path. Keeping one definition matters more than the awkwardness:
     * a second list here could drift from the one the runner uses, and the two
     * disagreeing is how a folder ends up created but unprotected.
     */
    private function loadPackagePaths(string $root): void
    {
        $this->loadCoreClass($root, PackagePaths::class, 'src/Core/Package/PackagePaths.php');
    }

    /**
     * @param string $class    Fully qualified class name.
     * @param string $relative Its file, relative to the installed administrator root.
     */
    private function loadCoreClass(string $root, string $class, string $relative): void
    {
        if (class_exists($class, false)) {
            return;
        }

        $file = $root . '/' . $relative;

        if (!is_file($file)) {
            throw new RuntimeException(
                Text::sprintf('COM_ECSKCCUSTOM_ERROR_STATE_FOLDER', dirname($relative))
            );
        }

        require_once $file;
    }
}
