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

/**
 * What one read of the event log found: the records, the damaged lines, or a fault.
 *
 * Three answers, the shape `ReportedValue` and `WriteIntentRead` both use, because the
 * question has the same trap in it. A log that is not there yet is a determined
 * negative: a site where nothing has run has no history, and that is not a failure.
 * A log that is there and cannot be read is a fault. Whether the package wrote at a
 * path is then unknown, and reporting "nothing recorded" would read as a clean
 * history over a file nobody can open.
 *
 * **A damaged line is neither of those.** It is a fault on that record, and the
 * records on either side of it stay readable, which is why the faults travel beside
 * the records rather than instead of them. One read therefore answers both questions
 * the report asks: the last write at a target, and which lines could not be parsed.
 *
 * Nothing here truncates or repairs. The log is append-only.
 */
final class EventLogRead
{
    /**
     * @param list<EventRecord> $records
     * @param list<string>      $recordFaults
     */
    private function __construct(
        private readonly array $records,
        private readonly array $recordFaults,
        public readonly ?string $reason,
        private readonly bool $determined,
    ) {
    }

    /**
     * The log was read. Complete records, and the lines that were not records.
     *
     * @param list<EventRecord> $records
     * @param list<string>      $recordFaults
     */
    public static function of(array $records, array $recordFaults): self
    {
        return new self(\array_values($records), \array_values($recordFaults), null, true);
    }

    /**
     * There is no log, and this is why that is not a fault.
     */
    public static function absent(string $reason): self
    {
        return new self([], [], $reason, true);
    }

    /**
     * The log is there and could not be read. Nothing is known about its contents.
     */
    public static function unavailable(string $reason): self
    {
        return new self([], [], $reason, false);
    }

    /**
     * @return list<EventRecord>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * One reason per line that is not a committed record.
     *
     * @return list<string>
     */
    public function recordFaults(): array
    {
        return $this->recordFaults;
    }

    /**
     * Was the log read at all, whether or not it held anything?
     */
    public function isDetermined(): bool
    {
        return $this->determined;
    }

    /**
     * The last record that changed this target's own bytes, or null for none.
     *
     * File order is append order, so the last matching record wins. Damaged lines
     * never reach this: they are not records, and a line nobody can parse cannot
     * displace a write that was recorded whole.
     */
    public function lastWriteAt(string $targetPath): ?EventRecord
    {
        $last = null;

        foreach ($this->records as $record) {
            if ($record->target === $targetPath && $record->isWriteAtTarget()) {
                $last = $record;
            }
        }

        return $last;
    }
}
