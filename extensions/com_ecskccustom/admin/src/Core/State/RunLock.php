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
 * The one advisory lock a write run holds, at `state/run.lock`.
 */
final class RunLock
{
    public const IN_PROGRESS = 'another write run is in progress';

    public const READING = 'a check or dry run is in progress';

    private const UNAVAILABLE = 'the run lock at %s could not be opened';

    /** @var resource|null */
    private $handle = null;

    private ?string $refusal = null;

    public function __construct(private readonly PackagePaths $paths)
    {
    }

    public function path(): string
    {
        return $this->paths->lock();
    }

    /**
     * Take the lock, or report that this process does not have it.
     *
     * Acquiring twice is the same as acquiring once: a caller that already holds the
     * lock still holds it, and re-locking the same handle would not tell it apart
     * from a second run anyway.
     */
    public function acquire(): bool
    {
        if ($this->handle !== null) {
            return true;
        }

        // 'c+' creates the file without truncating an existing one. The lock's
        // content is never read or written; the file exists to be locked.
        $handle = @\fopen($this->path(), 'c+');

        if ($handle === false) {
            $this->refusal = \sprintf(self::UNAVAILABLE, $this->path());

            return false;
        }

        if (!\flock($handle, \LOCK_EX | \LOCK_NB)) {
            $refusal = self::holder($handle);

            if ($refusal !== null) {
                $this->refusal = $refusal;
                \fclose($handle);

                return false;
            }
        }

        $this->handle  = $handle;
        $this->refusal = null;

        return true;
    }

    /**
     * Observe the lock for a report, without creating the file.
     *
     * This never takes the exclusive write-run lock. Where the file exists it holds
     * a non-blocking shared lock, which refuses while a write run is in progress,
     * and keeps it until `release()`.
     */
    public function observe(): bool
    {
        if ($this->handle !== null) {
            return true;
        }

        // 'r' never creates.
        $handle = @\fopen($this->path(), 'r');

        if ($handle === false) {
            \clearstatcache(true, $this->path());

            // A missing state folder is a tree that cannot be read, not an unlocked
            // one, so the absent lock file only answers where the folder is there.
            if (!\file_exists($this->path()) && \is_dir(\dirname($this->path()))) {
                $this->refusal = null;

                return true;
            }

            $this->refusal = \sprintf(self::UNAVAILABLE, $this->path());

            return false;
        }

        if (!\flock($handle, \LOCK_SH | \LOCK_NB)) {
            \fclose($handle);
            $this->refusal = self::IN_PROGRESS;

            return false;
        }

        $this->handle  = $handle;
        $this->refusal = null;

        return true;
    }

    /**
     * Who holds the lock that `acquire()` was just refused, or null once `$handle`
     * holds it exclusively after all.
     *
     * @param resource $handle
     */
    private static function holder($handle): ?string
    {
        if (!\flock($handle, \LOCK_SH | \LOCK_NB)) {
            return self::IN_PROGRESS;
        }

        // Unlock before retrying rather than converting in place: flock() does not
        // promise an atomic conversion, and a failed one may leave no lock at all.
        \flock($handle, \LOCK_UN);

        if (\flock($handle, \LOCK_EX | \LOCK_NB)) {
            return null;
        }

        if (!\flock($handle, \LOCK_SH | \LOCK_NB)) {
            return self::IN_PROGRESS;
        }

        \flock($handle, \LOCK_UN);

        return self::READING;
    }

    /**
     * Why the last acquire() or observe() failed, or null when it succeeded.
     *
     * Three reasons, and they send an operator somewhere different: a lock held by
     * another write run is the design working, a lock a check or dry run holds for
     * its read is gone once that read ends, and a lock file that cannot be opened is
     * a fault in the package's own tree. Only `acquire()` can meet a read; a read
     * refused by `observe()` met a write run.
     */
    public function refusal(): ?string
    {
        return $this->refusal;
    }

    /**
     * Does this process hold a lock through this object, exclusive or shared?
     */
    public function isHeld(): bool
    {
        return $this->handle !== null;
    }

    /**
     * Release the lock, leaving the lock file in place.
     *
     * Closing the handle would release it too. Unlocking first says so in the code,
     * and costs nothing.
     */
    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        \flock($this->handle, \LOCK_UN);
        \fclose($this->handle);
        $this->handle = null;
    }
}
