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

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleObject;

/**
 * What the walk decided about one object, and why.
 *
 * Brief 5.3: the state report gives one status per path, and the outcome report may
 * narrate per object. "Applied v1, skipped v2 (disabled), v3 not matched" is an
 * account of what happened, where a single line would hide the reason the walk
 * stopped. These are those lines.
 *
 * `gatesLaterObjects` is the one field that is not narration. A skip only strands a
 * site when the walk could have advanced through the object and something follows
 * it at the same path, and that is what separates `incomplete` from the ordinary
 * cases: a disabled last object turns a customisation off, and a vendor alternative
 * for a build this site does not run was never going to match. The walk records the
 * judgement here rather than making a reader infer it from the object list.
 */
final class ObjectOutcome
{
    /** The target already holds what this object writes, so the walk advanced without work. */
    public const AT_PAYLOAD = 'at-payload';

    /** This object can move the target forward, and proposed the operation to do it. */
    public const WOULD_APPLY = 'would-apply';

    /** enable="false". */
    public const DISABLED = 'disabled';

    /** No action's conditions hold in this environment. */
    public const CONDITIONS_UNMET = 'conditions-unmet';

    /** The target matches neither this object's expected original nor its payload. */
    public const NOT_MATCHED = 'not-matched';

    /** The object's own permissions refuse the write. */
    public const NOT_APPLICABLE = 'not-applicable';

    /** A condition could not be judged, so the whole target halts here. */
    public const FAULTED = 'faulted';

    private function __construct(
        public readonly string $sourceFile,
        public readonly int $version,
        public readonly string $result,
        public readonly string $reason,
        public readonly bool $gatesLaterObjects,
    ) {
    }

    public static function for(
        RuleObject $object,
        string $result,
        string $reason,
        bool $gatesLaterObjects = false,
    ): self {
        return new self($object->sourceFile, $object->version, $result, $reason, $gatesLaterObjects);
    }

    /**
     * The same line, with the gate lifted.
     *
     * A skip holds the walk short of the head only until something gets past it. When
     * a later object does, this line has to stop saying otherwise, or the per-object
     * account contradicts the status above it.
     */
    public function released(): self
    {
        return new self(
            $this->sourceFile,
            $this->version,
            $this->result,
            $this->reason . ', though a later object at this target carried the walk past it',
            false
        );
    }

    /**
     * The line as a report renders it.
     */
    public function describe(): string
    {
        return \sprintf('version %d: %s, %s', $this->version, $this->result, $this->reason);
    }
}
