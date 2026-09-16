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

use Closure;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BackupOutcome;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventLog;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventRecord;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntent;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntentStore;

/**
 * The intent, mutation, event, and settlement sequence decision 32 requires.
 *
 * Freezer and TargetWriter each inspect their own path, then hand the mutation
 * to this class. The caller keeps the state it observes after a failed mutation,
 * the messages, the backup result, and when its checkpoint fires. The protocol
 * itself is one place, so a later change to the decision cannot leave the two
 * writers on different rules.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class MutationTransaction
{
    /**
     * @param Closure(string): void|null $checkpoint Called with a point name.
     */
    public function __construct(
        private readonly WriteIntentStore $intents,
        private readonly EventLog $log,
        private readonly ?Closure $checkpoint = null,
    ) {
    }

    /**
     * Publish the intent, mutate, append, and clear, or settle a failed mutation.
     *
     * @param Closure(): bool   $mutation  The create, move, unlink or rmdir.
     * @param Closure(): string $observed  Current state in the intent's vocabulary,
     *                                     or why it could not be read.
     * @param string            $untouched What a refused publish left unchanged.
     * @param string            $subject   What a pre-state mismatch names.
     */
    public function run(
        EventRecord $record,
        Closure $mutation,
        Closure $observed,
        string $failure,
        string $done,
        string $untouched,
        string $subject,
        ?BackupOutcome $backup = null,
    ): WriteOutcome {
        $id = (string) $record->correlationId;

        if (!$this->intents->publish(WriteIntent::of($id, $record->toPayload()))) {
            return WriteOutcome::refused(
                \sprintf('the write intent could not be published, so %s', $untouched),
                $backup
            );
        }

        $this->reach('intent-published');

        if (!$mutation()) {
            return $this->settleFailure($record, $observed, $failure, $subject, $backup);
        }

        $this->reach('mutated');

        if (!$this->log->append($record)) {
            return WriteOutcome::faulted($id, true, true, \sprintf(
                '%s, but its event could not be appended (%s), so the write intent stays for recovery',
                $done,
                (string) $this->log->failure()
            ), $backup);
        }

        $this->reach('event-committed');

        if (!$this->intents->remove()) {
            return WriteOutcome::faulted($id, true, true, \sprintf(
                '%s and its event is committed, but the write intent at %s could not be removed',
                $done,
                $this->intents->path()
            ), $backup);
        }

        return WriteOutcome::changed($id, $done, $backup);
    }

    /**
     * A mutation that reported failure after its intent was published, decision 32 case 2.
     *
     * @param Closure(): string $observed
     */
    private function settleFailure(
        EventRecord $record,
        Closure $observed,
        string $failure,
        string $subject,
        ?BackupOutcome $backup,
    ): WriteOutcome {
        $id      = (string) $record->correlationId;
        $current = $observed();

        if ($current !== $record->pre) {
            return WriteOutcome::faulted($id, false, true, \sprintf(
                '%s, and %s is now %s rather than %s, so the write intent stays for recovery',
                $failure,
                $subject,
                $current,
                (string) $record->pre
            ), $backup);
        }

        $fault = EventRecord::mutation(
            EventRecord::MUTATION_FAILED,
            (string) $record->operation,
            (string) $record->target,
            (string) $record->pre,
            (string) $record->post,
            $id,
            reason: $failure
        );

        if (!$this->log->append($fault)) {
            return WriteOutcome::faulted($id, false, true, \sprintf(
                '%s, and the fault could not be appended (%s), so the write intent stays for recovery',
                $failure,
                (string) $this->log->failure()
            ), $backup);
        }

        if (!$this->intents->remove()) {
            return WriteOutcome::faulted($id, false, true, \sprintf(
                '%s, and the fault is committed, but the write intent at %s could not be removed',
                $failure,
                $this->intents->path()
            ), $backup);
        }

        return WriteOutcome::faulted($id, false, false, $failure, $backup);
    }

    private function reach(string $point): void
    {
        if ($this->checkpoint !== null) {
            ($this->checkpoint)($point);
        }
    }
}
