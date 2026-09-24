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

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;

\defined('_JEXEC') or die;

/**
 * Which report lines the list shows. It narrows rows and never touches the counts,
 * which describe the whole report.
 */
final class TargetFilter
{
    /**
     * @param string $status One of the seven statuses. Anything else, including an
     *                       empty string, filters nothing.
     * @param string $path   A substring of the target path, matched without regard
     *                       to case. Empty filters nothing.
     */
    public function __construct(
        private readonly string $status,
        private readonly string $path,
    ) {
    }

    /**
     * @param list<TargetReport> $targets
     *
     * @return list<TargetReport>
     */
    public function apply(array $targets): array
    {
        $status = \in_array($this->status, TargetStatus::ALL, true) ? $this->status : null;
        $path   = \trim($this->path);

        return \array_values(\array_filter(
            $targets,
            static fn (TargetReport $target): bool => ($status === null || $target->status() === $status)
                && ($path === '' || \stripos($target->targetPath(), $path) !== false)
        ));
    }
}
