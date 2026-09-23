<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\HashAlgorithm;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\HashResult;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\Hasher;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path\PathPolicy;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\PlannedOperation;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\ReportedValue;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleAction;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BoundBackupDirectory;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ProposedOperation;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetState;

final class WritePlanner
{
    private const NO_BACKUP_ON_CREATE = 'the file does not exist yet, so this operation '
        . 'displaces nothing and writes no backup';

    private const NO_BACKUP_REQUESTED = 'the rule asks for no copy of the removed bytes';

    private const NOTHING_DISPLACED = 'nothing is displaced: the target does not exist';

    private const UNREADABLE_CURRENT = 'what is at %s now could not be read, so nothing may be '
        . 'displaced there: %s';

    private readonly Hasher $hasher;

    public function __construct(
        private readonly PackagePaths $paths,
        private readonly PathPolicy $policy,
    ) {
        $this->hasher = new Hasher();
    }

    /**
     * The plan for one target: one line per operation the walk proposed.
     *
     * @return list<PlannedOperation>
     */
    public function plan(TargetState $state): array
    {
        $plan = [];

        $current = $state->actual;

        foreach ($state->operations() as $operation) {
            $plan[]  = $this->line($operation, $current);
            $current = $operation->isCopy()
                ? HashResult::digest((string) $operation->payloadHash)
                : HashResult::absent(self::NOTHING_DISPLACED);
        }

        return $plan;
    }

    /**
     * One operation, checked against the tree as it is at this moment.
     *
     * @param HashResult $displaced What this operation would replace: a digest, the
     *                              determined absence of a target, or the reason the
     *                              current bytes could not be read. The three are not
     *                              one nullable field, because an operation that
     *                              reported an unreadable target as an absent one
     *                              would say it displaces nothing and name no backup,
     *                              and the write layer would then refuse it anyway.
     */
    public function line(ProposedOperation $operation, HashResult $displaced): PlannedOperation
    {
        $refusals = [];
        $decision = $this->policy->decide($operation->targetPath);

        if (!$decision->allowed) {

            return new PlannedOperation(
                $operation,
                self::displacedValue($displaced),
                ReportedValue::none('the path is denied, so no backup destination is computed'),
                [(string) $decision->reason],
                ReportedValue::none(PlannedOperation::NOT_EXECUTED)
            );
        }

        $resolved = (string) $decision->path;

        foreach (
            [
                self::currentRefusal($operation, $displaced),
                $this->payloadRefusal($operation),
                $this->permissionRefusal($operation, $resolved, $displaced),
            ] as $refusal
        ) {
            if ($refusal !== null) {
                $refusals[] = $refusal;
            }
        }

        $backup = $this->backupDestination($operation, $displaced);

        if ($backup->isKnown()) {

            $refusal = BoundBackupDirectory::publishRefusal($this->paths, (string) $backup->value);

            if ($refusal !== null) {
                $refusals[] = $refusal;
            }
        }

        return new PlannedOperation(
            $operation,
            self::displacedValue($displaced),
            $backup,
            $refusals,
            ReportedValue::none(PlannedOperation::NOT_EXECUTED)
        );
    }

    /**
     * Where the displaced bytes would go, or why nothing goes anywhere.
     */
    public function backupDestination(ProposedOperation $operation, HashResult $displaced): ReportedValue
    {
        if (!$displaced->isAvailable()) {

            if (!$displaced->isAbsent()) {
                return ReportedValue::unavailable(\sprintf(
                    'what is at %s now could not be read, so no backup destination is computed',
                    $operation->targetPath
                ));
            }

            return ReportedValue::none(
                $operation->isCopy() ? self::NO_BACKUP_ON_CREATE : self::NOTHING_DISPLACED
            );
        }

        if (!$operation->backup) {
            return ReportedValue::none(self::NO_BACKUP_REQUESTED);
        }

        return ReportedValue::known($this->paths->backupFile(
            $operation->targetPath,
            $operation->version,
            (string) $displaced->digest
        ));
    }

    /**
     * Can the package see what it would be displacing at all?
     *
     * An unreadable target is not an absent one and is not an empty one. The move
     * would be refused at the write, so the plan says so here rather than describing
     * a create over a file nobody can read.
     */
    private static function currentRefusal(ProposedOperation $operation, HashResult $displaced): ?string
    {
        if ($displaced->isAvailable() || $displaced->isAbsent()) {
            return null;
        }

        return \sprintf(
            self::UNREADABLE_CURRENT,
            $operation->targetPath,
            (string) $displaced->reason
        );
    }

    /**
     * Does the payload exist, and does it hash to what the rule declared?
     *
     * The hash is checked here rather than only at the move because a dry run has to
     * tell an operator that a payload has been damaged before they run an apply that
     * would have stopped half-way through the chain. `TargetWriter` verifies the
     * staged copy again, and that check is the one the write depends on.
     */
    private function payloadRefusal(ProposedOperation $operation): ?string
    {
        if (!$operation->isCopy()) {
            return null;
        }

        if (!HashAlgorithm::isSupported($operation->hashType)) {
            return HashAlgorithm::unsupportedReason($operation->hashType);
        }

        $payload = $this->paths->payload() . '/' . (string) $operation->payloadSource;
        \clearstatcache(true, $payload);

        if (\is_link($payload) || !\is_file($payload) || !\is_readable($payload)) {
            return \sprintf('the payload %s is not a readable regular file', $payload);
        }

        $hash = $this->hasher->hashFile($operation->hashType, $payload);

        if (!$hash->isAvailable()) {
            return \sprintf('the payload %s could not be hashed: %s', $payload, (string) $hash->reason);
        }

        if (!$hash->matches((string) $operation->payloadHash)) {
            return \sprintf(
                'the payload %s hashes to %s and the rule declares %s',
                $payload,
                (string) $hash->digest,
                (string) $operation->payloadHash
            );
        }

        return null;
    }

    /**
     * Can the package write here at all?
     *
     * The move is a rename into the target's parent directory, so the parent is what
     * has to be writable - a read-only file in a writable directory can still be
     * replaced, and a writable file in a read-only directory cannot. The target's own
     * mode is therefore never asked: a vendor file shipped `0444` inside a writable
     * directory is a valid atomic replacement, and `TargetWriter` preserves the mode
     * it finds. What is asked of the target is its shape, because a directory or a
     * symbolic link is not something an atomic move may land on.
     */
    private function permissionRefusal(
        ProposedOperation $operation,
        string $resolved,
        HashResult $displaced,
    ): ?string {
        \clearstatcache(true, $resolved);

        if (\is_link($resolved)) {
            return \sprintf('%s is a symbolic link, which is never a managed target', $resolved);
        }

        if (\is_dir($resolved)) {
            return \sprintf('%s is a directory, so no payload can be moved onto it', $resolved);
        }

        if ($operation->operation === RuleAction::REMOVE && $displaced->isAbsent()) {

            return null;
        }

        $parent = \dirname($resolved);
        \clearstatcache(true, $parent);

        if (!\is_dir($parent)) {
            return \sprintf('%s is not a directory, so %s cannot be written', $parent, $resolved);
        }

        if (!\is_writable($parent)) {
            return \sprintf('%s is not writable, so %s cannot be written', $parent, $resolved);
        }

        return null;
    }

    private static function displacedValue(HashResult $displaced): ReportedValue
    {
        if ($displaced->isAvailable()) {
            return ReportedValue::known((string) $displaced->digest);
        }

        return $displaced->isAbsent()
            ? ReportedValue::none(self::NOTHING_DISPLACED)
            : ReportedValue::unavailable((string) $displaced->reason);
    }
}
