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
 * One update site linked to an extension through `#__update_sites_extensions`.
 */
final class UpdateSiteLink
{
    public function __construct(
        public readonly int $extensionId,
        public readonly int $updateSiteId,
        public readonly string $location,
        public readonly int $enabled,
    ) {
    }
}
