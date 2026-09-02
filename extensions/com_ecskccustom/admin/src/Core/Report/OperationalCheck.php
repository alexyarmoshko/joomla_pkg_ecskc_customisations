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
 * Decision 22 of the brief puts these outside the rule vocabulary. A check is not a
 * target path: it carries no path, no hash and no backup, it is never planned,
 * applied, reverted, redone, frozen or unfrozen, and it never enters the per-target
 * state counts. Everything it can say about itself is here.
 *
 * Version 1 of the plan defines one check, `system_plugin_enabled`, and Phase 4
 * builds it together with the provider that feeds it. Phase 1 defines none, so the
 * section this belongs to is present and empty. The outcome vocabulary is
 * deliberately left as a string rather than fixed as constants here: `enabled`,
 * `disabled` and `unknown` belong to that one check, and a second check would answer
 * in words of its own. What every check shares is the three fields below, which is
 * the part the report shape has to settle now.
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
