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
 * Turns a regex condition's operand into the PCRE expression that is evaluated.
 *
 * ConditionEvaluator matches with it at runtime and BuildValidator warns with it
 * at build time, so both judge the same expression.
 */
final class RegexExpression
{
    /**
     * Tried in order; the first that appears nowhere in the operand is used.
     *
     * The alternative - one fixed delimiter and escaping it inside the expression -
     * corrupts an expression that already escapes it, and the author would never
     * see that their pattern had been rewritten.
     *
     * @var list<string>
     */
    private const DELIMITERS = ['/', '~', '#', '%', '!', '@', ';', ',', '|', '+', '=', ':'];

    /**
     * The delimited expression, or null when the operand uses every delimiter.
     *
     * D anchors $ at the end of the subject rather than before a final newline,
     * so an exclusion written as ^(?!host$).*$ means what its author read.
     */
    public static function of(string $operand): ?string
    {
        foreach (self::DELIMITERS as $delimiter) {
            if (!\str_contains($operand, $delimiter)) {
                return $delimiter . $operand . $delimiter . 'D';
            }
        }

        return null;
    }
}
