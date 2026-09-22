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
 * The write history of a caller that has no package state tree to read.
 *
 * Phase 1 wired this, because nothing existed then that could write a target, append
 * to the event log, or leave a run marker: both answers were a determined negative
 * rather than a fault - the package was not failing to read a record, there was no
 * record to read and it could say why.
 *
 * Phase 2's runtime reads the records instead, through EventLogHistory. This stays as
 * the report-only answer, and as what the report's own tests compose against: the
 * report shape does not depend on there being a log, and that is worth being able to
 * demonstrate rather than assert.
 *
 * It answers the same way for every target, which is the honest answer and not a
 * placeholder.
 */
final class ReportOnlyHistory implements WriteHistoryInterface
{
    private const NO_WRITE_PATH = 'this package version only checks and reports, so it has '
        . 'written nothing at any target';

    private const NO_MARKER = 'this package version performs no write run, so no run marker '
        . 'can have been left behind';

    public function lastWriteFor(string $targetPath): ReportedValue
    {
        return ReportedValue::none(self::NO_WRITE_PATH);
    }

    public function interruption(): ReportedValue
    {
        return ReportedValue::none(self::NO_MARKER);
    }

    /**
     * No records were read, so none of them was damaged.
     *
     * @return list<string>
     */
    public function recordFaults(): array
    {
        return [];
    }

    /**
     * There is nothing cached, because there is nothing to read.
     */
    public function refresh(): void
    {
    }
}
