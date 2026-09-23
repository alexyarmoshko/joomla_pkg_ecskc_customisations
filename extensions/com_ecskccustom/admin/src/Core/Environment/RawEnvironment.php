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
 * The raw Joomla inputs a condition is evaluated from, before any derivation.
 */
final class RawEnvironment
{
    public function __construct(
        public readonly ?string $liveSite,
        public readonly ?string $uriBase,
        public readonly ?string $joomlaVersion,
        public readonly ?string $phpVersion,
    ) {
    }
}
