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
