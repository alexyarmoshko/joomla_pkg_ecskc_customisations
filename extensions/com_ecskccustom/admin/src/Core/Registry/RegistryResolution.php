<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry;

/**
 * The Plugins screen's rows, or the page-level fault that replaces them.
 *
 * Never both, decision 53: a list built from a failed read would report extensions as
 * absent that the package has not looked for.
 */
final class RegistryResolution
{
    /**
     * @param list<RegistryRow> $rows   Empty when there is a fault.
     * @param list<string>      $faults Every reason there is no list; empty when there is one.
     */
    private function __construct(
        public readonly array $rows,
        public readonly array $faults,
    ) {
    }

    /**
     * @param list<RegistryRow> $rows
     */
    public static function rows(array $rows): self
    {
        return new self($rows, []);
    }

    /**
     * @param non-empty-list<string> $faults
     */
    public static function fault(array $faults): self
    {
        return new self([], $faults);
    }

    public function isValid(): bool
    {
        return $this->faults === [];
    }
}
