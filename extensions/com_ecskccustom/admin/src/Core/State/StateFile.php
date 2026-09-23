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
 * One record under `state/`, published whole or not at all.
 *
 * Publication temporaries are named `<basename>.<12 hex>.tmp` and swept by the
 * process holding the run lock. Sweeping is not part of publishing: a temporary
 * abandoned by a process that died is indistinguishable from one being written right
 * now, and only the lock says which. Every live temporary in this process is kept
 * out of a sweep, no matter which StateFile began it, so a second object for the
 * same path cannot delete the lock holder's in-flight record.
 */
final class StateFile
{
    /**
     * The temporaries this instance is writing right now, as path => true.
     *
     * @var array<string, true>
     */
    private array $live = [];

    /**
     * Every temporary any instance in this process is writing right now.
     *
     * @var array<string, true>
     */
    private static array $processLive = [];

    public function __construct(private readonly string $path)
    {
    }

    /**
     * Drop this instance's names from the process table.
     *
     * A discarded object has abandoned its temporary: nothing can complete that
     * publish, and the next sweep must be free to remove the file. Leaving the
     * name in the process table would keep an orphan unsweepable for as long as
     * this process lives.
     */
    public function __destruct()
    {
        foreach (\array_keys($this->live) as $temporary) {
            unset(self::$processLive[$temporary]);
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Is there anything at all at the path?
     *
     * Answered generously: a directory, an unreadable file and a dangling link all
     * count. Each is a record that is
     * present and cannot be parsed, which is a fault; treating any of them as
     * absence would report a clean tree over a state nobody can explain.
     */
    public function exists(): bool
    {
        // A record published moments ago by this same request would otherwise be
        // answered from cached metadata.
        \clearstatcache(true, $this->path);

        return \file_exists($this->path) || \is_link($this->path);
    }

    /**
     * The bytes at the path, or null when they are there and cannot be read.
     *
     * Absence is the caller's question to ask first, with exists(). Splitting the
     * two is what lets a reader report "no record" and "a record I cannot read" as
     * the different things they are.
     */
    public function read(): ?string
    {
        \clearstatcache(true, $this->path);

        $bytes = @\file_get_contents($this->path);

        return $bytes === false ? null : $bytes;
    }

    /**
     * Write a complete record and move it onto the path, or change nothing.
     */
    public function publish(string $bytes): bool
    {
        $temporary = $this->beginPublication($bytes);

        return $temporary !== null && $this->completePublication($temporary);
    }

    /**
     * Write the record to its temporary name, ready to be renamed into place.
     *
     * Split from the rename because that is where the two failure modes live, and
     * because the window between them is the one a crash falls into. A caller that
     * stops here has published nothing: the final path still holds the previous
     * record or nothing at all, and the temporary is left for the next process
     * holding the run lock to sweep.
     *
     * @return string|null The temporary's path, or null when it could not be written.
     */
    public function beginPublication(string $bytes): ?string
    {
        $temporary = \dirname($this->path) . '/' . PackagePaths::publicationTemporaryName(
            \basename($this->path),
            \bin2hex(\random_bytes(PackagePaths::PUBLICATION_TEMPORARY_BYTES))
        );

        $handle = @\fopen($temporary, 'x');

        if ($handle === false) {
            return null;
        }

        $this->markLive($temporary);
        $written = @\fwrite($handle, $bytes);
        @\fclose($handle);

        if ($written !== \strlen($bytes)) {
            $this->abandonPublication($temporary);

            return null;
        }

        // Set the mode before the rename, so the record is never briefly readable
        // on terms decided by the process umask.
        @\chmod($temporary, 0644);

        return $temporary;
    }

    /**
     * Move a written record onto the path, atomically.
     *
     * Only a temporary this object began. A rename is a publish, and taking any
     * path a caller offered would let one publish whatever it liked at the record's
     * name.
     */
    public function completePublication(string $temporary): bool
    {
        if (!isset($this->live[$temporary])) {
            return false;
        }

        if (!@\rename($temporary, $this->path)) {
            $this->abandonPublication($temporary);

            return false;
        }

        $this->unmarkLive($temporary);
        \clearstatcache(true, $this->path);

        return true;
    }

    /**
     * Drop a written record that will not be published, leaving the path alone.
     */
    public function abandonPublication(string $temporary): void
    {
        if (!isset($this->live[$temporary])) {
            return;
        }

        @\unlink($temporary);
        $this->unmarkLive($temporary);
    }

    /**
     * Remove the record, reporting whether the path is clear afterwards.
     *
     * A path that was already absent is a success: the caller asked for the record
     * to be gone, and it is.
     */
    public function remove(): bool
    {
        if (!$this->exists()) {
            return true;
        }

        // unlink() removes the name, so a link is removed rather than followed.
        @\unlink($this->path);
        \clearstatcache(true, $this->path);

        return !$this->exists();
    }

    /**
     * Unlink abandoned publication temporaries, and report the ones that stayed.
     *
     * Only for the process holding the run lock. Without the lock this cannot tell
     * a temporary left by a process that died from one another process is writing
     * at this moment, and removing the second one turns a healthy publish into a
     * failed one.
     *
     * @return list<string> Absolute paths of temporaries that could not be removed.
     */
    public function sweepTemporaries(): array
    {
        $directory = \dirname($this->path);
        $pattern   = PackagePaths::publicationTemporaryPattern(\basename($this->path));
        $entries   = @\scandir($directory);

        if ($entries === false) {
            return [];
        }

        $left = [];

        foreach ($entries as $entry) {
            if (\preg_match($pattern, $entry) !== 1) {
                continue;
            }

            $temporary = $directory . '/' . $entry;

            if (isset(self::$processLive[$temporary])) {
                continue;
            }

            if (!\is_file($temporary) && !\is_link($temporary)) {
                $left[] = $temporary;
                continue;
            }

            @\unlink($temporary);
            \clearstatcache(true, $temporary);

            if (\file_exists($temporary) || \is_link($temporary)) {
                $left[] = $temporary;
            }
        }

        return $left;
    }

    private function markLive(string $temporary): void
    {
        $this->live[$temporary]        = true;
        self::$processLive[$temporary] = true;
    }

    private function unmarkLive(string $temporary): void
    {
        unset($this->live[$temporary], self::$processLive[$temporary]);
    }
}
