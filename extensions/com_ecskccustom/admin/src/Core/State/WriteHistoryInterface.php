<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\State;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\ReportedValue;

/**
 * What the package's own records say about earlier runs.
 *
 * Two questions, both of which the report has to answer and the walk cannot: when
 * the package last wrote at a target, and whether the previous run was interrupted.
 * Brief 5.6 says why they need a record at all. A file created where none existed
 * leaves no backup behind it, an unfreeze deletes its own evidence, and a target
 * that was reported rather than written leaves nothing anywhere, so the event log is
 * the only place several of these events exist.
 *
 * Both the log and the run marker are written by the write path, which lands in
 * Phase 2. This is the seam that lets the report carry the fields now and Phase 2
 * fill them without the report shape moving.
 *
 * An implementation reads records and answers. It writes nothing, and a check that
 * cannot read its own records reports a fault rather than a clean history: a
 * ReportedValue holds the reason either way.
 */
interface WriteHistoryInterface
{
    /**
     * The last actual write at this target, never the last check.
     *
     * @param string $targetPath The canonical root-relative target path.
     */
    public function lastWriteFor(string $targetPath): ReportedValue;

    /**
     * What an interrupted previous run left behind, if the records show one.
     */
    public function interruption(): ReportedValue;
}
