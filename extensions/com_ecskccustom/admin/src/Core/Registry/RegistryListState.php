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
 * The Plugins screen's list state as the request and user state give it, unchecked.
 *
 * The resolver validates each value when it builds a page, work item 11, so the view
 * can pass what Joomla's user state holds.
 */
final class RegistryListState
{
    /**
     * @param string $ordering  A column name; anything but a sortable column means Name.
     * @param string $direction ASC or DESC in any case; anything else means ASC.
     * @param string $search    The Name search; blank matches every row.
     * @param int    $start     The first row's offset; a start past the end means the last page.
     * @param int    $limit     Rows per page; 0 means the whole filtered list.
     */
    public function __construct(
        public readonly string $ordering = RegistryResolver::DEFAULT_ORDERING,
        public readonly string $direction = RegistryResolver::DEFAULT_DIRECTION,
        public readonly string $search = '',
        public readonly int $start = 0,
        public readonly int $limit = 0,
    ) {
    }
}
