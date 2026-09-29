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
 * What loading the registry produced: its entries, or the load fault.
 *
 * Never both. A registry that fails the schema has no entries, because a file whose
 * shape cannot be trusted cannot be read in part: listing the entries that happened
 * to parse would present a partial list as the whole one.
 */
final class RegistryLoadResult
{
    /**
     * @param list<RegistryEntry> $entries    Empty when the registry did not load.
     * @param list<string>        $violations Every reason it did not load; empty when it did.
     */
    private function __construct(
        public readonly array $entries,
        public readonly array $violations,
    ) {
    }

    /**
     * @param list<RegistryEntry> $entries
     */
    public static function loaded(array $entries): self
    {
        return new self($entries, []);
    }

    /**
     * @param non-empty-list<string> $violations
     */
    public static function fault(array $violations): self
    {
        return new self([], $violations);
    }

    public function isValid(): bool
    {
        return $this->violations === [];
    }
}
