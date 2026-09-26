<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Operational;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Operational\ExtensionRecordRead;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Operational\OperationalCheckProviderInterface;

/**
 * Reads the system plugin's `#__extensions` record, and derives nothing from it.
 *
 * `loadResult()` hands back whatever the driver produced: an integer, a numeric
 * string, or `null` for an absent row. That value goes to the core unchanged,
 * because `SystemPluginCheck` is where it gets mapped, and a cast here would turn
 * a value the core reports as `unknown` into a plausible `0`. This is the only
 * database access on the check path.
 */
final class JoomlaOperationalCheckProvider implements OperationalCheckProviderInterface
{
    public function systemPluginEnabled(): ExtensionRecordRead
    {
        // Resolved outside the try: a container that cannot build the driver throws
        // DependencyResolutionException, a RuntimeException, and that is a broken
        // site to surface, not a failed read of the plugin record.
        /** @var DatabaseDriver $db */
        $db      = Factory::getContainer()->get(DatabaseInterface::class);
        // The package's script.php selects the same record to enable it on first
        // install, from its own copy of these values; change both together.
        $type    = 'plugin';
        $folder  = 'system';
        $element = 'ecskccustom';

        try {
            // createQuery() rather than getQuery(true), which the Joomla database
            // package deprecates. The container always holds a DatabaseDriver.
            $query = $db->createQuery()
                ->select($db->quoteName('enabled'))
                ->from($db->quoteName('#__extensions'))
                ->where(
                    [
                        $db->quoteName('type') . ' = :type',
                        $db->quoteName('folder') . ' = :folder',
                        $db->quoteName('element') . ' = :element',
                    ]
                )
                ->bind(':type', $type)
                ->bind(':folder', $folder)
                ->bind(':element', $element);

            return ExtensionRecordRead::value($db->setQuery($query)->loadResult());
        } catch (\RuntimeException $e) {
            // Every exception the driver raises here extends RuntimeException:
            // ConnectionFailureException, ExecutionFailureException and
            // PrepareStatementFailureException among them.
            return ExtensionRecordRead::failure($e->getMessage());
        }
    }
}
