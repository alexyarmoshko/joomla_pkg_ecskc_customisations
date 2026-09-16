<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BackupOutcome;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;

/**
 * What one write or removal did at its target, or a freeze or unfreeze at its marker.
 *
 * The status is the report's own vocabulary: `ok` when the target is where the
 * caller asked for it, `faulted` otherwise. Three facts beside it matter to the
 * runner and cannot be read from the status. `changed` says this call is known to
 * have mutated the target. `intentLeft` says `state/write.intent` is still there,
 * so the mutation is not settled and ticket 34's recovery owns it. `leftover` is
 * a staging temporary this call created and could not remove, so the run cannot
 * claim ticket 31's completed-run guarantee.
 *
 * A fault before the intent is published changed nothing and left no intent. A
 * fault after it carries the correlation id, because the intent or the event log
 * holds the same id and that is how an operator matches them.
 */
final class WriteOutcome
{
    private function __construct(
        public readonly string $status,
        public readonly bool $changed,
        public readonly bool $intentLeft,
        public readonly ?string $correlationId,
        public readonly string $reason,
        public readonly ?BackupOutcome $backup,
        public readonly ?string $leftover = null,
    ) {
    }

    /** The target already was what the caller asked for. Nothing was written. */
    public static function unchanged(string $reason): self
    {
        return new self(TargetStatus::OK, false, false, null, $reason, null);
    }

    /** The mutation happened, its event is committed and the intent is gone. */
    public static function changed(string $correlationId, string $reason, ?BackupOutcome $backup): self
    {
        return new self(TargetStatus::OK, true, false, $correlationId, $reason, $backup);
    }

    /** Stopped before the intent was published: nothing changed and nothing is outstanding. */
    public static function refused(string $reason, ?BackupOutcome $backup = null): self
    {
        return new self(TargetStatus::FAULTED, false, false, null, $reason, $backup);
    }

    /** Went wrong after the intent was published. */
    public static function faulted(
        string $correlationId,
        bool $changed,
        bool $intentLeft,
        string $reason,
        ?BackupOutcome $backup,
    ): self {
        return new self(TargetStatus::FAULTED, $changed, $intentLeft, $correlationId, $reason, $backup);
    }

    public function isFaulted(): bool
    {
        return $this->status === TargetStatus::FAULTED;
    }

    /**
     * The call returned, but its own staging temporary is still there.
     */
    public function withLeftover(string $path): self
    {
        return new self(
            TargetStatus::FAULTED,
            $this->changed,
            $this->intentLeft,
            $this->correlationId,
            \sprintf('%s. The staged payload %s could not be removed', $this->reason, $path),
            $this->backup,
            $path,
        );
    }
}
