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
 * What the package knows about hash algorithms, in one place.
 *
 * Two consumers need it and they need it to agree. Merged-set validation rejects an
 * unsupported declaration as a build error, and the hasher refuses to hash under
 * one. If those two lists could drift, a file could declare an algorithm the build
 * accepts and the hasher cannot compute, and every target would report a hash it
 * could not produce rather than a fault the author can act on.
 */
final class HashAlgorithm
{
    /**
     * The supported algorithms and how many hex characters each one's digest has.
     *
     * @var array<string, int>
     */
    private const DIGEST_LENGTH = ['sha256' => 64];

    /**
     * @return list<string>
     */
    public static function supported(): array
    {
        return \array_keys(self::DIGEST_LENGTH);
    }

    public static function isSupported(string $algorithm): bool
    {
        return isset(self::DIGEST_LENGTH[$algorithm]);
    }

    /**
     * The digest width, or null for an algorithm version 1 does not support - which
     * has no width to measure a declared digest against.
     */
    public static function digestLength(string $algorithm): ?int
    {
        return self::DIGEST_LENGTH[$algorithm] ?? null;
    }

    /**
     * Why an unsupported declaration is refused, in the words the report and the
     * build both use.
     */
    public static function unsupportedReason(string $algorithm): string
    {
        return \sprintf(
            'the rule declares hashType %s; version 1 supports %s only',
            \var_export($algorithm, true),
            \implode(', ', self::supported())
        );
    }
}
