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

use InvalidArgumentException;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\HashAlgorithm;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\Hasher;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\ExclusiveCopy;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;

/**
 * Deterministic, verified backups of displaced content, work items 8 and 9.
 *
 * Brief decision 4: before an apply overwrites a file it always backs it up, and no
 * rule can turn that off. The backup sits at `PackagePaths::backupFile()`, named for
 * the version whose write displaces the content and the digest of that content.
 *
 * **The name is a claim, never proof.** A backup already at the name is re-hashed
 * before it is kept, and so is the live source. Trusting the name is what would
 * let a truncated or edited backup pass as valid, and the next write would then
 * overwrite the last intact original. Trusting the backup hash alone would let a
 * stale caller digest certify an outside edit the backup does not contain.
 * Anything at the name that does not hash to it is replaced. A symbolic link is
 * replaced even when its destination holds the right bytes, because a link is a
 * pointer somebody else controls, not a backup.
 *
 * A new backup is copied through ExclusiveCopy to an exclusive temporary beside
 * its destination, so it is written in the backup filesystem and the rename is
 * atomic. The copy is hashed and compared with the digest the caller decided on,
 * then renamed onto the name. A crash before the rename leaves
 * `<name>.<12 hex>.tmp`. No lookup reads that name, so a partial copy is never
 * taken for a backup. Sweeping those leftovers needs the run lock, and this class
 * does not take it.
 *
 * ExclusiveCopy owns the temporary. An incomplete copy unlinks the file it
 * created. A verify, chmod or rename failure does the same from preserve().
 * A source that cannot be opened, or a name that cannot be created exclusively, is
 * left alone, because this call never created it.
 *
 * The caller's digest is what both the copy and a kept backup are held to. The
 * caller has already decided which bytes it is displacing, and the intent it
 * publishes names them. A target that changed since that decision fails here,
 * even when a file that hashes to the digest already sits at the backup name.
 * The write does not go ahead over bytes nobody backed up.
 *
 * Only the write run holding the run lock calls this. It takes no lock of its own.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class BackupStore
{
    private readonly Hasher $hasher;

    public function __construct(private readonly PackagePaths $paths)
    {
        $this->hasher = new Hasher();
    }

    /**
     * Preserve the bytes at $sourcePath, or report why they could not be.
     *
     * @param string $targetPath The canonical root-relative target path.
     * @param int    $version    The version of the object whose write displaces the bytes.
     * @param string $hashType   The algorithm the rule declared.
     * @param string $digest     The digest of the bytes being displaced, under $hashType.
     * @param string $sourcePath The target's absolute path, as path policy resolved it
     *                           immediately before this call.
     *
     * @throws InvalidArgumentException On a target path, version or digest no validated
     *                                  rule can produce. The runner cannot reach this.
     */
    public function preserve(
        string $targetPath,
        int $version,
        string $hashType,
        string $digest,
        string $sourcePath,
    ): BackupOutcome {
        $destination = $this->paths->backupFile($targetPath, $version, $digest);
        $length      = HashAlgorithm::digestLength($hashType);

        if ($length === null) {
            return BackupOutcome::failed($destination, HashAlgorithm::unsupportedReason($hashType));
        }

        if (\strlen($digest) !== $length) {
            throw new InvalidArgumentException(\sprintf(
                'a %s digest has %d hex characters, not %d',
                $hashType,
                $length,
                \strlen($digest)
            ));
        }

        $reason = $this->prepareDirectory($targetPath);

        if ($reason !== null) {
            return BackupOutcome::failed($destination, $reason);
        }

        \clearstatcache(true, $destination);
        $replaced = null;

        // is_link() first: every other test here follows a link, and a link whose
        // destination holds the right bytes would otherwise be kept. Directories
        // above this name have already been refused if they were links, so a kept
        // match cannot sit behind a linked parent.
        if (\is_link($destination)) {
            $replaced = 'a symbolic link was at the backup name and was replaced by a backup';
        } elseif (\is_dir($destination)) {
            return BackupOutcome::failed(
                $destination,
                'a directory is at the backup name, so no backup can be published there'
            );
        } elseif (\file_exists($destination)) {
            if ($this->hasher->hashFile($hashType, $destination)->matches($digest)) {
                \clearstatcache(true, $sourcePath);

                if (\is_link($sourcePath) || !\is_file($sourcePath)) {
                    return BackupOutcome::failed(
                        $destination,
                        \sprintf(
                            '%s is not a regular file, so there are no bytes to back up',
                            $sourcePath
                        )
                    );
                }

                $current = $this->hasher->hashFile($hashType, $sourcePath);

                if (!$current->matches($digest)) {
                    $reason = $current->isAvailable()
                        ? \sprintf(
                            'the target hashes to %s, not %s: it changed after it was hashed, '
                            . 'so the existing backup is of different bytes and no backup of '
                            . 'the current bytes was published',
                            $current->describe(),
                            \strtolower($digest)
                        )
                        : \sprintf(
                            '%s could not be hashed (%s), so the existing backup cannot be '
                            . 'accepted for it',
                            $sourcePath,
                            $current->describe()
                        );

                    return BackupOutcome::failed($destination, $reason);
                }

                return BackupOutcome::kept($destination);
            }

            $replaced = 'the file at the backup name did not hash to its name and was re-made';
        }

        $temporary = \dirname($destination) . '/' . PackagePaths::publicationTemporaryName(
            \basename($destination),
            \bin2hex(\random_bytes(PackagePaths::PUBLICATION_TEMPORARY_BYTES))
        );

        $kind = ExclusiveCopy::copy($sourcePath, $temporary);

        if ($kind !== null) {
            return BackupOutcome::failed($destination, match ($kind) {
                ExclusiveCopy::NOT_A_FILE  => \sprintf(
                    '%s is not a regular file, so there are no bytes to back up',
                    $sourcePath
                ),
                ExclusiveCopy::UNREADABLE  => \sprintf('%s could not be opened for reading', $sourcePath),
                ExclusiveCopy::UNCREATABLE => \sprintf(
                    'the temporary backup %s could not be created',
                    $temporary
                ),
                ExclusiveCopy::INCOMPLETE  => \sprintf(
                    'the copy of %s into %s did not complete',
                    $sourcePath,
                    $temporary
                ),
            });
        }

        $reason = $this->verify($hashType, $digest, $temporary);

        if ($reason === null && !@\chmod($temporary, 0o644)) {
            $reason = \sprintf('the mode of %s could not be set', $temporary);
        }

        if ($reason === null && !@\rename($temporary, $destination)) {
            $reason = \sprintf('the verified copy could not be moved onto %s', $destination);
        }

        \clearstatcache(true, $destination);

        if ($reason !== null) {
            ExclusiveCopy::discard($temporary);

            return BackupOutcome::failed($destination, $reason);
        }

        return $replaced === null
            ? BackupOutcome::created($destination)
            : BackupOutcome::remade($destination, $replaced);
    }

    /**
     * Create the mirrored directories below `backup/`, one level at a time.
     *
     * A recursive mkdir() would follow a symbolic link part-way down and create
     * directories wherever it pointed. Each level is checked before anything is made
     * under it. `backup/` itself is the installer's, and a missing one is a fault
     * rather than something to create without its deny-all guard.
     *
     * Public because a freeze marker sits in the same directory as the backups, and
     * `Freezer` has to create that directory on the same terms.
     *
     * @return string|null Why the directory is not usable, or null when it is.
     */
    public function prepareDirectory(string $targetPath): ?string
    {
        $directory = $this->paths->backup();
        \clearstatcache(true, $directory);

        if (\is_link($directory) || !\is_dir($directory)) {
            return \sprintf('the backup folder %s is not a directory', $directory);
        }

        $parent = \dirname($targetPath);

        if ($parent === '.') {
            return null;
        }

        foreach (\explode('/', $parent) as $segment) {
            $directory .= '/' . $segment;
            \clearstatcache(true, $directory);

            if (\is_link($directory)) {
                return \sprintf(
                    '%s is a symbolic link, and nothing under backup/ is written through one',
                    $directory
                );
            }

            if (!\is_dir($directory) && !@\mkdir($directory, 0o755) && !\is_dir($directory)) {
                return \sprintf('the backup directory %s could not be created', $directory);
            }
        }

        return null;
    }

    /**
     * Hash the copy and hold it to the digest its name will claim.
     *
     * @return string|null Why the copy cannot be published, or null when it can.
     */
    private function verify(string $hashType, string $digest, string $temporary): ?string
    {
        $copied = $this->hasher->hashFile($hashType, $temporary);

        if ($copied->matches($digest)) {
            return null;
        }

        return \sprintf(
            'the copy hashes to %s, not %s: the target changed after it was hashed or the copy '
            . 'was damaged, so no backup was published',
            $copied->describe(),
            \strtolower($digest)
        );
    }
}
