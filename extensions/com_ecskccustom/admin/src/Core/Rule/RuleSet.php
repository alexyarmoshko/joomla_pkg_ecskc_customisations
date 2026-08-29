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
 * Every object from every rule file, merged into one sequence in load order.
 *
 * The merge is what makes validation possible at all: document order across the
 * whole set is what the forward walk depends on, so a path split across two rule
 * files can only be judged once they are joined. Nothing here validates - it holds
 * the merged sequence and groups it by target path.
 */
final class RuleSet
{
    /**
     * @param list<RuleObject>      $objects   In load order: files byte-order
     *                                         alphabetical, objects in document order
     *                                         within each file.
     * @param array<string, string> $hashTypes The algorithm each rule file declared,
     *                                         keyed by file name.
     */
    public function __construct(
        private readonly array $objects,
        private readonly array $hashTypes,
    ) {
    }

    /**
     * @return list<RuleObject>
     */
    public function objects(): array
    {
        return $this->objects;
    }

    /**
     * What each rule file declared as its hash algorithm.
     *
     * Held per file rather than read off the objects. A patch element may carry no
     * objects at all - the schema allows it - and such a file still declares an
     * algorithm that has to be judged. Taken from the objects, an empty rule file
     * declaring an unsupported algorithm would pass unexamined.
     *
     * @return array<string, string>
     */
    public function hashTypes(): array
    {
        return $this->hashTypes;
    }

    public function isEmpty(): bool
    {
        return $this->objects === [];
    }

    /**
     * The objects at each target path, each list still in load order.
     *
     * @return array<string, list<RuleObject>>
     */
    public function byTargetPath(): array
    {
        $grouped = [];

        foreach ($this->objects as $object) {
            $grouped[$object->targetPath][] = $object;
        }

        return $grouped;
    }
}
