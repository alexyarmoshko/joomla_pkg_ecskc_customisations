<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk;

/**
 * The seven states a target path can report, from brief 5.3.
 *
 * One status per path, never one per object. Objects are cumulative, so a current
 * file will not match the earlier steps it has already passed, and reporting each
 * object separately would make a healthy file look blocked. The status describes
 * the result of the whole walk; ObjectOutcome carries the object-by-object account
 * underneath it.
 *
 * The three that read alike are worth keeping apart, because each sends the
 * operator somewhere different:
 *
 * - `blocked` means the bytes at the target are unknown to the rule set. For a
 *   vendor-owned file the vendor has changed it and a person re-cuts the payload;
 *   for a package-owned one somebody edited the live site.
 * - `incomplete` means the rule set could not carry the file to the head. The rule
 *   set needs attention, not the site.
 * - `faulted` means the package or the filesystem is at fault and the target is not
 *   implicated at all.
 */
final class TargetStatus
{
    public const OK             = 'ok';
    public const ACTIONABLE     = 'actionable';
    public const INCOMPLETE     = 'incomplete';
    public const BLOCKED        = 'blocked';
    public const NOT_APPLICABLE = 'not applicable';
    public const FROZEN         = 'frozen';
    public const FAULTED        = 'faulted';

    public const ALL = [
        self::OK,
        self::ACTIONABLE,
        self::INCOMPLETE,
        self::BLOCKED,
        self::NOT_APPLICABLE,
        self::FROZEN,
        self::FAULTED,
    ];

    private function __construct()
    {
    }
}
