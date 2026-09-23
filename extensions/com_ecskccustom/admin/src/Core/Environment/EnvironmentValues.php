<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment;

/**
 * The closed condition vocabulary, resolved: four attributes, each either a value
 * or a fault carrying the reason it could not be determined.
 *
 * Enforcing it at this boundary rather than in the resolver means a later second
 * source of values cannot reintroduce the hazard by forgetting the rule.
 */
final class EnvironmentValues
{
    public const SITE_URL       = 'SITE_URL';
    public const HOST           = 'HOST';
    public const JOOMLA_VERSION = 'JOOMLA_VERSION';
    public const PHP_VERSION    = 'PHP_VERSION';

    /**
     * The whole vocabulary. Version 1 has no fifth attribute and adding one is a
     * deliberate act: this list, the schema's enumeration, and the resolver.
     */
    public const ATTRIBUTES = [
        self::SITE_URL,
        self::HOST,
        self::JOOMLA_VERSION,
        self::PHP_VERSION,
    ];

    private const NOT_RESOLVED = 'it was not resolved';

    private const EMPTY_VALUE = 'it resolved to an empty value. An empty string is a fault and '
        . 'never a value: it satisfies a negative lookahead, which would invert the exclusion '
        . 'its author wrote.';

    /** @var array<string, string> */
    private array $values = [];

    /** @var array<string, string> */
    private array $faults = [];

    /**
     * @param array<string, string|null> $values One entry per determined attribute.
     * @param array<string, string>      $faults Why an attribute could not be determined.
     */
    public function __construct(array $values, array $faults = [])
    {
        foreach (self::ATTRIBUTES as $attribute) {
            $value = $values[$attribute] ?? null;
            $value = $value === null ? null : \trim($value);

            if ($value === null) {
                $this->faults[$attribute] = $faults[$attribute] ?? self::NOT_RESOLVED;

                continue;
            }

            if ($value === '') {
                // The caller's own reason where it gave one. "Joomla did not report
                // its version" tells an operator what to look at; the empty-value
                // explanation below is about regular expressions and would send them
                // somewhere else entirely.
                $this->faults[$attribute] = $faults[$attribute] ?? self::EMPTY_VALUE;

                continue;
            }

            $this->values[$attribute] = $value;
        }
    }

    public function isDetermined(string $attribute): bool
    {
        return isset($this->values[$attribute]);
    }

    /**
     * The value, or null where it could not be determined.
     *
     * A non-null return is guaranteed non-empty, which is the whole point of the
     * class: a caller that has a string here has something worth matching against.
     */
    public function value(string $attribute): ?string
    {
        return $this->values[$attribute] ?? null;
    }

    /**
     * Why the attribute could not be determined, or null where it could.
     *
     * The reason is what an operator reads in a faulted target's report, so it says
     * what was missing rather than naming an error code.
     */
    public function reason(string $attribute): ?string
    {
        if (isset($this->values[$attribute])) {
            return null;
        }

        return $this->faults[$attribute]
            ?? \sprintf(
                'it is not one of the four attributes a condition may ask about (%s)',
                \implode(', ', self::ATTRIBUTES)
            );
    }
}
