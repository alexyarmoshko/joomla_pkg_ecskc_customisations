<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\HashResult;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleObject;

/**
 * Mutable state carried through a single walkTarget pass.
 *
 * Exists only to let the extracted walk helpers share state without passing
 * ten parameters back and forth.  Every field is public so the helpers read
 * and write it directly — the context is created, used and discarded inside
 * one walkTarget call.
 *
 * @internal Not part of the public API; consumed only by ObjectWalker.
 */
final class WalkContext
{
    /** The simulated hash state, starting as the file's own bytes. */
    public HashResult $state;

    /** @var list<ObjectOutcome> */
    public array $outcomes = [];

    /** @var list<ProposedOperation> */
    public array $operations = [];

    /** The hash the walk was measuring against where it settled. */
    public ?HashResult $expected = null;

    /** The last object the walk settled at. */
    public ?RuleObject $settled = null;

    public bool $refusedByPermit = false;
    public bool $sawNotMatched   = false;

    public ?string $skipQualifier = null;
    public ?string $skipReason    = null;

    /** @var list<int> Indexes into $outcomes of pending gates. */
    public array $pendingGates = [];

    /** Whether the first object has create=true (package-owned chain). */
    public readonly bool $packageOwned;

    /**
     * Payload hashes written by earlier objects, per position.
     *
     * @var list<array<string, true>>
     */
    public readonly array $writtenBefore;

    /**
     * The original object list, kept for recordConditionsUnmet which needs
     * the full list to test gating.
     *
     * @var list<RuleObject>
     */
    public readonly array $objects;

    /**
     * @param list<RuleObject> $objects
     */
    public function __construct(HashResult $actual, array $objects)
    {
        $this->state        = $actual;
        $this->objects      = $objects;
        $this->packageOwned = $objects[0]->create;

        // Pre-compute per-position payload hashes so an object can never
        // link to itself.
        $accumulated = [];
        $before      = [];

        foreach ($objects as $object) {
            $before[] = $accumulated;

            foreach ($object->payloadHashes() as $hash) {
                $accumulated[\strtolower($hash)] = true;
            }
        }

        $this->writtenBefore = $before;
    }

    /**
     * The index of the given object in the original list.
     *
     * Used by recordConditionsUnmet which receives the object but not its
     * position.
     */
    public function indexOf(RuleObject $object): int
    {
        foreach ($this->objects as $index => $candidate) {
            if ($candidate === $object) {
                return $index;
            }
        }

        // @codeCoverageIgnoreStart
        throw new \LogicException('Object not found in the walk context');
        // @codeCoverageIgnoreEnd
    }
}
