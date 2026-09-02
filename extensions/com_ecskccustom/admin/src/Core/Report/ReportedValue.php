<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report;

/**
 * A field the report has to carry, holding either its value or the reason it has none.
 *
 * The runner contract makes this shape mandatory for the fields the walk cannot
 * always answer: "a hash might be unavailable because the file is absent or
 * unreadable, or because the package itself is at fault. In that case, the field
 * contains the reason instead of a hash. Do not omit the field." HashResult already
 * does that for digests. This does it for the rest, so the report carries no
 * nullable strings and a caller cannot skip a field by leaving it null.
 *
 * Three answers rather than two, the same split HashResult makes between an absent
 * file and one it could not read:
 *
 * - `known` is the value.
 * - `none` is a determined negative. Nothing was written at this target, and the
 *   package knows that because it read the record and found no entry.
 * - `unavailable` is a fault of the package. The event log could not be read, so
 *   whether anything was written is unknown.
 *
 * Collapsing the last two would report a site whose event log has gone missing
 * exactly like one nobody has ever written to, which sends an operator looking at
 * the wrong thing. It is the environment-value rule again: a value that cannot be
 * determined is a fault, never a default.
 */
final class ReportedValue
{
    private function __construct(
        public readonly ?string $value,
        public readonly ?string $reason,
        private readonly bool $determined,
    ) {
    }

    public static function known(string $value): self
    {
        return new self($value, null, true);
    }

    /**
     * There is no value, and this is why there is none.
     */
    public static function none(string $reason): self
    {
        return new self(null, $reason, true);
    }

    /**
     * The value could not be determined, and this is what stopped it.
     */
    public static function unavailable(string $reason): self
    {
        return new self(null, $reason, false);
    }

    public function isKnown(): bool
    {
        return $this->value !== null;
    }

    /**
     * Whether the package answered the question at all, either way.
     */
    public function isDetermined(): bool
    {
        return $this->determined;
    }

    /**
     * The field as the report renders it: the value, or the reason there is none.
     */
    public function describe(): string
    {
        return $this->value ?? (string) $this->reason;
    }
}
