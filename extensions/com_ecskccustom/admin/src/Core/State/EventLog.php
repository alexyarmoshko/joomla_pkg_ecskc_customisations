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

use JsonException;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;

/**
 * The append-only log at `state/events.log`: one record per line, never rewritten.
 *
 * Brief 5.6 requires it because the backups cannot cover every mutation. A file created
 * where none existed displaces nothing, a removal can be told to keep no copy, and
 * an unfreeze deletes its own evidence. It records only mutation and recovery outcomes.
 * Checks, dry runs, ordinary skips and pre-intent faults stay in the current report.
 * Without the log, a path frozen and released three times reads exactly like one
 * nobody touched. The runtime reads it to fill report fields. It is not authoritative
 * target state, which is the file's own bytes, and it is not the recovery record,
 * which is the lock, the marker, the intent and those bytes.
 *
 * ## The record boundary
 *
 * A committed record is **one UTF-8 JSON object, then TAB, then `committed`, then
 * LF**, decision 29. The TAB and the token are the commit mark; LF is only the line
 * boundary. A process that dies after the object and before the token leaves bytes
 * that are perfectly good JSON, and without a separate mark the next writer's LF
 * would promote that fragment to a committed record. So:
 *
 * - A line that does not end with TAB `committed` is damaged, a syntactically
 *   complete JSON object included.
 * - A tail that has TAB `committed` and lacks only LF **is committed**, decision 33.
 *   The writer had already written the commit mark. A read before the next append and
 *   a read after it therefore return the same records and the same faults.
 * - A writer that finds a non-empty file not ending in LF writes LF first, so its own
 *   record starts on its own line. That LF is not a commit token and does not make
 *   the line before it a record.
 *
 * ## Locking
 *
 * Only write runs append, and each already holds `run.lock`. The log still takes an
 * exclusive lock for each append and a shared lock for each read, so this module does
 * not depend on a caller keeping that wider run invariant. The append lock waits
 * rather than dropping durable mutation history. The holds are short, one seek and
 * one write.
 *
 * A first append creates the log exclusively. PHP's `fopen()` follows a symbolic
 * link and will open a FIFO until a writer appears, so a path that is already
 * something else is refused before the open. After the lock, the opened inode is
 * compared with `lstat()` of the path: another process can replace the name
 * between the type check and the open, and writing or reading then would follow
 * whatever now sits there. Mode 0644 is set through the handle, not the path,
 * for the same reason.
 *
 * ## A log that cannot be read or appended to
 *
 * That fault belongs on the current run report and is not itself appended, decision
 * 33: a log that cannot take a record cannot take the record of its own failure, and
 * version 1 has no second durable fault channel. `append()` returning false and
 * `failure()` are how a caller carries it there.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class EventLog
{
    /** The commit mark, and the whole of what tells a record from a fragment. */
    public const COMMITTED = "\tcommitted";

    public const NO_LOG = 'no event log: this package has recorded no event yet';

    public const EMPTY_LOG = 'the event log holds no record yet';

    private const UNREADABLE = 'the event log at %s is present and could not be read';

    private const UNWRITABLE = 'the event log at %s could not be opened for appending';

    private const UNLOCKABLE = 'the event log at %s could not be locked for %s';

    private const NOT_A_FILE = 'the path %s is not the regular file the package writes its '
        . 'event log to';

    private const SHORT_WRITE = 'the record appended to the event log at %s was written only in '
        . 'part, so it stays a damaged line there';

    private const NOT_FLUSHED = 'the record appended to the event log at %s could not be flushed, '
        . 'so whether it reached the file is unknown';

    private const NOT_COMMITTED = 'the event log at %s has a damaged record on line %d: it does '
        . 'not end with the commit token';

    private const NOT_JSON = 'the event log at %s has a damaged record on line %d: the bytes '
        . 'before the commit token are not one JSON object';

    private const NOT_A_RECORD = 'the event log at %s has a damaged record on line %d: the '
        . 'committed object is not an event record';

    private const NOT_A_MUTATION = 'the event log accepts only mutation and recovery records, '
        . 'not %s';

    private ?string $failure = null;

    public function __construct(private readonly PackagePaths $paths)
    {
    }

    public function path(): string
    {
        return $this->paths->eventLog();
    }

    /**
     * Append one committed record, creating the log if this is the first.
     *
     * A false return carries `failure()`. It is either an I/O fault for the current
     * report or a caller trying to append a readable future or legacy kind that this
     * version does not write. The caller does not retry it into the log.
     *
     * A short write is reported the same way, and leaves a line with no commit token,
     * which the reader reports as damaged. That is the honest outcome: the bytes are
     * on disk and the log is never rewritten, so the alternative would be a log that
     * claims a record the writer knows is incomplete.
     */
    public function append(EventRecord $record): bool
    {
        $this->failure = null;

        if (!$record->isMutation()) {
            $this->failure = \sprintf(self::NOT_A_MUTATION, $record->kind);

            return false;
        }

        try {
            $bytes = $record->toJson() . self::COMMITTED . "\n";
        } catch (JsonException $exception) {
            // A record built by the factories always encodes. One that does not is a
            // caller fault, and it surfaces here before anything is written.
            $this->failure = $exception->getMessage();

            return false;
        }

        $opened = $this->openForAppend();

        if ($opened === null) {
            return false;
        }

        [$handle, $created] = $opened;

        // Blocking, for the reason in the class comment. LOCK_EX can still fail, on a
        // filesystem that does not support it, and that is a fault rather than a
        // reason to write unlocked beside another process.
        if (!\flock($handle, \LOCK_EX)) {
            \fclose($handle);
            $this->failure = \sprintf(self::UNLOCKABLE, $this->path(), 'appending');

            return false;
        }

        if (!$this->isStillTheOpenedFile($handle)) {
            \flock($handle, \LOCK_UN);
            \fclose($handle);
            $this->failure = \sprintf(self::NOT_A_FILE, $this->path());

            return false;
        }

        if ($created) {
            // Before the first record goes in, so the log is never briefly readable
            // on terms the process umask decided. Through the handle: chmod of the
            // path would follow a name swapped in after the exclusive create.
            $this->chmodOpened($handle, 0o644);
        }

        $appended = $this->write($handle, $bytes);

        \flock($handle, \LOCK_UN);
        \fclose($handle);
        \clearstatcache(true, $this->path());

        return $appended;
    }

    /**
     * Why the last append failed, or null when it succeeded.
     */
    public function failure(): ?string
    {
        return $this->failure;
    }

    /**
     * Every committed record, and one fault per line that is not one.
     *
     * Read-only: a check that only reads must not bring the log into existence, so an
     * absent log is an absence here and not a file this method created.
     */
    public function read(): EventLogRead
    {
        \clearstatcache(true, $this->path());

        if (!\file_exists($this->path()) && !\is_link($this->path())) {
            return EventLogRead::absent(self::NO_LOG);
        }

        if (!$this->isOwnFile()) {
            return EventLogRead::unavailable(\sprintf(self::NOT_A_FILE, $this->path()));
        }

        $handle = @\fopen($this->path(), 'rb');

        if ($handle === false) {
            return EventLogRead::unavailable(\sprintf(self::UNREADABLE, $this->path()));
        }

        if (!\flock($handle, \LOCK_SH)) {
            \fclose($handle);

            return EventLogRead::unavailable(
                \sprintf(self::UNLOCKABLE, $this->path(), 'reading')
            );
        }

        if (!$this->isStillTheOpenedFile($handle)) {
            \flock($handle, \LOCK_UN);
            \fclose($handle);

            return EventLogRead::unavailable(\sprintf(self::NOT_A_FILE, $this->path()));
        }

        $bytes = \stream_get_contents($handle);

        \flock($handle, \LOCK_UN);
        \fclose($handle);

        if ($bytes === false) {
            return EventLogRead::unavailable(\sprintf(self::UNREADABLE, $this->path()));
        }

        return $this->parse($bytes);
    }

    /**
     * Turn the log's bytes into records and faults, without judging the file as a whole.
     */
    private function parse(string $bytes): EventLogRead
    {
        if ($bytes === '') {
            // A log the uninstall archive created to flock, or one whose first append
            // died before any byte landed. Empty is not damaged: there is no record
            // there to be wrong.
            return EventLogRead::absent(self::EMPTY_LOG);
        }

        $records = [];
        $faults  = [];
        $lines   = \explode("\n", $bytes);
        $last    = \array_key_last($lines);

        foreach ($lines as $number => $line) {
            // The bytes after the final LF, when there are none, are not a line.
            if ($number === $last && $line === '') {
                continue;
            }

            $this->classify($line, $number + 1, $records, $faults);
        }

        return EventLogRead::of($records, $faults);
    }

    /**
     * One line: a record, or a fault that names what is wrong with it.
     *
     * @param list<EventRecord> $records
     * @param list<string>      $faults
     */
    private function classify(string $line, int $number, array &$records, array &$faults): void
    {
        if (!\str_ends_with($line, self::COMMITTED)) {
            $faults[] = \sprintf(self::NOT_COMMITTED, $this->path(), $number);

            return;
        }

        $json = \substr($line, 0, -\strlen(self::COMMITTED));

        try {
            $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $faults[] = \sprintf(self::NOT_JSON, $this->path(), $number);

            return;
        }

        if (!\is_array($decoded)) {
            $faults[] = \sprintf(self::NOT_JSON, $this->path(), $number);

            return;
        }

        $record = EventRecord::fromPayload($decoded);

        if ($record === null) {
            $faults[] = \sprintf(self::NOT_A_RECORD, $this->path(), $number);

            return;
        }

        $records[] = $record;
    }

    /**
     * Seal an unterminated line if there is one, then write the record.
     *
     * One `fwrite` for both, so the seal and the record cannot be separated by a
     * crash into a lone LF that terminates a damaged line and nothing else. The bytes
     * are identical either way, and the damaged line stays damaged: the seal is an LF
     * and never the commit token.
     *
     * @param resource $handle
     */
    private function write($handle, string $bytes): bool
    {
        if (\fseek($handle, 0, \SEEK_END) !== 0) {
            $this->failure = \sprintf(self::UNWRITABLE, $this->path());

            return false;
        }

        $size = \ftell($handle);

        if ($size === false) {
            $this->failure = \sprintf(self::UNWRITABLE, $this->path());

            return false;
        }

        if ($size > 0) {
            $lastByte = $this->lastByte($handle, $size);

            if ($lastByte === null) {
                $this->failure = \sprintf(self::UNREADABLE, $this->path());

                return false;
            }

            if ($lastByte !== "\n") {
                $bytes = "\n" . $bytes;
            }

            if (\fseek($handle, 0, \SEEK_END) !== 0) {
                $this->failure = \sprintf(self::UNWRITABLE, $this->path());

                return false;
            }
        }

        $written = @\fwrite($handle, $bytes);

        if ($written !== \strlen($bytes)) {
            $this->failure = \sprintf(self::SHORT_WRITE, $this->path());

            return false;
        }

        if (!\fflush($handle)) {
            $this->failure = \sprintf(self::NOT_FLUSHED, $this->path());

            return false;
        }

        return true;
    }

    /**
     * Open the log for appending, creating it only when the name is still absent.
     *
     * Exclusive create (`x+b`) is `O_CREAT|O_EXCL`: a name that already exists,
     * including as a symbolic link or a FIFO, is refused rather than followed or
     * waited on. `c+b` would create after an absence check, which is the same race
     * as opening a file that was replaced since `isOwnFile()`.
     *
     * @return array{0: resource, 1: bool}|null The handle and whether this call created
     *                                         the log, or null when `failure` is set.
     */
    private function openForAppend(): ?array
    {
        \clearstatcache(true, $this->path());

        $handle = @\fopen($this->path(), 'x+b');

        if ($handle !== false) {
            return [$handle, true];
        }

        if (!\file_exists($this->path()) && !\is_link($this->path())) {
            $this->failure = \sprintf(self::UNWRITABLE, $this->path());

            return null;
        }

        if (!$this->isOwnFile()) {
            $this->failure = \sprintf(self::NOT_A_FILE, $this->path());

            return null;
        }

        // Existing file only: `r+b` does not create, so a name that vanished
        // after the type check is a fault rather than a new file this open made.
        $handle = @\fopen($this->path(), 'r+b');

        if ($handle === false) {
            $this->failure = \sprintf(self::UNWRITABLE, $this->path());

            return null;
        }

        return [$handle, false];
    }

    /**
     * Is what is at the path the file this class writes, and nothing else?
     *
     * Every other shape misleads a reader or a writer in its own way, so each is
     * refused rather than opened. A directory opens and locks successfully, and only
     * the read of it fails, which would otherwise arrive as an empty log. A FIFO blocks
     * in `fopen()` until somebody writes to it, which is a report that never returns
     * rather than a fault that says why. A symbolic link is followed, so reading one
     * reports a history from elsewhere and appending through one puts this history
     * there. The package writes its log directly rather than through a link, so a link at
     * this path was not put there by the package.
     *
     * This is the pre-open filter. `isStillTheOpenedFile()` is the same question
     * after the lock, against the inode actually opened.
     */
    private function isOwnFile(): bool
    {
        return !\is_link($this->path()) && \is_file($this->path());
    }

    /**
     * Is the opened handle still the regular file at this path?
     *
     * `isOwnFile()` cannot close the window between its check and `fopen()`. A
     * replacement in that window is a different inode, or no longer a regular file
     * at the name. Compared after the lock, before any read or write.
     *
     * @param resource $handle
     */
    private function isStillTheOpenedFile($handle): bool
    {
        \clearstatcache(true, $this->path());

        $opened = \fstat($handle);
        $named  = @\lstat($this->path());

        if ($opened === false || $named === false) {
            return false;
        }

        // POSIX S_IFMT / S_IFREG. `lstat()` of a symlink is the link, so a followed
        // open cannot match even when the target happens to share nothing else.
        return ($named['mode'] & 0o170000) === 0o100000
            && $opened['dev'] === $named['dev']
            && $opened['ino'] === $named['ino'];
    }

    /**
     * Set the mode on the opened inode, not on whatever the path now names.
     *
     * PHP has no `fchmod()`. On Linux, `/proc/self/fd/N` is the opened file; `chmod`
     * of that path changes the inode this handle holds even if the log's name was
     * replaced after the exclusive create.
     *
     * @param resource $handle
     */
    private function chmodOpened($handle, int $mode): void
    {
        $want = \fstat($handle);

        if ($want === false) {
            return;
        }

        $entries = @\scandir('/proc/self/fd');

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if (!\ctype_digit($entry)) {
                continue;
            }

            $candidate = '/proc/self/fd/' . $entry;
            $got       = @\stat($candidate);

            if ($got === false
                || $got['dev'] !== $want['dev']
                || $got['ino'] !== $want['ino']
            ) {
                continue;
            }

            if (@\chmod($candidate, $mode)) {
                return;
            }
        }
    }

    /**
     * The log's final byte, or null when it could not be read.
     *
     * Whether the file ends on a line boundary decides what this append writes, so a
     * byte that cannot be read is a fault rather than a guess. Guessing either way
     * damages something: assume a boundary and this record joins the end of the
     * previous line, assume none and a blank line goes in where the log was whole.
     *
     * @param resource $handle
     */
    private function lastByte($handle, int $size): ?string
    {
        if (\fseek($handle, $size - 1, \SEEK_SET) !== 0) {
            return null;
        }

        $byte = \fread($handle, 1);

        return $byte === false || $byte === '' ? null : $byte;
    }
}
