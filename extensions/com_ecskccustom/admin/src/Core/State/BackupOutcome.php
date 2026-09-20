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
 * What one backup request did, and where the backup is.
 *
 * Three outcomes preserve the displaced bytes and one does not. The caller needs
 * only the split: a failed backup stops the operation at that target, brief
 * decision 4. The other three stay distinct because a report that says "kept" over
 * a backup that was in fact re-made would hide the corruption that forced it.
 *
 * The path is the computed destination in every case, a failure included, so a dry
 * run and a fault both name where the backup belongs.
 */
final class BackupOutcome
{
    /** No backup was at the name, and one was published. */
    public const CREATED = 'created';

    /** A backup was at the name, it hashes to the name, and the source still does. Recency is updated; the bytes are not rewritten. */
    public const KEPT = 'kept';

    /** Something was at the name that was not a valid backup, and was replaced by one. */
    public const REMADE = 'remade';

    /** No valid backup is at the name. The operation must not proceed. */
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly string $path,
        public readonly string $reason,
    ) {
    }

    public static function created(string $path): self
    {
        return new self(self::CREATED, $path, 'the displaced bytes were copied, verified and published');
    }

    public static function kept(string $path): self
    {
        return new self(self::KEPT, $path, 'an existing backup was re-hashed and still matches the source');
    }

    public static function remade(string $path, string $reason): self
    {
        return new self(self::REMADE, $path, $reason);
    }

    public static function failed(string $path, string $reason): self
    {
        return new self(self::FAILED, $path, $reason);
    }

    /**
     * Whether a verified backup of the displaced bytes is at the path now.
     */
    public function isPreserved(): bool
    {
        return $this->status !== self::FAILED;
    }
}
