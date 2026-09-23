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
 * One file at one revision, as the rule set declares it.
 *
 * `destPathFileName` may be written relative to the enclosing root or absolute
 * from the web root, and both are legal syntax. `targetPath` is the canonical
 * form both spellings reduce to: root-relative, no leading slash. `absoluteForm`
 * records which spelling produced it, because naming one target both ways is an
 * authoring fault the merged set reports rather than silently resolves.
 */
final class RuleObject
{
    /**
     * @param list<RuleAction> $actions
     */
    public function __construct(
        public readonly string $sourceFile,
        public readonly string $hashType,
        public readonly string $rootPath,
        public readonly string $destPathFileName,
        public readonly string $targetPath,
        public readonly bool $absoluteForm,
        public readonly int $version,
        public readonly bool $create,
        public readonly bool $replace,
        public readonly bool $enable,
        public readonly ?string $expectedHash,
        public readonly array $actions,
    ) {
    }

    /**
     * How this object is named in a violation, so every message says which file
     * and which object it came from.
     */
    public function label(): string
    {
        return \sprintf('%s: object %s version %d', $this->sourceFile, $this->targetPath, $this->version);
    }

    /**
     * The payloads this object names, in action order.
     *
     * @return list<string>
     */
    public function payloadSources(): array
    {
        $sources = [];

        foreach ($this->actions as $action) {
            if ($action->source !== null) {
                $sources[] = $action->source;
            }
        }

        return $sources;
    }

    /**
     * The hashes of the payloads this object writes.
     *
     * These are the states a later object at the same path may expect: an object
     * with several actions writes different payloads under different conditions,
     * so a chain may link to any one of them.
     *
     * @return list<string>
     */
    public function payloadHashes(): array
    {
        $hashes = [];

        foreach ($this->actions as $action) {
            if ($action->sourceHash !== null) {
                $hashes[] = $action->sourceHash;
            }
        }

        return $hashes;
    }
}
