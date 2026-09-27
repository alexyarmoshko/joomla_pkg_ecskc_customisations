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

    /**
     * What a report shows for each result. The codes stay the code's own words.
     */
    private const LABELS = [
        self::AT_PAYLOAD       => 'already applied',
        self::WOULD_APPLY      => 'can be applied',
        self::DISABLED         => 'switched off',
        self::CONDITIONS_UNMET => 'not for this site',
        self::NOT_MATCHED      => 'does not match',
        self::NOT_APPLICABLE   => 'not allowed',
        self::FAULTED          => 'could not be checked',
    ];

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
            $this->reason . ', but a later version moved the file past it',
            false
        );
    }

    /**
     * The line as a report renders it.
     */
    public function describe(): string
    {
        return \sprintf(
            'version %d, %s: %s',
            $this->version,
            self::LABELS[$this->result] ?? $this->result,
            $this->reason
        );
    }
}
