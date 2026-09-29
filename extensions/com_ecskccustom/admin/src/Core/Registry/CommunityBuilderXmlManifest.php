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
 * What a Community Builder plugin's XML manifest file says, read only as a fallback.
 *
 * The file sits at `components/com_comprofiler/plugin/<type>/<folder>/<element>.xml`.
 * An absent element is ''. `release` is the plugin's own version; `<version>` is the
 * Community Builder version it targets and is not carried.
 */
final class CommunityBuilderXmlManifest
{
    public function __construct(
        public readonly string $name,
        public readonly string $release,
        public readonly string $description,
    ) {
    }
}
