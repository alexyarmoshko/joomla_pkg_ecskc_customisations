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
 * Three questions, all of which the report has to answer and the walk cannot: when
 * the package last wrote at a target, whether the previous run was interrupted, and
 * which of its own records it could not read. Brief 5.6 says why they need a record at
 * all. A file created where none existed leaves no backup behind it, an unfreeze
 * deletes its own evidence, and a target that was reported rather than written leaves
 * nothing anywhere, so the event log is the only place several of these events exist.
 *
 * An implementation reads records and answers. It writes nothing, and a check that
 * cannot read its own records reports a fault rather than a clean history: a
 * ReportedValue holds the reason either way, and a damaged record is a fault of its
 * own that sits beside a valid answer rather than replacing one.
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

    /**
     * One reason per record the history could not read, which is rarely any.
     *
     * Separate from `lastWriteFor()` because a damaged line and a valid last write are
     * both true at once, and one ReportedValue cannot hold two answers. The runner
     * maps these onto the report as run-level faults that coexist with the walked
     * targets: a damaged line does not discard the walk.
     *
     * @return list<string>
     */
    public function recordFaults(): array;

    /**
     * Forget what has been read, so the next question is answered from the tree now.
     *
     * A report-only run asks once and an implementation may cache the answer: reading
     * the whole log for each of a dozen targets would read it a dozen times, and the
     * run-state read observes the run lock every time it runs. A run that **writes**
     * then asks again in the same process needs the new records, so it says so here
     * rather than every implementation having to guess when a cache went stale.
     */
    public function refresh(): void;
}
