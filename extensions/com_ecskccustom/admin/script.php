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

use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Language\Text;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;

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

    /**
     * Runs after install, update and discover_install alike.
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
        if (class_exists(PackagePaths::class, false)) {
            return;
        }

        $file = $root . '/src/Core/Package/PackagePaths.php';

        if (!is_file($file)) {
            throw new RuntimeException(
                Text::sprintf('COM_ECSKCCUSTOM_ERROR_STATE_FOLDER', 'src/Core/Package')
            );
        }

        require_once $file;
    }
}
