<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Build;

/**
 * What the build's validation pass found.
 *
 * Errors stop the artefact. Warnings print and the build continues. `ok()` is
 * the gate the Makefile uses: warnings do not fail it.
 */
final class BuildCheck
{
    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $warnings,
    ) {
    }

    public function ok(): bool
    {
        return $this->errors === [];
    }
}
