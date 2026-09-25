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

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;

/**
 * What one run of the runner returns. Everything the operator is shown comes from here.
 */
final class RunReport
{
    /**
     * @param list<TargetReport> $targets
     * @param list<string>       $faults
     */
    private function __construct(
        private readonly array $targets,
        public readonly RunCounts $counts,
        public readonly OperationalChecks $checks,
        public readonly ReportedValue $interruption,
        private readonly array $faults,
    ) {
    }

    /**
     * @param list<TargetReport> $targets
     * @param list<string> $faults
     */
    public static function of(
        array $targets,
        OperationalChecks $checks,
        ReportedValue $interruption,
        array $faults = [],
    ): self {
        return new self(
            $targets,
            RunCounts::of($targets, $checks),
            $checks,
            $interruption,
            \array_values($faults)
        );
    }

    /**
     * A run that could not reach the site, and why.
     *
     * @param list<string> $faults
     */
    public static function faulted(
        array $faults,
        OperationalChecks $checks,
        ReportedValue $interruption,
    ): self {
        return new self([], RunCounts::of([], $checks), $checks, $interruption, \array_values($faults));
    }

    /**
     * @return list<TargetReport>
     */
    public function targets(): array
    {
        return $this->targets;
    }

    /**
     * @return list<string>
     */
    public function faults(): array
    {
        return $this->faults;
    }

    public function isFaulted(): bool
    {
        return $this->faults !== [];
    }

    /**
     * The targets in a notifiable status, in report order.
     *
     * @return list<TargetReport>
     */
    public function notifiableTargets(): array
    {
        return \array_values(\array_filter(
            $this->targets,
            static fn (TargetReport $target): bool => \in_array($target->status(), TargetStatus::NOTIFIABLE, true)
        ));
    }

    /**
     * Whether the installer plugin tells the operator about this report.
     *
     * A notifiable target, a notifiable operational check, or a run-level fault each
     * decide it alone, decisions 7, 22 and 51. The component and the installer plugin
     * both ask here, so they cannot disagree.
     */
    public function needsAttention(): bool
    {
        return $this->notifiableTargets() !== []
            || $this->checks->notifiable() !== []
            || $this->isFaulted();
    }

    public function wasInterrupted(): bool
    {
        return $this->interruption->isKnown();
    }
}
