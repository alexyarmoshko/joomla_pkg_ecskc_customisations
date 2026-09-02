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
 * The named operational-checks section of the report, which may hold nothing.
 *
 * It is a section rather than a plain array so that "present and empty" is a state
 * the report can actually be in. Phase 1 defines no check, and an empty section says
 * the run looked and found nothing to report; a missing section would say the run
 * did not look. Phase 4 adds `system_plugin_enabled` by putting a check in here, and
 * nothing else about the report changes.
 *
 * It sits alongside the per-target states and never inside them. The run-level counts
 * report it separately, so the target counts stay exactly the counts of managed paths.
 */
final class OperationalChecks
{
    /**
     * @param list<OperationalCheck> $checks
     */
    private function __construct(private readonly array $checks)
    {
    }

    /**
     * The section a run performs no checks in. Present, and empty.
     */
    public static function none(): self
    {
        return new self([]);
    }

    public static function of(OperationalCheck ...$checks): self
    {
        return new self(\array_values($checks));
    }

    /**
     * @return list<OperationalCheck>
     */
    public function all(): array
    {
        return $this->checks;
    }

    /**
     * @return list<OperationalCheck>
     */
    public function notifiable(): array
    {
        return \array_values(
            \array_filter($this->checks, static fn (OperationalCheck $check): bool => $check->notifiable)
        );
    }

    public function isEmpty(): bool
    {
        return $this->checks === [];
    }

    public function count(): int
    {
        return \count($this->checks);
    }
}
