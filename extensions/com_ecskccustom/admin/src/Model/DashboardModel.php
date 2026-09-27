<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use UnexpectedValueException;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalCheck;
use YakShaver\Component\ECSKCCustomisation\Administrator\Extension\ECSKCCustomisationComponent;

\defined('_JEXEC') or die;

/**
 * The installed component's version and author, as Joomla recorded them from the
 * manifest at install time, and whether the system plugin is enabled. It checks no
 * managed path.
 */
class DashboardModel extends BaseDatabaseModel
{
    /**
     * @return array{version: string, author: string} Empty strings where Joomla recorded nothing.
     */
    public function getComponentInfo(): array
    {
        $db      = $this->getDatabase();
        $type    = 'component';
        $element = 'com_ecskccustom';

        $query = $db->getQuery(true)
            ->select($db->quoteName('manifest_cache'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = :type')
            ->where($db->quoteName('element') . ' = :element')
            ->bind(':type', $type)
            ->bind(':element', $element);

        $manifest = \json_decode((string) $db->setQuery($query)->loadResult(), true);

        return [
            'version' => \is_array($manifest) ? (string) ($manifest['version'] ?? '') : '',
            'author'  => \is_array($manifest) ? (string) ($manifest['author'] ?? '') : '',
        ];
    }

    /**
     * The runner's `system_plugin_enabled` check. The model asks the booted component
     * for the runner and derives nothing itself (plan decision 5).
     */
    public function getSystemPluginCheck(): OperationalCheck
    {
        $component = Factory::getApplication()->bootComponent('com_ecskccustom');

        if (!$component instanceof ECSKCCustomisationComponent) {
            throw new UnexpectedValueException('com_ecskccustom did not boot as ECSKCCustomisationComponent.');
        }

        return $component->runner()->systemPluginCheck();
    }
}
