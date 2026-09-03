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

use RuntimeException;

/**
 * Copy one file without following a symbolic link.
 *
 * Git stores symbolic links natively. A payload committed as a link, then copied
 * with `cp -a` or PHP's `copy()`, ships the bytes the link points at - which may
 * sit outside `data/payload/`. Refusing the link here is the copy-time half of
 * the same check MergedSetValidator makes against the payload directory.
 *
 * `copy()` itself follows. The link is refused first; the race between that
 * check and the copy is accepted for a build of a tree the author owns.
 */
final class LinkSafeCopy
{
    public static function copy(string $source, string $destination): void
    {
        \clearstatcache(true, $source);

        if (\is_link($source)) {
            throw new RuntimeException(\sprintf(
                'Refusing to copy %s: it is a symbolic link. A payload is bytes the '
                . 'package ships, so the source has to be a regular file.',
                $source
            ));
        }

        if (!\is_file($source)) {
            throw new RuntimeException(\sprintf(
                'Refusing to copy %s: it is not a regular file',
                $source
            ));
        }

        $directory = \dirname($destination);

        if (!\is_dir($directory) && !@\mkdir($directory, 0o755, true) && !\is_dir($directory)) {
            throw new RuntimeException(\sprintf('Could not create directory %s', $directory));
        }

        if (!@\copy($source, $destination)) {
            throw new RuntimeException(\sprintf('Could not copy %s to %s', $source, $destination));
        }
    }
}
