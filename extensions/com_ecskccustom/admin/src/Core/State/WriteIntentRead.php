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
 * What the write-intent store found: the record, no record, or a fault.
 *
 * The same three answers ReportedValue makes for a reported field, because the
 * question has the same shape and the same trap. An intent nobody can read is not an
 * absent intent: absence says the last mutation was settled, and a fault says one
 * mutation may have happened with its event unrecorded and the package cannot tell
 * which. Collapsing them would let a damaged record read as a completed run.
 *
 * It carries the record itself rather than a string, because recovery needs the
 * correlation id and the payload, not a description of them.
 */
final class WriteIntentRead
{
    private function __construct(
        public readonly ?WriteIntent $intent,
        public readonly ?string $reason,
        private readonly bool $determined,
    ) {
    }

    public static function found(WriteIntent $intent): self
    {
        return new self($intent, null, true);
    }

    /**
     * There is no intent, and this is why there is none.
     */
    public static function none(string $reason): self
    {
        return new self(null, $reason, true);
    }

    /**
     * Whether an intent is outstanding could not be determined, and this stopped it.
     */
    public static function unavailable(string $reason): self
    {
        return new self(null, $reason, false);
    }

    public function isPresent(): bool
    {
        return $this->intent !== null;
    }

    /**
     * Whether the package answered the question at all, either way.
     */
    public function isDetermined(): bool
    {
        return $this->determined;
    }
}
