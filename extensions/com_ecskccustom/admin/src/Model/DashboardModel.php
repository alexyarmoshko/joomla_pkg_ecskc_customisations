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

use Joomla\CMS\MVC\Model\BaseDatabaseModel;

\defined('_JEXEC') or die;

/**
 * The installed component's version and author, as Joomla recorded them from the
 * manifest at install time. It runs no check.
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
}
