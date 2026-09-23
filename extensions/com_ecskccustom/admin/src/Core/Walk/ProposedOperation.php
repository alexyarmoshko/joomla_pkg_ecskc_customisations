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

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleAction;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleObject;

/**
 * One write the walk says would move a target forward. Nothing here performs it.
 *
 * `hashType` is the object's declared algorithm, carried because `payloadHash` is
 * meaningless without it: a digest is a claim under one algorithm, and an executor
 * that assumed sha256 would verify the wrong thing for a rule that declared
 * something else.
 */
final class ProposedOperation
{
    private function __construct(
        public readonly string $operation,
        public readonly string $targetPath,
        public readonly int $version,
        public readonly ?string $payloadSource,
        public readonly ?string $payloadHash,
        public readonly string $hashType,
        public readonly bool $backup,
        public readonly string $reason,
    ) {
    }

    public static function fromAction(RuleObject $object, RuleAction $action, string $reason): self
    {
        return new self(
            $action->operation,
            $object->targetPath,
            $object->version,
            $action->source,
            $action->sourceHash,
            $object->hashType,
            $action->isCopy() ? true : $action->backup,
            $reason
        );
    }

    public function isCopy(): bool
    {
        return $this->operation === RuleAction::COPY;
    }

    public function describe(): string
    {
        return \sprintf(
            '%s %s at version %d: %s',
            $this->operation,
            $this->targetPath,
            $this->version,
            $this->reason
        );
    }
}
