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

final class RunPhase
{
    public const LIVE        = 'live';
    public const INTERRUPTED = 'interrupted';
    public const CLEAN       = 'clean';

    public const ALL = [
        self::LIVE,
        self::INTERRUPTED,
        self::CLEAN,
    ];

    private function __construct()
    {
    }
}
