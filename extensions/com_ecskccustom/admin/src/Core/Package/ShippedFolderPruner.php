<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package;

/**
 * Brings an installed shipped folder, payload/ or rules/, down to what the new
 * release ships.
 *
 * Joomla updates a component by copying the new files over the old ones. It
 * removes only what the old manifest names and the new one does not, and the
 * manifest names payload/ and rules/ as whole folders, so a payload or rule file
 * a release drops stays on the site. The runner then refuses every run over an
 * unreferenced payload.
 *
 * Runs after the copy, so a failed update never leaves a folder emptied. It
 * removes only what the release does not carry and keeps the deny-all guard,
 * which the installer writes and no release ships.
 */
final class ShippedFolderPruner
{
    /**
     * Remove every file under $installed that has no counterpart under $shipped,
     * then every directory that leaves empty.
     *
     * A link is removed as itself and never followed: no release ships one. A
     * folder that cannot be read is listed as unreadable and skipped, and the walk
     * carries on with the rest, so one unreadable corner neither stops the update
     * nor keeps the readable stale files around. An unreadable folder is not known
     * to be retired, so it is never reported as something to delete. Never throws
     * for a filesystem fault.
     *
     * @param string $installed The installed folder, such as .../com_ecskccustom/payload.
     * @param string $shipped   The same folder in the extracted release.
     *
     * @return array{removed: list<string>, failed: list<string>, unreadable: list<string>}
     *         Paths relative to $installed; a folder ends in "/", and "" is
     *         $installed itself. failed holds retired entries that could not be
     *         removed; unreadable holds folders that could not be listed.
     */
    public function prune(string $installed, string $shipped): array
    {
        $result = ['removed' => [], 'failed' => [], 'unreadable' => []];

        if (!\is_dir($installed) || \is_link($installed) || !\is_dir($shipped)) {
            return $result;
        }

        $this->pruneFolder($installed, $shipped, '', $result);

        \sort($result['removed']);
        \sort($result['failed']);
        \sort($result['unreadable']);

        return $result;
    }

    /**
     * @param array{removed: list<string>, failed: list<string>, unreadable: list<string>} $result
     */
    private function pruneFolder(string $installed, string $shipped, string $prefix, array &$result): void
    {
        $names = @\scandir($installed);

        if ($names === false) {
            $result['unreadable'][] = $prefix;

            return;
        }

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path     = $installed . '/' . $name;
            $relative = $prefix . $name;
            $twin     = $shipped . '/' . $name;

            if (\is_dir($path) && !\is_link($path)) {
                $this->pruneFolder($path, $twin, $relative . '/', $result);

                // A shipped folder stays even when empty; so does one still
                // holding something that could not be removed.
                if (!\is_dir($twin) && self::isEmpty($path)) {
                    if (@\rmdir($path)) {
                        $result['removed'][] = $relative . '/';
                    } else {
                        $result['failed'][] = $relative . '/';
                    }
                }

                continue;
            }

            if ($this->kept($relative) || (\is_file($twin) && !\is_link($twin))) {
                continue;
            }

            if (@\unlink($path)) {
                $result['removed'][] = $relative;
            } else {
                $result['failed'][] = $relative;
            }
        }
    }

    /**
     * The guard and its in-flight temporaries belong to the installer, not to a release.
     */
    private function kept(string $relative): bool
    {
        if (\str_contains($relative, '/')) {
            return false;
        }

        return $relative === PackagePaths::HTACCESS
            || \preg_match(PackagePaths::HTACCESS_TEMPORARY, $relative) === 1;
    }

    private static function isEmpty(string $directory): bool
    {
        $handle = @\opendir($directory);

        if ($handle === false) {
            return false;
        }

        try {
            while (($name = \readdir($handle)) !== false) {
                if ($name !== '.' && $name !== '..') {
                    return false;
                }
            }
        } finally {
            \closedir($handle);
        }

        return true;
    }
}
