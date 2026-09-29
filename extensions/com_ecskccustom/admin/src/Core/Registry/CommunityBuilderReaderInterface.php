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
 * Reads Community Builder's plugin table and manifest files, and decides nothing.
 *
 * The resolver calls it only after finding `com_comprofiler` installed, so the
 * adapter never queries a table that may not exist.
 */
interface CommunityBuilderReaderInterface
{
    /**
     * Every `#__comprofiler_plugin` row whose `element` is one of the entries' elements,
     * whatever its `type` and `folder`. The resolver matches `type` against the entry's
     * `cbType`, never its kind, which is always `cbplugin`.
     *
     * Each row carries its XML manifest, parsed whatever its `manifest_cache` holds:
     * whether the cache is usable is the resolver's decision, so the reader never skips
     * the file on its own reading of it.
     *
     * @param list<RegistryEntry> $entries Community Builder entries only.
     *
     * @return list<CommunityBuilderPluginRow>
     *
     * @throws RegistryReadFailure
     */
    public function plugins(array $entries): array;
}
