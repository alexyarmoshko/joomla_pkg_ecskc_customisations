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

use DateTimeImmutable;
use DateTimeZone;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\ReportedValue;

/**
 * The file that says a write run began, at `state/run.marker`.
 */
final class RunMarker
{
    public const NO_MARKER = 'no run marker: no earlier write run was interrupted';

    private const UNREADABLE = 'the run marker at %s is present and could not be read';

    private const MALFORMED = 'the run marker at %s does not hold one ISO-8601 UTC timestamp';

    /** One line: an ISO-8601 UTC timestamp, LF-terminated. */
    private const FORMAT = 'Y-m-d\TH:i:s\Z';

    private const RECORD = '/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z)\n\z/';

    private readonly StateFile $file;

    public function __construct(PackagePaths $paths)
    {
        $this->file = new StateFile($paths->marker());
    }

    public function path(): string
    {
        return $this->file->path();
    }

    /**
     * Write the record that says a run began now.
     *
     * Only for a process that holds the run lock. A publish that fails is a fault:
     * the run does not proceed, and the marker path is left as it was.
     *
     * @param int|null $startedAt Unix timestamp, or null for the current time. The
     *                            caller passes one so a test can fix it; nothing in
     *                            the design reads the value back except an operator.
     */
    public function publish(?int $startedAt = null): bool
    {
        return $this->file->publish(\gmdate(self::FORMAT, $startedAt ?? \time()) . "\n");
    }

    /**
     * When the run that left this marker began, or why there is no such answer.
     *
     * Three answers, the split ReportedValue exists for: the timestamp, a determined
     * "no marker", and a fault. A present marker that cannot be read or does not
     * parse is the third, never the second.
     */
    public function read(): ReportedValue
    {
        if (!$this->file->exists()) {
            return ReportedValue::none(self::NO_MARKER);
        }

        $bytes = $this->file->read();

        if ($bytes === null) {
            return ReportedValue::unavailable(\sprintf(self::UNREADABLE, $this->path()));
        }

        if (\preg_match(self::RECORD, $bytes, $match) !== 1) {
            return ReportedValue::unavailable(\sprintf(self::MALFORMED, $this->path()));
        }

        // The shape is right; the date still has to be a real one. Round-tripping
        // catches 2026-02-31 and 2026-13-01, which the pattern above accepts and
        // PHP's parser would silently roll over into the following month.
        $parsed = DateTimeImmutable::createFromFormat(
            self::FORMAT,
            $match[1],
            new DateTimeZone('UTC')
        );

        if ($parsed === false || $parsed->format(self::FORMAT) !== $match[1]) {
            return ReportedValue::unavailable(\sprintf(self::MALFORMED, $this->path()));
        }

        return ReportedValue::known($match[1]);
    }

    /**
     * Is a marker there at all, whatever shape it is in?
     */
    public function isPresent(): bool
    {
        return $this->file->exists();
    }

    /**
     * Remove the marker, which only a completed write run may do.
     */
    public function remove(): bool
    {
        return $this->file->remove();
    }

    /**
     * @return list<string> Abandoned marker temporaries that could not be removed.
     */
    public function sweepTemporaries(): array
    {
        return $this->file->sweepTemporaries();
    }
}
