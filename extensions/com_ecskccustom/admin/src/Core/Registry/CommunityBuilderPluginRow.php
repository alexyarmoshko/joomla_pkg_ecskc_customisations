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
 * One `#__comprofiler_plugin` row, with its XML manifest when the file parses.
 *
 * `id` and `published` follow the integer rule in ExtensionReaderInterface.
 */
final class CommunityBuilderPluginRow
{
    /**
     * @param ?CommunityBuilderXmlManifest $xmlManifest Null when the file is absent or
     *                                                  does not parse.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $folder,
        public readonly string $element,
        public readonly int $published,
        public readonly string $manifestCache,
        public readonly string $name,
        public readonly ?CommunityBuilderXmlManifest $xmlManifest,
    ) {
    }
}
