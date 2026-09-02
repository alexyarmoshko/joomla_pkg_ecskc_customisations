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
 * The write history of a package that has no write path.
 *
 * Phase 1 builds check and report only. Nothing exists that could write a target,
 * append to the event log, or leave a run marker, so both answers are a determined
 * negative rather than a fault: the package is not failing to read a record, there
 * is no record to read and it can say why.
 *
 * It answers the same way for every target, which is the honest answer and not a
 * placeholder. Phase 2 replaces this with the reader over `state/events.log` and
 * `state/run.marker`, and nothing else in the report changes.
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
}
