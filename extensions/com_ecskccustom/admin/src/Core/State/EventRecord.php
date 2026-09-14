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
 * an outcome. The second is the events that leave no other trace at all: a file
 * created where none existed, a removal told to keep no copy, a target reported
 * rather than written, a dry run, and every freeze and unfreeze, since unfreezing
 * deletes its own evidence.
 *
 * ## Two kinds of record, and why the split is in the factories
 *
 * A **mutation** record says the package, or recovery, found a target in one state
 * and left it in another. Decision 32 makes the pre-state, the post-state and the
 * correlation id mandatory there: recovery matches a leftover intent on the id, and
 * compares the current bytes with those two states to decide whether the mutation
 * happened. A record missing any of the three cannot be settled by anything.
 *
 * A **report-only** record says a run looked and did not write: a check, a dry run, a
 * skip, or a fault. It carries no correlation id because no mutation was attempted,
 * and it may leave a state out, because a target whose bytes could not be read has no
 * state to record and the reason says why.
 *
 * Both factories validate. A caller that mislabels a record is a bug, and a bug that
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
 * use, rather than a file of damaged records. It stays strict about structure, so a
 * line that is merely valid JSON does not become a record.
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
     * marker beside a target's backups rather than the target. `report` is a target
     * reported and not written, `dry-run` a previewed operation, `skip` an operation
     * the run declined, including a freeze of an already-frozen path. `fault` is any
     * failure that is not an event-log I/O failure. That one is exempt, decision 33,
     * because a log that cannot be appended to cannot record its own failure.
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
    public const REPORT            = 'report';
    public const DRY_RUN           = 'dry-run';
    public const SKIP              = 'skip';
    public const FAULT             = 'fault';
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
     * The kinds a run appends without touching a managed file.
     */
    public const REPORT_ONLY = [
        self::REPORT,
        self::DRY_RUN,
        self::SKIP,
        self::FAULT,
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
     * A record of a run that looked and wrote nothing.
     *
     * The target is optional because a run that cannot load its rule set has no
     * target to name, and a fault there is still an event. The state is optional
     * because a target whose bytes could not be read has none to record; the reason
     * is what an operator reads in that case, so it is required.
     *
     * @param string      $kind      One of REPORT_ONLY.
     * @param string      $reason    Why this was reported rather than written.
     * @param string|null $target    The canonical root-relative target path, if one.
     * @param string|null $state     The state the run observed, if it could read one.
     * @param string|null $operation The operation a dry run previewed, if one.
     * @param int|null    $at        Unix timestamp, or null for the current time.
     *
     * @throws InvalidArgumentException On a record that claims to be a mutation.
     */
    public static function reportOnly(
        string $kind,
        string $reason,
        ?string $target = null,
        ?string $state = null,
        ?string $operation = null,
        ?int $at = null,
    ): self {
        self::require(\in_array($kind, self::REPORT_ONLY, true), 'report-only kind', $kind);
        self::require($reason !== '', 'reason', $reason);
        self::require(
            $target === null || PackagePaths::isCanonicalTargetPath($target),
            'target path',
            $target
        );
        self::require($state === null || \preg_match(self::STATE, $state) === 1, 'state', $state);
        self::require(
            $operation === null || \in_array($operation, self::OPERATIONS, true),
            'operation',
            $operation
        );

        // Nothing was written, so the state before and the state after are the one
        // state the run saw. Recording it twice says that in the same field a
        // mutation uses, so a reader needs no second rule for report-only lines.
        return new self(
            $kind,
            \gmdate(self::TIMESTAMP, $at ?? \time()),
            self::PACKAGE,
            $target,
            $operation,
            $state,
            $state,
            null,
            null,
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
     *                       the factories above and would be a caller building a
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
     * Structure is checked; vocabulary is not. An unknown kind round-trips so that a
     * log written by a later release stays readable, and a kind this version does not
     * know simply never answers `isWriteAtTarget()`. Unknown keys are dropped rather
     * than refused, for the same reason.
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

        $target = self::field($payload, 'target');

        if ($target !== null && !PackagePaths::isCanonicalTargetPath($target)) {
            return null;
        }

        return new self(
            $kind,
            $at,
            $attribution,
            $target,
            self::field($payload, 'operation'),
            self::field($payload, 'pre'),
            self::field($payload, 'post'),
            self::field($payload, 'observed'),
            self::field($payload, 'id'),
            self::field($payload, 'reason')
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
