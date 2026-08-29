<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule;

/**
 * One action of an object: exactly one operation, either a copy or a removal.
 *
 * Decision 11 fixes one operation per action, and the schema enforces it with a
 * choice of exactly one element, so nothing here has to represent an action that
 * carries none or several.
 *
 * Conditions are deliberately absent. Ticket 13 owns the condition vocabulary and
 * its evaluation; merged-set validation needs to know which payload an action
 * names and nothing more, and the closed attribute vocabulary is already settled
 * by the schema's enumeration.
 */
final class RuleAction
{
    public const COPY   = 'copy';
    public const REMOVE = 'remove';

    private function __construct(
        public readonly string $operation,
        public readonly ?string $source,
        public readonly ?string $sourceHash,
        public readonly bool $backup,
    ) {
    }

    public static function copy(string $source, string $sourceHash): self
    {
        return new self(self::COPY, $source, $sourceHash, false);
    }

    public static function remove(bool $backup): self
    {
        return new self(self::REMOVE, null, null, $backup);
    }

    public function isCopy(): bool
    {
        return $this->operation === self::COPY;
    }
}
