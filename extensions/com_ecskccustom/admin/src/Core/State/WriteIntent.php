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

/**
 * The record of one mutation that is about to happen, or may just have happened.
 *
 * Atomic target replacement and an independently locked event log are not one
 * transaction, decision 31. Logging before the mutation can record a write that
 * never happened; logging after it can lose the only evidence of a create, a no-copy
 * removal, or a freeze or unfreeze if the process dies in between. The run marker
 * does not help - it records only that a run began.
 *
 * So the record holds two things and no more: **one immutable correlation id**, and
 * **the complete event payload recovery would append**. Recovery matches on the id,
 * because target, operation, pre-state and post-state cannot tell a leftover intent
 * from an older identical transition, and are not enough to rebuild the record
 * either. The event the run commits carries the same id.
 *
 * What goes in the payload is ticket 29's contract, not this class's: this is the
 * store's record shape, and it carries whatever the event log would have received.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class WriteIntent
{
    /**
     * Correlation ids minted here are 32 hex characters.
     *
     * Reading accepts a wider shape, so a record written by a release that minted
     * ids differently still round-trips rather than reading as a malformed intent
     * that nobody can settle. It stays narrow enough that an id cannot carry a
     * separator, a newline, or anything else a later reader has to defend against.
     */
    private const ID_BYTES = 16;

    /**
     * Public because the event record accepts exactly the ids this class mints.
     *
     * The committed event carries the intent's correlation id, decision 32, so the
     * two have to agree about what an id may look like. A second copy of the pattern
     * is how they would come to disagree.
     */
    public const ID = '/\A[A-Za-z0-9._-]{1,64}\z/';

    /**
     * @param array<string, mixed> $event
     */
    private function __construct(
        public readonly string $id,
        public readonly array $event,
    ) {
    }

    /**
     * A new intent, with a correlation id minted for it.
     *
     * @param array<string, mixed> $event The complete event payload recovery would
     *                                    append for this mutation.
     */
    public static function begin(array $event): self
    {
        return self::of(self::mintId(), $event);
    }

    /**
     * A new correlation id, for a caller that builds the event before the intent.
     *
     * The event record carries the id too, so a writer needs it before the payload the
     * intent will hold exists. It then publishes `of($id, $record->toPayload())`.
     */
    public static function mintId(): string
    {
        return \bin2hex(\random_bytes(self::ID_BYTES));
    }

    /**
     * An intent with an id that already exists, as read back from the store.
     *
     * @param array<string, mixed> $event
     *
     * @throws InvalidArgumentException On an id or payload that could not have been
     *                                  written by begin(), so a caller building one
     *                                  by hand fails here rather than at the point
     *                                  where recovery cannot match it.
     */
    public static function of(string $id, array $event): self
    {
        if (\preg_match(self::ID, $id) !== 1) {
            throw new InvalidArgumentException(
                \sprintf('%s is not a usable correlation id', \var_export($id, true))
            );
        }

        if ($event === []) {
            throw new InvalidArgumentException(
                'A write intent carries the complete event payload, which is never empty'
            );
        }

        return new self($id, $event);
    }

    /**
     * The record as it is stored: one UTF-8 JSON object.
     *
     * The payload's keys are not policed. PHP coerces a numeric string key to an
     * integer, so a rule that the payload's keys must be strings would reject a
     * record this class had just written back to itself. What matters is that the
     * array survives the round trip unchanged, and it does either way: a map encodes
     * as an object and a list as an array, and each decodes to the array it came
     * from.
     *
     * @throws JsonException On a payload that is not encodable, which is a caller
     *                       fault rather than an I/O condition. It surfaces before
     *                       anything is published and therefore before the mutation,
     *                       so nothing has happened that the log would need to know
     *                       about.
     */
    public function toJson(): string
    {
        return \json_encode(
            ['id' => $this->id, 'event' => $this->event],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Read a record back, or null when it is not one this class wrote.
     *
     * A trailing LF is accepted because publish() writes one; nothing else is.
     */
    public static function fromJson(string $bytes): ?self
    {
        $decoded = \json_decode(\rtrim($bytes, "\n"), true);

        if (!\is_array($decoded)
            || !\is_string($decoded['id'] ?? null)
            || !\is_array($decoded['event'] ?? null)) {
            return null;
        }

        try {
            return self::of($decoded['id'], $decoded['event']);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
