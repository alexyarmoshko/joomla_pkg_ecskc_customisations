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
 * What the run artefacts say about the tree at one moment.
 *
 * A leftover intent is only meaningful with a free lock. While the lock is held the
 * record may belong to the holder's mutation of a moment ago, and calling that
 * leftover would have a healthy run recovering from itself.
 */
final class RunState
{
    /**
     * @param list<string> $faults
     */
    private function __construct(
        public readonly string $phase,
        public readonly ReportedValue $startedAt,
        private readonly bool $leftoverIntent,
        private readonly array $faults,
    ) {
    }

    private const NO_EARLIER_RUN = 'a write run is in progress, so no earlier run is outstanding';

    /**
     * A fault is passed when the lock could not even be asked. The phase stays
     * `live` because that is the safe direction: it keeps a caller from treating a
     * tree it cannot read as clean, and from recovering from an interruption that
     * may not have happened. The fault says the package could not see for itself.
     */
    public static function live(?string $fault = null): self
    {
        if ($fault === null) {
            return new self(RunPhase::LIVE, ReportedValue::none(self::NO_EARLIER_RUN), false, []);
        }

        return new self(RunPhase::LIVE, ReportedValue::unavailable($fault), false, [$fault]);
    }

    /**
     * The lock is free and nothing outstanding was found.
     */
    public static function clean(ReportedValue $startedAt): self
    {
        return new self(RunPhase::CLEAN, $startedAt, false, []);
    }

    /**
     * The lock is free and a run marker, an intent, or both were left behind.
     *
     * @param list<string> $faults Reasons a record could not be read, which is a
     *                             fault on this report and never a clean history.
     */
    public static function interrupted(
        ReportedValue $startedAt,
        bool $leftoverIntent,
        array $faults = []
    ): self {
        return new self(RunPhase::INTERRUPTED, $startedAt, $leftoverIntent, $faults);
    }

    public function isLive(): bool
    {
        return $this->phase === RunPhase::LIVE;
    }

    public function wasInterrupted(): bool
    {
        return $this->phase === RunPhase::INTERRUPTED;
    }

    public function isClean(): bool
    {
        return $this->phase === RunPhase::CLEAN;
    }

    /**
     * Was a mutation left in flight, with an event that may never have been recorded?
     */
    public function hasLeftoverIntent(): bool
    {
        return $this->leftoverIntent;
    }

    /**
     * @return list<string>
     */
    public function faults(): array
    {
        return $this->faults;
    }

    public function isFaulted(): bool
    {
        return $this->faults !== [];
    }
}
