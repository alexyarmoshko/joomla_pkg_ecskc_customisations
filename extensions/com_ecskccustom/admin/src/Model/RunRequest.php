<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Model;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\ReadOnlyRunnerInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;

\defined('_JEXEC') or die;

/**
 * The read-only run a display URL asks for: a check or a dry run, of the set or of one path.
 *
 * Only `check()` and `dryRun()` are reachable from here. A value this class does not
 * know becomes the whole-set check with a notice, never another runner call. The path
 * reaches the runner exactly as it arrived; the runner refuses one it does not declare.
 */
final class RunRequest
{
    public const CHECK   = 'check';
    public const DRY_RUN = 'dryrun';

    public const NOTICE_UNKNOWN_RUN   = 'COM_ECSKCCUSTOM_RUN_NOTICE_UNKNOWN_RUN';
    public const NOTICE_PATH_NOT_TEXT = 'COM_ECSKCCUSTOM_RUN_NOTICE_PATH_NOT_TEXT';

    /**
     * @param string|null $notice A language key saying why the request was not run as sent.
     */
    private function __construct(
        public readonly string $run,
        public readonly ?string $path,
        public readonly ?string $notice,
    ) {
    }

    public static function wholeSetCheck(?string $notice = null): self
    {
        return new self(self::CHECK, null, $notice);
    }

    /**
     * @param mixed $run  The raw `run` value, or null when the URL has none.
     * @param mixed $path The raw `path` value, or null when the URL has none.
     */
    public static function fromInput(mixed $run, mixed $path): self
    {
        $run ??= self::CHECK;

        if ($run !== self::CHECK && $run !== self::DRY_RUN) {
            return self::wholeSetCheck(self::NOTICE_UNKNOWN_RUN);
        }

        if ($path !== null && !\is_string($path)) {
            return self::wholeSetCheck(self::NOTICE_PATH_NOT_TEXT);
        }

        return new self($run, $path, null);
    }

    /**
     * The non-SEF display URL of a read-only run, for Route::_().
     *
     * @param string      $run  self::CHECK or self::DRY_RUN.
     * @param string|null $path One target path, or null for the set.
     */
    public static function displayUrl(string $run, ?string $path = null): string
    {
        $url = 'index.php?option=com_ecskccustom&view=targets&run=' . \rawurlencode($run);

        return $path === null ? $url : $url . '&path=' . \rawurlencode($path);
    }

    public function isDryRun(): bool
    {
        return $this->run === self::DRY_RUN;
    }

    public function execute(ReadOnlyRunnerInterface $runner): RunReport
    {
        return $this->isDryRun()
            ? $runner->dryRun($this->path)
            : $runner->check($this->path);
    }
}
