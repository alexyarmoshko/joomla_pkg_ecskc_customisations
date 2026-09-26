<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Support;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\ReportedValue;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteHistoryInterface;

/**
 * A write history with no records, for tests of read-only runs that need no state tree.
 */
final class ReportOnlyHistory implements WriteHistoryInterface
{
    private const NO_WRITE_PATH = 'the supplied history holds no record, so as far as it shows '
        . 'the package has written nothing at any target';

    private const NO_MARKER = 'the supplied history holds no run marker, so no run marker '
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
