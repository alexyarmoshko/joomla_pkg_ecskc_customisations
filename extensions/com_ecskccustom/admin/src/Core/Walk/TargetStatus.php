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
 * One status per path, never one per object. Objects are cumulative, so a current
 * file will not match the earlier steps it has already passed, and reporting each
 * object separately would make a healthy file look blocked. The status describes
 * the result of the whole walk; ObjectOutcome carries the object-by-object account
 * underneath it.
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

    /**
     * The statuses that need an operator after extension work, decisions 7 and 8.
     *
     * `frozen` is not here whatever the path would otherwise be: a frozen path reports
     * `frozen` as its status, and the operator froze it on purpose.
     */
    public const NOTIFIABLE = [
        self::ACTIONABLE,
        self::INCOMPLETE,
        self::BLOCKED,
        self::FAULTED,
    ];

    private function __construct()
    {
    }
}
