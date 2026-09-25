<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Operational;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalCheck;

/**
 * Derives `system_plugin_enabled` from the raw `enabled` value. The only place that
 * mapping exists.
 *
 * The accepted set is matched by strict comparison. A loose `==` accepts `"01"` and
 * `true` as `1`, and a cast turns `"nonsense"` into `0`, which would report a healthy
 * site as `disabled`. Both shortcuts fail in the direction that hides a fault.
 * Anything outside the set is `unknown`, never a default: a value that cannot be
 * determined is a fault of the check.
 */
final class SystemPluginCheck
{
    public const NAME = 'system_plugin_enabled';

    public const ENABLED  = 'enabled';
    public const DISABLED = 'disabled';
    public const UNKNOWN  = 'unknown';

    private const PLUGIN = 'the system plugin plg_system_ecskccustom';

    private const IS_ENABLED = self::PLUGIN . ' is enabled';

    private const IS_DISABLED = self::PLUGIN . ' is disabled, so the site is missing every rule in '
        . 'override.css while no target path changes state';

    private const NO_RECORD = 'no extension record exists for ' . self::PLUGIN . ', so whether the '
        . 'site loads override.css cannot be determined';

    private const READ_FAILED = 'the extension record for ' . self::PLUGIN . ' could not be read, so '
        . 'whether the site loads override.css cannot be determined: %s';

    private const OUT_OF_RANGE = 'the extension record for ' . self::PLUGIN . ' holds %s in its '
        . 'enabled column, which is neither 0 nor 1, so whether the site loads override.css cannot '
        . 'be determined';

    public function derive(ExtensionRecordRead $read): OperationalCheck
    {
        if ($read->failed) {
            return self::unknown(\sprintf(self::READ_FAILED, self::printable($read->reason)));
        }

        $value = $read->value;

        if ($value === 1 || $value === '1') {
            return new OperationalCheck(self::NAME, self::ENABLED, false, self::IS_ENABLED);
        }

        if ($value === 0 || $value === '0') {
            return new OperationalCheck(self::NAME, self::DISABLED, true, self::IS_DISABLED);
        }

        if ($value === null) {
            return self::unknown(self::NO_RECORD);
        }

        return self::unknown(\sprintf(self::OUT_OF_RANGE, self::describe($value)));
    }

    private static function unknown(string $reason): OperationalCheck
    {
        return new OperationalCheck(self::NAME, self::UNKNOWN, true, $reason);
    }

    /**
     * The value's type and value, so `"1"` and `1` never read the same in a reason.
     */
    private static function describe(mixed $value): string
    {
        return match (true) {
            \is_string($value) => 'the string "' . self::printable($value) . '"',
            \is_bool($value)   => 'the boolean ' . ($value ? 'true' : 'false'),
            \is_int($value)    => 'the integer ' . $value,
            \is_float($value)  => 'the float ' . \var_export($value, true),
            default            => 'a value of type ' . \get_debug_type($value),
        };
    }

    /**
     * Control bytes escaped, so the reason stays one printable line.
     */
    private static function printable(string $text): string
    {
        return \addcslashes($text, "\0..\37\177");
    }
}
