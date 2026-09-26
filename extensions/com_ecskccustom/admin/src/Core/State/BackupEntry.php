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
 * One backup of a target, as the catalog found it under `backup/`.
 *
 * The digest is the one its name declares. A caller about to restore from it hashes
 * the file again, because the name is not proof of the bytes.
 */
final class BackupEntry
{
    /**
     * @param string $path    The backup's absolute path.
     * @param string $digest  The digest its name declares, lower case.
     * @param int    $version The version of the object whose write displaced it.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $digest,
        public readonly int $version,
    ) {
    }
}
