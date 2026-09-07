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
 * The three states brief 5.6's table distinguishes, and nothing else.
 *
 * | State                  | Lock acquirable | Marker present |
 * |------------------------|-----------------|----------------|
 * | A run is in progress   | no              | yes or no      |
 * | A run died part-way    | yes             | yes            |
 * | Clean                  | yes             | no             |
 *
 * The lock and the marker want opposite lifetimes - a lock is released when its
 * holder dies, a file is not - and it is that difference that tells the second row
 * from the first. Neither artefact alone can.
 *
 * `live` covers the marker-absent window too. A run acquires the lock before it
 * publishes the marker and removes the marker before it releases the lock, so a
 * process that finds the lock held has nothing to learn from the marker, decision
 * 34. Clean is a free lock and no record of an unsettled run.
 */
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
