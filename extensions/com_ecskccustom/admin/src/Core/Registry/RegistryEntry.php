<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry;

/**
 * One extension the registry lists, identified the way its row is keyed.
 *
 * A Joomla entry is keyed on kind, element, folder and client ID, as `#__extensions`
 * is. A Community Builder entry is keyed on CB type, folder and element, as
 * `#__comprofiler_plugin` is, and its client ID is 0 and carries no meaning.
 */
final class RegistryEntry
{
    /** The element name used for a Community Builder plugin. */
    public const KIND_CBPLUGIN = 'cbplugin';

    /**
     * @param string $kind     The registry element name: component, module, template,
     *                         language, plugin, package, library, file or cbplugin.
     * @param string $folder   '' where the kind has none, the value Joomla stores for
     *                         every type but plugin.
     * @param int    $clientId 0 site, 1 administrator, 3 api; 0 where the kind has no client.
     * @param string $cbType   The Community Builder group; '' for a Joomla entry.
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $element,
        public readonly string $folder,
        public readonly int $clientId,
        public readonly string $cbType,
        public readonly string $repo,
    ) {
    }

    public function isCommunityBuilder(): bool
    {
        return $this->kind === self::KIND_CBPLUGIN;
    }
}
