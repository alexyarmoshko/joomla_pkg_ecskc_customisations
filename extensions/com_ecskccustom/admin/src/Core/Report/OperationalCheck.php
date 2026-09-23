<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report;

/**
 * One operational check: what was checked, how it came out, and whether to say so.
 *
 * `notifiable` is the check's own judgement rather than the reader's. The installer
 * plugin enqueues a message when a report holds a notifiable check even where every
 * target is `ok`, and it must not have to know which outcomes of which check mean
 * trouble.
 */
final class OperationalCheck
{
    public function __construct(
        public readonly string $name,
        public readonly string $outcome,
        public readonly bool $notifiable,
        public readonly string $reason,
    ) {
    }

    /**
     * The line as a report renders it.
     */
    public function describe(): string
    {
        return \sprintf('%s: %s, %s', $this->name, $this->outcome, $this->reason);
    }
}
