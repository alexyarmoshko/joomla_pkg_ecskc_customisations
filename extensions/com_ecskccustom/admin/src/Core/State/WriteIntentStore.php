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

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;

/**
 * The one in-flight mutation, at `state/write.intent`.
 *
 * Decision 32 calls this an atomic intent store, and that is all it is: it publishes
 * one record, reads it back, and removes it. **At most one exists**, because the run
 * lock serializes write runs.
 *
 * It does not wrap a mutation. Tickets 31 and 32 sequence their own - publish,
 * mutate, append the event, clear - and ticket 34 recovers a leftover record and
 * asserts a completed run. Nothing here decides when an intent may be cleared;
 * decision 32's terminal outcomes do, and they are about the event log, which this
 * class does not touch.
 *
 * A present record with a free run lock is a leftover: one mutation may have
 * happened and its event may not have been recorded. Reading one while the lock is
 * held says nothing on its own - the holder may have published it a moment ago - so
 * RunState answers the leftover question and this class answers the record one.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class WriteIntentStore
{
    public const NO_INTENT = 'no write intent: no mutation is outstanding';

    private const UNREADABLE = 'the write intent at %s is present and could not be read';

    private const MALFORMED = 'the write intent at %s does not hold one intent record';

    private readonly StateFile $file;

    public function __construct(PackagePaths $paths)
    {
        $this->file = new StateFile($paths->writeIntent());
    }

    public function path(): string
    {
        return $this->file->path();
    }

    /**
     * Write the record, whole or not at all.
     *
     * Only for a process that holds the run lock, and only immediately before the
     * mutation it describes. A false return means nothing was published and the
     * caller must not mutate: the record that would have let recovery reconstruct
     * the event is not there.
     */
    public function publish(WriteIntent $intent): bool
    {
        return $this->file->publish($intent->toJson() . "\n");
    }

    /**
     * The outstanding record, the fact that there is none, or the fault in between.
     */
    public function read(): WriteIntentRead
    {
        if (!$this->file->exists()) {
            return WriteIntentRead::none(self::NO_INTENT);
        }

        $bytes = $this->file->read();

        if ($bytes === null) {
            return WriteIntentRead::unavailable(\sprintf(self::UNREADABLE, $this->path()));
        }

        $intent = WriteIntent::fromJson($bytes);

        if ($intent === null) {
            return WriteIntentRead::unavailable(\sprintf(self::MALFORMED, $this->path()));
        }

        return WriteIntentRead::found($intent);
    }

    /**
     * Is a record there at all, whatever shape it is in?
     */
    public function isPresent(): bool
    {
        return $this->file->exists();
    }

    /**
     * Remove the record, which only a terminal outcome of decision 32 may do.
     */
    public function remove(): bool
    {
        return $this->file->remove();
    }

    /**
     * @return list<string> Abandoned intent temporaries that could not be removed.
     */
    public function sweepTemporaries(): array
    {
        return $this->file->sweepTemporaries();
    }
}
