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
 * The read half only. Freezing and unfreezing are writes and land with the rest of
 * the state records in Phase 2; the walk needs the question answered before then,
 * because a check reports `frozen` and Phase 1 reports.
 *
 * Decision 13 of the brief: everything else about a target is read from the file's
 * own content, so the package recognises a customisation it did not write as readily
 * as one it did. A freeze is the single exception - deliberate operator state rather
 * than a property of the file - which is why it needs a marker at all.
 *
 * Existence is the whole signal, and it is answered generously: a truncated file, an
 * empty one, a directory, a symbolic link, a link whose destination is gone. The
 * marker's content says nothing, so an operator who creates one by hand gets what
 * they intended, and every malformed shape still holds the path. Anything ambiguous
 * has to fail in some direction, and for a freeze the safe direction is "held".
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
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

        // A marker written moments ago by the same request would otherwise answer
        // from cached metadata.
        \clearstatcache(true, $marker);

        // file_exists() rather than is_file(), because a marker the operator made a
        // directory is still an instruction to hold the path. is_link() is asked as
        // well, because file_exists() follows a symbolic link and answers false for
        // one whose destination is gone. A dangling marker link would otherwise
        // release the freeze silently, which is the one direction this must not fail
        // in: a freeze is deliberate operator state, and nothing but an unfreeze may
        // end it. Review s1.v24, standards finding 1.
        return \file_exists($marker) || \is_link($marker);
    }
}
