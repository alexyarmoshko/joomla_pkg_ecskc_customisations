<?php

/**
 * ECSKC Customisations package installer script.
 *
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Package
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Enables both plugins when the package is first installed.
 *
 * Joomla stores a newly installed plugin disabled, and a plugin update leaves
 * `enabled` as it was (PluginAdapter::storeExtension). So an update needs
 * nothing from this script, and a plugin the site disabled stays disabled.
 */
class Pkg_Ecskc_customisationsInstallerScript
{
    /** The groups of the two plugins, both with the element `ecskccustom`. */
    private const PLUGIN_GROUPS = ['system', 'installer'];

    /**
     * Runs after install, update, discover_install and uninstall. Acts on install only.
     *
     * By then Joomla has installed all three child extensions, so both plugin
     * records exist.
     *
     * @param   string            $type     Install type.
     * @param   InstallerAdapter  $adapter  Installer adapter.
     *
     * @return  boolean
     *
     * @throws  RuntimeException  when the database refuses the update. Joomla then
     *                            aborts the package install with the driver's message.
     */
    public function postflight($type, InstallerAdapter $adapter): bool
    {
        if ($type !== 'install') {
            return true;
        }

        $db            = Factory::getContainer()->get(DatabaseInterface::class);
        $enabled       = 1;
        // The component's JoomlaOperationalCheckProvider reads the system plugin's
        // record by these values and PLUGIN_GROUPS; change both together.
        $extensionType = 'plugin';
        $element       = 'ecskccustom';

        foreach (self::PLUGIN_GROUPS as $group) {
            $query = $db->createQuery()
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = :enabled')
                ->where(
                    [
                        $db->quoteName('type') . ' = :type',
                        $db->quoteName('folder') . ' = :folder',
                        $db->quoteName('element') . ' = :element',
                    ]
                )
                ->bind(':enabled', $enabled, ParameterType::INTEGER)
                ->bind(':type', $extensionType)
                ->bind(':folder', $group)
                ->bind(':element', $element);

            $db->setQuery($query)->execute();
        }

        return true;
    }
}
