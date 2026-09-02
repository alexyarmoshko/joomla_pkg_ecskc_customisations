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
 * ## Why this carries no resolved path
 *
 * Security review v1 (2026-08-29): PathPolicy::decide() resolves a path, and the
 * write happens later. Anything that can swap a path under the site root in between
 * defeats a decision taken earlier. PHP has no openat and no O_NOFOLLOW, so the
 * window cannot be closed, only narrowed, and staging followed by an atomic move
 * narrows it to the move itself - provided the decision is taken there rather than
 * carried forward from the report.
 *
 * So this object carries the canonical root-relative `targetPath` and no absolute
 * one. An executor has to call path policy again immediately before it moves, and
 * cannot accidentally trust a resolution that is by then minutes old. The walk's own
 * resolution decided what to hash, which is a read and answers only for the moment
 * it happened.
 *
 * The payload source is likewise relative to the installed `payload/` directory, as
 * the rule wrote it. `payloadHash` is what the rule declared, not a digest of the
 * bytes on disk. Verifying the payload against it is a write precondition that
 * belongs to the dry run in Phase 2, and a check that only reports has no business
 * hashing files it will not write.
 *
 * `backup` reads as "preserve whatever this displaces". A copy always does, per
 * decision 4, and the schema gives it no attribute to say otherwise; only a removal
 * can be told to keep no copy. A copy that creates a file where none existed
 * displaces nothing and so writes no backup, which the executor sees for itself
 * without being told here.
 */
final class ProposedOperation
{
    private function __construct(
        public readonly string $operation,
        public readonly string $targetPath,
        public readonly int $version,
        public readonly ?string $payloadSource,
        public readonly ?string $payloadHash,
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
