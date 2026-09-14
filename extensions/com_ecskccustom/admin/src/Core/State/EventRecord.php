<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\State;

use InvalidArgumentException;
use JsonException;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;

/**
 * One line of the event log: what happened, where, and who is said to have done it.
 *
 * Brief 5.6 needs the log to answer two questions the site's own files cannot. The
 * first is when the package last wrote at a path, which wants a time, a target and
 * an outcome. The second is durable mutation and recovery history: writes, removals,
 * reverts, redos, freeze and unfreeze changes, failures after intent publication,
 * and recovery outcomes. Checks, dry runs, ordinary skips and pre-intent faults stay
 * only in the current report.
 *
 * A record says the package, or recovery, found a target in one state
 * and left it in another. Decision 32 makes the pre-state, the post-state and the
 * correlation id mandatory there: recovery matches a leftover intent on the id, and
 * compares the current bytes with those two states to decide whether the mutation
 * happened. A record missing any of the three cannot be settled by anything.
 * The factory validates. A caller that mislabels a record is a bug, and a bug that
 * reached the log would be durable: the log is never truncated or rewritten.
 *
 * ## Attribution
 *
 * `package` means this process performed the mutation and saw it complete. `observed`
 * means recovery found the world in a state consistent with the mutation, decision
 * 32. The run lock does not exclude an outside writer, so byte equality after a crash
 * is evidence about the file and not a claim about who wrote it. The distinction is
 * on the record rather than inferred at read time, because only the process that
 * wrote the record knew which it was.
 *
 * ## Reading is more permissive than writing
 *
 * `fromPayload()` accepts a kind this version does not define and ignores keys it
 * does not know. A log written by a later release is then still history a reader can
 * use, rather than a file of damaged records. `EventLog` will not append that unknown
 * kind. A kind this version does define must
 * still carry that kind's full contract: a committed object labelled `write` with
 * no operation, states or correlation id is not a write, it is a damaged record.
 * Structure is still required, so a line that is merely valid JSON does not become
 * a record.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class EventRecord
{
    /**
     * The event kinds this version writes.
     *
     * `write` and `remove` are the two target mutations, `revert` and `redo` the two
     * steps along the backup and payload chain. `freeze` and `unfreeze` mutate the
     * marker beside a target's backups rather than the target.
     *
     * The last three are decision 32's unsettled mutations. `mutation-failed` is a
     * mutation that reported failure with the target still in its pre-state.
     * `intent-abandoned` is a leftover intent whose mutation recovery found had not
     * happened. `unresolved-intent` is one whose target matches neither state, which
     * is the only case that leaves the run marker behind.
     */
    public const WRITE             = 'write';
    public const REMOVE            = 'remove';
    public const REVERT            = 'revert';
    public const REDO              = 'redo';
    public const FREEZE            = 'freeze';
    public const UNFREEZE          = 'unfreeze';
    public const MUTATION_FAILED   = 'mutation-failed';
    public const INTENT_ABANDONED  = 'intent-abandoned';
    public const UNRESOLVED_INTENT = 'unresolved-intent';

    /**
     * The kinds that carry a correlation id, a pre-state and a post-state.
     *
     * Exactly the list in ticket 29: every write, freeze, unfreeze, revert, redo,
     * mutation-failed, intent-abandoned and unresolved-intent event. A removal is a
     * write of absence and belongs with them.
     */
    public const MUTATIONS = [
        self::WRITE,
        self::REMOVE,
        self::REVERT,
        self::REDO,
        self::FREEZE,
        self::UNFREEZE,
        self::MUTATION_FAILED,
        self::INTENT_ABANDONED,
        self::UNRESOLVED_INTENT,
    ];

    /**
     * The kinds that changed a target's own bytes, and nothing else.
     *
     * This is what `lastWriteFor()` answers from. A freeze or unfreeze mutates the
     * marker under `backup/`, not the target, so it is a mutation and is not a write
     * at the target. A `mutation-failed` record says the bytes did not change, and an
     * `intent-abandoned` record says recovery found that they had not. An
     * `unresolved-intent` record says nobody can tell what the bytes mean, which is
     * not a write this package can claim.
     */
    public const WRITES_AT_TARGET = [
        self::WRITE,
        self::REMOVE,
        self::REVERT,
        self::REDO,
    ];

    /** What a mutation's `operation` field may say. */
    public const COPY     = 'copy';
    public const REMOVAL  = 'remove';
    public const FREEZING = 'freeze';
    public const RELEASE  = 'unfreeze';

    public const OPERATIONS = [self::COPY, self::REMOVAL, self::FREEZING, self::RELEASE];

    /** Attribution: who the record says performed the mutation. */
    public const PACKAGE  = 'package';
    public const OBSERVED = 'observed';

    public const ATTRIBUTIONS = [self::PACKAGE, self::OBSERVED];

    /**
     * The two states a file target takes, beside a sha256 digest of its bytes.
     *
     * A freeze marker is `ABSENT` or `PRESENT`, where present is any shape
     * `FreezeMarkers::isFrozen()` treats as frozen, decision 32. Freeze-marker state
     * is never a digest, which is why neither-state recovery cannot arise for one.
     */
    public const ABSENT  = 'absent';
    public const PRESENT = 'present';

    /**
     * The timestamp shape, which is RunMarker's for the same reasons.
     *
     * Sortable, unambiguous about its zone, and one token wide. The two are separate
     * constants on purpose: the marker's is a whole file format that a later release
     * could not change without breaking the marker it has to read, and this is one
     * field of a JSON object.
     */
    public const TIMESTAMP = 'Y-m-d\TH:i:s\Z';

    /** A kind or operation a later release may have added, kept legible. */
    private const TOKEN = '/\A[a-z][a-z0-9-]{0,31}\z/';

    /** `absent`, `present`, or a sha256 digest. */
    private const STATE = '/\A(absent|present|[0-9a-f]{64})\z/';

    private function __construct(
        public readonly string $kind,
        public readonly string $at,
        public readonly string $attribution,
        public readonly ?string $target,
        public readonly ?string $operation,
        public readonly ?string $pre,
        public readonly ?string $post,
        public readonly ?string $observed,
        public readonly ?string $correlationId,
        public readonly ?string $reason,
    ) {
    }

    /**
     * A record of one mutation, attributed and correlated.
     *
     * @param string      $kind        One of MUTATIONS.
     * @param string      $operation   One of OPERATIONS.
     * @param string      $target      The canonical root-relative target path.
     * @param string      $pre         The state before: ABSENT, PRESENT or a digest.
     * @param string      $post        The state the mutation was to leave behind.
     * @param string      $id          The intent's correlation id, which recovery
     *                                 matches on and which is the only thing that
     *                                 tells two identical transitions apart.
     * @param string      $attribution PACKAGE for a mutation this process completed,
     *                                 OBSERVED for one recovery inferred.
     * @param string|null $observed    What recovery actually found, for an
     *                                 unresolved intent that matches neither state.
     * @param string|null $reason      Why, for a failure or a recovery.
     * @param int|null    $at          Unix timestamp, or null for the current time.
     *                                 A caller passes one so a test can fix it.
     *
     * @throws InvalidArgumentException On a record no reader could settle.
     */
    public static function mutation(
        string $kind,
        string $operation,
        string $target,
        string $pre,
        string $post,
        string $id,
        string $attribution = self::PACKAGE,
        ?string $observed = null,
        ?string $reason = null,
        ?int $at = null,
    ): self {
        self::require(\in_array($kind, self::MUTATIONS, true), 'mutation kind', $kind);
        self::require(\in_array($operation, self::OPERATIONS, true), 'operation', $operation);
        self::require(PackagePaths::isCanonicalTargetPath($target), 'target path', $target);
        self::require(\preg_match(self::STATE, $pre) === 1, 'pre-state', $pre);
        self::require(\preg_match(self::STATE, $post) === 1, 'post-state', $post);
        self::require(
            $observed === null || \preg_match(self::STATE, $observed) === 1,
            'observed state',
            $observed
        );
        self::require(\preg_match(WriteIntent::ID, $id) === 1, 'correlation id', $id);
        self::require(
            \in_array($attribution, self::ATTRIBUTIONS, true),
            'attribution',
            $attribution
        );
        self::require($reason === null || $reason !== '', 'reason', $reason);

        return new self(
            $kind,
            \gmdate(self::TIMESTAMP, $at ?? \time()),
            $attribution,
            $target,
            $operation,
            $pre,
            $post,
            $observed,
            $id,
            $reason
        );
    }

    /**
     * Is this a write at the target itself, rather than at a marker beside it?
     */
    public function isWriteAtTarget(): bool
    {
        return \in_array($this->kind, self::WRITES_AT_TARGET, true);
    }

    /** Is this one of the mutation and recovery kinds this version may append? */
    public function isMutation(): bool
    {
        return \in_array($this->kind, self::MUTATIONS, true);
    }

    /**
     * The record as the log and the write intent carry it.
     *
     * Keys are written in a fixed order and absent keys are left out, so two records
     * of the same event encode to the same bytes. The intent stores this payload
     * whole, decision 32, which is what lets recovery append the event the crashed
     * run did not: the same bytes, re-attributed as observed.
     *
     * @return array<string, string>
     */
    public function toPayload(): array
    {
        $payload = [
            'event' => $this->kind,
            'at'    => $this->at,
            'by'    => $this->attribution,
        ];

        foreach (
            [
                'target'    => $this->target,
                'operation' => $this->operation,
                'pre'       => $this->pre,
                'post'      => $this->post,
                'observed'  => $this->observed,
                'id'        => $this->correlationId,
                'reason'    => $this->reason,
            ] as $key => $value
        ) {
            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * The committed line's JSON half: one UTF-8 object, no line break in it.
     *
     * Slashes and non-ASCII are left unescaped so a target path and a reason read as
     * themselves in a file an operator opens. `json_encode` escapes the bytes that
     * would break the record, a line feed, a tab or a quote, whatever the flags.
     *
     * @throws JsonException On a payload that will not encode, which cannot come from
     *                       the factory above and would be a caller building a
     *                       record by hand. It surfaces before anything is appended.
     */
    public function toJson(): string
    {
        return \json_encode(
            $this->toPayload(),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * The same record with recovery's attribution and nothing else changed.
     *
     * Decision 32: a recovered completion is attributed as observed, never as a
     * package write. The timestamp stays the one the crashed run recorded, because
     * that is when the mutation happened; recovery's own clock would date the event
     * to the recovery instead.
     */
    public function asObserved(): self
    {
        if ($this->attribution === self::OBSERVED) {
            return $this;
        }

        return new self(
            $this->kind,
            $this->at,
            self::OBSERVED,
            $this->target,
            $this->operation,
            $this->pre,
            $this->post,
            $this->observed,
            $this->correlationId,
            $this->reason
        );
    }

    /**
     * Read a payload back, or null when it is not a record.
     *
     * Structure is checked, and a kind this version writes must still hold the
     * contract its factory would have required. Vocabulary of unknown kinds is not.
     * An unknown kind round-trips so that a log written by a later release stays
     * readable, and a kind this version does not know simply never answers
     * `isWriteAtTarget()`. A known `write` that omits its mutation fields is not
     * that: it would count as the latest write while carrying none of the states
     * recovery needs. Unknown keys are dropped rather than refused, for the same
     * forward-compatibility reason unknown kinds are kept.
     *
     * @param array<array-key, mixed> $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        $kind        = self::field($payload, 'event');
        $at          = self::field($payload, 'at');
        $attribution = self::field($payload, 'by');

        if ($kind === null
            || $at === null
            || $attribution === null
            || \preg_match(self::TOKEN, $kind) !== 1
            || \preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $at) !== 1
            || !\in_array($attribution, self::ATTRIBUTIONS, true)) {
            return null;
        }

        foreach (['target', 'operation', 'pre', 'post', 'observed', 'id', 'reason'] as $key) {
            if (\array_key_exists($key, $payload) && self::field($payload, $key) === null) {
                return null;
            }
        }

        $target    = self::field($payload, 'target');
        $operation = self::field($payload, 'operation');
        $pre       = self::field($payload, 'pre');
        $post      = self::field($payload, 'post');
        $observed  = self::field($payload, 'observed');
        $id        = self::field($payload, 'id');
        $reason    = self::field($payload, 'reason');

        if ($target !== null && !PackagePaths::isCanonicalTargetPath($target)) {
            return null;
        }

        if (\in_array($kind, self::MUTATIONS, true)
            && !self::isReadableMutation($target, $operation, $pre, $post, $observed, $id)
        ) {
            return null;
        }

        return new self(
            $kind,
            $at,
            $attribution,
            $target,
            $operation,
            $pre,
            $post,
            $observed,
            $id,
            $reason
        );
    }

    /**
     * The record as the report shows it where it answers "last package write".
     *
     * The time first, because that is the question. Then what happened, who it is
     * attributed to, and the transition, because a target that went from the vendor's
     * original to a payload and one that was created where nothing existed are
     * different histories and the hashes are what separate them.
     */
    public function describe(): string
    {
        $description = \sprintf('%s %s (%s)', $this->at, $this->kind, $this->attribution);

        if ($this->pre !== null && $this->post !== null) {
            $description .= \sprintf(', %s to %s', $this->pre, $this->post);
        }

        return $description;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private static function field(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The mutation factory's contract, used on read so a known kind cannot arrive
     * half-built. Unknown future kinds skip this and stay records.
     */
    private static function isReadableMutation(
        ?string $target,
        ?string $operation,
        ?string $pre,
        ?string $post,
        ?string $observed,
        ?string $id,
    ): bool {
        return $target !== null
            && $operation !== null && \in_array($operation, self::OPERATIONS, true)
            && $pre !== null && \preg_match(self::STATE, $pre) === 1
            && $post !== null && \preg_match(self::STATE, $post) === 1
            && $id !== null && \preg_match(WriteIntent::ID, $id) === 1
            && ($observed === null || \preg_match(self::STATE, $observed) === 1);
    }

    private static function require(bool $held, string $what, ?string $value): void
    {
        if ($held) {
            return;
        }

        throw new InvalidArgumentException(
            \sprintf('%s is not a usable %s for an event record', \var_export($value, true), $what)
        );
    }
}
