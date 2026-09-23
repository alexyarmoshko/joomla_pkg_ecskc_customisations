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

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;

/**
 * Whether a target is frozen, read from the marker beside its backups.
 *
 * The read half only. `Core\Write\Freezer` freezes and unfreezes, and
 * `Core\Write\FreezeGuard` is what a write consults. The walk asks this directly,
 * because a check reports `frozen`.
 */
final class FreezeMarkers
{
    public function __construct(private readonly PackagePaths $paths)
    {
    }

    /**
     * @param string $targetPath The canonical root-relative target path.
     */
    public function isFrozen(string $targetPath): bool
    {
        $marker = $this->paths->freezeMarker($targetPath);
        $parent = \dirname($targetPath);
        $paths  = [$this->paths->backup()];

        if ($parent !== '.') {
            foreach (\explode('/', $parent) as $segment) {
                $paths[] = $paths[\array_key_last($paths)] . '/' . $segment;
            }
        }

        $paths[] = $marker;

        foreach ($paths as $path) {
            // A marker written moments ago by the same request would otherwise answer
            // from cached metadata.
            \clearstatcache(true, $path);

            if (\is_link($path)) {
                return false;
            }
        }

        // file_exists() rather than is_file(), because a marker the operator made a
        // directory is still an instruction to hold the path.
        return \file_exists($marker);
    }
}
