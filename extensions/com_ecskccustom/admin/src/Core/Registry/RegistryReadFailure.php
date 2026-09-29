<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry;

use RuntimeException;

/**
 * A reader could not complete a read. The message is shown to the operator as it is.
 *
 * Thrown rather than returned as an empty list, because an empty list is a real answer:
 * it would report extensions as not installed that the package never looked for.
 */
final class RegistryReadFailure extends RuntimeException
{
}
