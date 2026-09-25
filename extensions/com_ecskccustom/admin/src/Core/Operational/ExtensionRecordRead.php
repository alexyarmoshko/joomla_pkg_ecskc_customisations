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

/**
 * One raw read of an extension record's column, before anything is derived from it.
 *
 * A read either returned a value, which may be `null` for an absent row, or failed
 * with a reason. The two are kept apart because the report says which case applied:
 * a missing row and a database error are different things to go and fix.
 */
final class ExtensionRecordRead
{
    private function __construct(
        public readonly bool $failed,
        public readonly mixed $value,
        public readonly string $reason,
    ) {
    }

    /**
     * The value exactly as the database driver supplied it. `null` is an absent row.
     */
    public static function value(mixed $value): self
    {
        return new self(false, $value, '');
    }

    /**
     * The read did not complete. The reason is shown to the operator as it is.
     */
    public static function failure(string $reason): self
    {
        return new self(true, null, $reason);
    }
}
