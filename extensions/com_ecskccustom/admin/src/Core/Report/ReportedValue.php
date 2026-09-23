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
