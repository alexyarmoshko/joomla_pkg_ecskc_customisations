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
 * One page of the sorted, searched rows, and the values the resolver applied to get it.
 *
 * The view builds its sortable headers and pagination footer from these, not from the
 * state it asked for, because the resolver replaces an unsortable column, an unknown
 * direction and a start past the end.
 */
final class RegistryPage
{
    /**
     * @param list<RegistryRow> $rows      The page's rows, in order.
     * @param int               $total     Rows the search matched, before slicing.
     * @param int               $start     The offset of the first row on this page.
     * @param string            $ordering  The column the rows are sorted on.
     * @param string            $direction ASC or DESC.
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $total,
        public readonly int $start,
        public readonly string $ordering,
        public readonly string $direction,
    ) {
    }
}
