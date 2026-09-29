<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Registry;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryReadFailure;

/**
 * Converts the column values a database driver returns into the row classes' types.
 *
 * The integer rule is the one ExtensionReaderInterface states. A text column may be
 * NULL where its table allows it, and NULL reads as '': an empty version, folder or
 * manifest cache is what the resolver already treats as absent.
 */
final class DriverValue
{
    private function __construct()
    {
    }

    /**
     * @param object $row    One row from `loadObjectList()`.
     * @param string $source The table and column, for the failure message.
     *
     * @throws RegistryReadFailure
     */
    public static function integer(object $row, string $column, string $source): int
    {
        $value = self::column($row, $column, $source);

        if (\is_int($value)) {
            return $value;
        }

        if (
            \is_string($value)
            && \preg_match('/^-?(0|[1-9][0-9]*)$/', $value) === 1
            && \filter_var($value, \FILTER_VALIDATE_INT) !== false
        ) {
            return (int) $value;
        }

        throw new RegistryReadFailure(\sprintf(
            '%s: the database returned %s where an integer is expected',
            $source,
            self::describe($value)
        ));
    }

    /**
     * @throws RegistryReadFailure
     */
    public static function text(object $row, string $column, string $source): string
    {
        $value = self::column($row, $column, $source);

        if (\is_string($value)) {
            return $value;
        }

        if ($value === null) {
            return '';
        }

        throw new RegistryReadFailure(\sprintf(
            '%s: the database returned %s where text is expected',
            $source,
            self::describe($value)
        ));
    }

    /**
     * @throws RegistryReadFailure
     */
    private static function column(object $row, string $column, string $source): mixed
    {
        if (!\property_exists($row, $column)) {
            throw new RegistryReadFailure(\sprintf('%s: the database returned no such column', $source));
        }

        return $row->{$column};
    }

    private static function describe(mixed $value): string
    {
        return \is_string($value) ? \sprintf("'%s'", $value) : \get_debug_type($value);
    }
}
