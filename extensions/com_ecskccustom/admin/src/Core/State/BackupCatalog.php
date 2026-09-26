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

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\HashAlgorithm;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\Hasher;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;

/**
 * Lists a target's backups for the steps that restore from them.
 *
 * BackupStore writes backups and this reads them. Both name them through
 * PackagePaths, so the two agree on what a backup of a target is called.
 */
final class BackupCatalog
{
    private readonly Hasher $hasher;

    public function __construct(private readonly PackagePaths $paths)
    {
        $this->hasher = new Hasher();
    }

    /**
     * This target's backups, newest first.
     *
     * A target with no mirrored directory has no backups, and that is an answer. Not
     * being able to look is not: a symbolic link at a segment, a file where the
     * directory should be, or a directory that cannot be listed or bound all return
     * null rather than report a chain with nothing behind it.
     *
     * Newest is by modification time, and backups with the same time are ordered by
     * name, descending, so the order does not depend on the directory's.
     *
     * @param string      $targetPath       The canonical root-relative target path.
     * @param string      $hashType         The algorithm the target's objects declare.
     *                                      A name whose digest is not that length is
     *                                      not one of this target's backups.
     * @param string|null $reason           Set when the backups could not be listed.
     * @param bool        $requireHashMatch Leave out a backup whose bytes no longer
     *                                      hash to the digest its name declares.
     *
     * @return list<BackupEntry>|null Null with $reason set when they could not be listed.
     */
    public function newestFirst(
        string $targetPath,
        string $hashType,
        ?string &$reason,
        bool $requireHashMatch = false,
    ): ?array {
        $length = HashAlgorithm::digestLength($hashType);

        if ($length === null) {
            $reason = HashAlgorithm::unsupportedReason($hashType);

            return null;
        }

        $missing = false;
        $bound   = BoundBackupDirectory::open($this->paths, $targetPath, false, missing: $missing);

        if (\is_string($bound)) {
            if ($missing) {
                // No mirrored directory is no backup, which is an answer rather than
                // a fault: a target nothing has ever displaced has none.
                return [];
            }

            // Anything else means the backups could not be looked at. Reading that
            // as an empty listing would report a clean start of chain over state
            // the package never saw, and a step back is not taken on a guess.
            $reason = \sprintf(
                'the backups of %s could not be inspected: %s',
                $targetPath,
                $bound
            );

            return null;
        }

        try {
            $entries = @\scandir($bound->proc);

            if ($entries === false) {
                $reason = \sprintf(
                    'the backups of %s could not be listed, so the package cannot say what to '
                    . 'restore there',
                    $targetPath
                );

                return null;
            }

            $directory = \dirname($this->paths->backup() . '/' . $targetPath);
            $found     = [];

            foreach ($entries as $entry) {
                $parsed = PackagePaths::parseBackupName($entry, $targetPath);

                if ($parsed === null || \strlen($parsed['digest']) !== $length) {
                    continue;
                }

                $path = $bound->child($entry);
                \clearstatcache(true, $path);

                if (\is_link($path) || !\is_file($path)) {
                    continue;
                }

                if ($requireHashMatch && !$this->hasher->hashFile($hashType, $path)->matches($parsed['digest'])) {
                    continue;
                }

                $found[] = [
                    'entry'    => new BackupEntry($directory . '/' . $entry, $parsed['digest'], $parsed['version']),
                    'modified' => (int) @\filemtime($path),
                    'name'     => $entry,
                ];
            }
        } finally {
            $bound->close();
        }

        \usort(
            $found,
            static fn (array $left, array $right): int => [$right['modified'], $right['name']]
                <=> [$left['modified'], $left['name']]
        );

        return \array_column($found, 'entry');
    }
}
