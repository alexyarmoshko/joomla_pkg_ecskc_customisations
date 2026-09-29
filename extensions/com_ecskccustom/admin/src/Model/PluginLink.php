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

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\EntryStatus;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryRow;

\defined('_JEXEC') or die;

/**
 * Where a Plugins row's Name links: the extension's own screen in Joomla or in
 * Community Builder, work item 10. Each form was opened on DDEV by ticket 92.
 */
final class PluginLink
{
    /**
     * The non-SEF administrator URL, not yet escaped, or null for a row with no
     * matched database row.
     *
     * A row whose manifest is missing still links, because it has a database row.
     * A template links to its styles only when enabled, because the styles list
     * shows only enabled templates. A template whose manifest is missing has no
     * enabled flag on its row, so it goes to Extensions: Manage with the rest.
     */
    public static function url(RegistryRow $row): ?string
    {
        if ($row->matchedId === null) {
            return null;
        }

        $entry = $row->entry;

        $query = match (true) {
            // The form CB's Plugin Management links each name to. Its `view=editPlugin`
            // is a plugin's own backend page, which most plugins do not have.
            $entry->isCommunityBuilder() => [
                'option' => 'com_comprofiler',
                'view'   => 'editrow',
                'table'  => 'pluginsbrowser',
                'action' => 'editrow',
                'cid'    => $row->matchedId,
            ],
            $entry->kind === 'plugin' => [
                'option'       => 'com_plugins',
                'task'         => 'plugin.edit',
                'extension_id' => $row->matchedId,
            ],
            $entry->kind === 'module' => [
                'option'    => 'com_modules',
                'view'      => 'modules',
                'client_id' => $entry->clientId,
                'filter'    => ['module' => $entry->element],
            ],
            $entry->kind === 'template' && $row->status === EntryStatus::Enabled => [
                'option'    => 'com_templates',
                'view'      => 'styles',
                'client_id' => $entry->clientId,
                'filter'    => ['template' => $entry->element],
            ],
            default => [
                'option' => 'com_installer',
                'view'   => 'manage',
                'filter' => ['search' => 'id:' . $row->matchedId],
            ],
        };

        return 'index.php?' . \http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
    }
}
