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
 * The walk's state for one target.
 *
 * ObjectWalker creates one per target, its helpers read and write the public fields
 * directly, and it is discarded when the target's state is built. `$state` is the
 * simulated target: it starts as the hash, or the absence, observed on disk, and
 * becomes the state each object the walk would apply leaves behind, so a later
 * object is judged against what the earlier ones would leave.
 *
 * An object's predecessor is an earlier object that writes the hash it expects.
 * `firstWriter` records, for each payload hash, the first position that writes it,
 * so an object's own payload never counts as its predecessor, and a hash written
 * again later still counts from its first position.
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
     * Each payload hash, lower case, and the position of the first object that writes it.
     *
     * @var array<string, int>
     */
    private readonly array $firstWriter;

    /**
     * @param list<RuleObject> $objects The objects at this target, in load order.
     */
    public function __construct(HashResult $actual, public readonly array $objects)
    {
        $this->state        = $actual;
        $this->packageOwned = $objects[0]->create;

        $first = [];

        foreach ($objects as $index => $object) {
            foreach ($object->payloadHashes() as $hash) {
                $first[\strtolower($hash)] ??= $index;
            }
        }

        $this->firstWriter = $first;
    }

    /**
     * Whether an object before position `$index` writes `$hash`.
     */
    public function isWrittenBefore(string $hash, int $index): bool
    {
        $first = $this->firstWriter[\strtolower($hash)] ?? null;

        return $first !== null && $first < $index;
    }
}
