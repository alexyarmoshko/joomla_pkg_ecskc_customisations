<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash;

/**
 * A digest, or the reason there is none.
 *
 * The runner contract requires this shape. Every `blocked`, `incomplete`,
 * `faulted`, refused and skipped target carries an expected and an actual hash,
 * and "a hash might be unavailable because the file is absent or unreadable, or
 * because the package itself is at fault. In that case, the field contains the
 * reason instead of a hash. Do not omit the field." A nullable string would let a
 * caller omit it and would say nothing about why; this cannot be built without one
 * or the other.
 *
 * It follows the same rule the environment values follow: a value that cannot be
 * determined is a fault, never a default. An unavailable result therefore matches
 * nothing - not even another unavailable one - because "we could not read the file"
 * is not evidence that the file is what the rule expects.
 */
final class HashResult
{
    private function __construct(
        public readonly ?string $digest,
        public readonly ?string $reason,
        private readonly bool $absent = false,
    ) {
    }

    public static function digest(string $digest): self
    {
        return new self($digest, null);
    }

    public static function unavailable(string $reason): self
    {
        return new self(null, $reason);
    }

    /**
     * There is no file at this path, which is a different answer from "no digest".
     *
     * The forward walk has to tell the two apart and cannot do it from the reason
     * text. An absent file is an ordinary state the rule set describes: an object
     * that may create writes there, one that may not reports `not applicable`, and a
     * removal is already done. Every other unavailable result - a directory in the
     * way, an unreadable file, a parent that cannot be searched - says the package
     * could not look, which is `faulted`. Sending an operator to the wrong one of
     * those two costs them the whole diagnosis.
     *
     * It carries no digest, so it matches nothing, exactly as any other unavailable
     * result does.
     */
    public static function absent(string $reason): self
    {
        return new self(null, $reason, true);
    }

    public function isAvailable(): bool
    {
        return $this->digest !== null;
    }

    public function isAbsent(): bool
    {
        return $this->absent;
    }

    /**
     * Whether this digest is the one a rule declared.
     *
     * Case-insensitive because the schema's hexDigest accepts upper and lower case,
     * so an author may write either and `hash()` always answers in lower case. The
     * comparison is the one place that difference is allowed to matter, and it is
     * settled here rather than by every caller remembering to fold the case.
     *
     * `hash_equals` rather than `===`: these are digests, and comparing them in
     * constant time costs nothing here.
     */
    public function matches(string $declared): bool
    {
        if ($this->digest === null) {
            return false;
        }

        return \hash_equals(\strtolower($this->digest), \strtolower($declared));
    }

    /**
     * The field as the report carries it: the digest, or the reason there is none.
     */
    public function describe(): string
    {
        return $this->digest ?? (string) $this->reason;
    }
}
