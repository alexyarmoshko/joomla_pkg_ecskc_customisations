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
 * Work item 9 of the plan says to read the algorithm from the patch declaration
 * rather than hardcode one at the point of use. That is a rule about the *call
 * sites*: nothing that hashes bytes may name an algorithm of its own. It still
 * leaves one question - which declarations version 1 can honour - and that answer
 * has to live somewhere. Here, and nowhere else.
 *
 * Two consumers need it and they need it to agree. Merged-set validation rejects an
 * unsupported declaration as a build error, and the hasher refuses to hash under
 * one. If those two lists could drift, a file could declare an algorithm the build
 * accepts and the hasher cannot compute, and every target would report a hash it
 * could not produce rather than a fault the author can act on.
 *
 * Digest width belongs with the algorithm for the same reason. The schema accepts
 * 32 to 128 hex characters because it cannot know which algorithm the file
 * declared; the width is what catches a file declaring sha256 while carrying
 * digests of something else - internally consistent, so every structural check
 * passes, until real bytes are hashed and no target ever matches again.
 *
 * Adding an algorithm in a later version means adding it to the one table here.
 * Everything else - what is supported, and how the refusal reads - is read off it.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class HashAlgorithm
{
    /**
     * The supported algorithms and how many hex characters each one's digest has.
     *
     * This is the table. Version 1 holds one entry, and a patch declaring anything
     * else is a build error, per plan work item 9. A second name written beside this
     * one - a scalar saying which algorithm is supported, a list repeated in a
     * message - is a name that can disagree with it, and the disagreement would read
     * as an algorithm the build accepts and the hasher cannot compute.
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
