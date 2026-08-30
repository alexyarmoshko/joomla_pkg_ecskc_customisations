<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition;

/**
 * One condition: an environment attribute, how to match it, and what to match it
 * against.
 *
 * Brief 5.1: "Matching is literal, by pattern, or by version", and literal is the
 * default. Version comparison earns a type of its own because doing it as text or
 * by pattern is a well-known source of quiet error - `5.10` sorts before `5.9` as a
 * string, and a pattern written for one numbering scheme stops matching when the
 * vendor changes it.
 *
 * A condition may never address the destination file. That boundary is the schema's
 * to enforce - the attribute vocabulary contains no way to name a file - and it is
 * what makes an unanswerable condition a fault rather than a silent false.
 *
 * `description` is the author's own account of what the condition is for. It is
 * carried because it is what a report can show beside a skipped or faulted target,
 * and the sample schema uses it for exactly the pattern that most needs explaining.
 */
final class RuleCondition
{
    public const LITERAL = 'literal';
    public const REGEX   = 'regex';
    public const VERSION = 'version';

    public function __construct(
        public readonly string $type,
        public readonly string $attribute,
        public readonly string $operand,
        public readonly ?string $description = null,
    ) {
    }
}
