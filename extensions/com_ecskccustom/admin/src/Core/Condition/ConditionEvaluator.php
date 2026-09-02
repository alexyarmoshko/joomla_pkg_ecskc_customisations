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

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentValues;

/**
 * Decides whether an action's conditions hold in this environment.
 *
 * ## A fault anywhere faults the whole outcome
 *
 * Every member of every group is evaluated - there is no short circuit - and if any
 * attribute could not be determined, the outcome is `faulted` however the rest came
 * out. Two reasons.
 *
 * The governing rule is to act only when the result is certain. An `any` group with
 * one matching member and one undeterminable one is not certain: the package cannot
 * say what the rule set asked it to say, and an operator who is never told that an
 * attribute was unreadable cannot fix it.
 *
 * And short-circuiting would make the outcome depend on the order the members are
 * written in - the same rule set faulting on one site and applying on another
 * because the author happened to put the host condition second. Evaluating
 * everything makes the answer a property of the environment alone.
 *
 * The cost is real and deliberate: a group that would have matched anyway now
 * reports `faulted` and writes nothing until the environment is fixed. That is the
 * side to fail on for a tool that rewrites files on a live site.
 *
 * ## No pattern is evaluated against an undeterminable value
 *
 * A faulted attribute never reaches `preg_match` or `version_compare`. The empty
 * string satisfies `^(?!ecskc\.eu$).*$`, so evaluating the pattern anyway - even to
 * discard the result - is one refactor away from applying the Community Builder
 * licence-bypass customisation on production. Brief section 6 records that case.
 *
 * ## A pattern that cannot be judged is a fault too
 *
 * An uncompilable expression, a comparison that exhausts PCRE's backtrack limit,
 * an unknown condition type, an unparseable version constraint: each is answered
 * with a fault rather than with false. False is a claim about the environment, and
 * none of these knows anything about the environment.
 */
final class ConditionEvaluator
{
    /**
     * Tried in order; the first that appears nowhere in the expression is used.
     *
     * The alternative - one fixed delimiter and escaping it inside the expression -
     * corrupts an expression that already escapes it, and the author would never
     * see that their pattern had been rewritten.
     */
    private const DELIMITERS = ['/', '~', '#', '%', '!', '@', ';', ',', '|', '+', '=', ':'];

    /**
     * Longest first, so `>=` is not read as `>` with a version of `=5.4.0`.
     */
    private const OPERATORS = ['>=', '<=', '==', '!=', '<>', '>', '<', '='];

    /**
     * Digits in dot-separated parts, then at most one suffix, which may contain dots
     * once it has started. A suffix is introduced by `-`, `_`, `+` or `~`, or by
     * nothing at all where it opens with a version state, as PHP spells a prerelease
     * in `PHP_VERSION`: `8.4.0RC1`.
     *
     * **The grammar admits only what `version_compare` can order in a direction the
     * author can reason about**, which is narrower than "looks like a version". Its
     * first suffix part decides the direction against the plain release, and there
     * are two parts it orders and one it does not:
     *
     * - A digit-led part sorts **above** the release, which is what a distribution
     *   revision means: `8.1.2-1ubuntu2.14` and `8.2.28-1~deb12u1` are 8.1.2 and
     *   8.2.28 with packaging on top.
     * - One of `version_compare`'s own states - `dev`, `alpha`, `a`, `beta`, `b`,
     *   `RC`, `rc`, `pl`, `p`, spelled in that case - sorts where the state says.
     * - **Anything else sorts below the release**, and that is the direction that
     *   writes. `8.2.0-ubuntu1`, `7.4.33-nmm1`, `8.2.0-zend1` and `5.4.7-PL1` are
     *   each *at* their release, and each satisfies a ceiling of `<8.2.0`, `<7.4.33`
     *   or `<5.4.7` - a ceiling written to keep a workaround off exactly those hosts.
     *   `PL` is not `pl` to `version_compare`, which reads it as an arbitrary string.
     *   So they fault, which an operator sees, rather than comparing backwards, which
     *   nobody sees.
     *
     * `.` never introduces a suffix. Allowing it let `5.4banana` backtrack into the
     * version `5` with the suffix `.4banana`, which `version_compare` is satisfied by
     * under `>=5.4.7`.
     *
     * The `-` sits last inside each class. Written as `[._+-~]` it would be the range
     * `+` to `~`, which is most of ASCII.
     */
    private const VERSION_STATE = '(?:dev|alpha|beta|RC|rc|pl|a|b|p)';

    private const VERSION = '/\A[0-9]+(\.[0-9]+)*'
        . '((' . self::VERSION_STATE . '[0-9]*'
        . '|[-_+~](?:[0-9][0-9A-Za-z]*|' . self::VERSION_STATE . '[0-9]*))'
        . '([._+~-][0-9A-Za-z]+)*)?\z/';

    /**
     * @param ConditionGroup|null $group Null where the action states no conditions,
     *                                   which the schema permits and which means the
     *                                   action always applies.
     */
    public function evaluate(?ConditionGroup $group, EnvironmentValues $values): ConditionOutcome
    {
        if ($group === null) {
            return ConditionOutcome::matched();
        }

        $faults = [];
        $held   = $this->group($group, $values, $faults);

        if ($faults !== []) {
            return ConditionOutcome::faulted($faults);
        }

        return $held ? ConditionOutcome::matched() : ConditionOutcome::notMatched();
    }

    /**
     * @param list<string> $faults
     */
    private function group(ConditionGroup $group, EnvironmentValues $values, array &$faults): bool
    {
        if ($group->members === []) {
            // Unreachable through the schema, which requires at least one member. An
            // empty group has no honest answer - vacuously true under "all", false
            // under "any" - so it is not given one.
            $faults[] = 'a condition group has no members, so what it asks cannot be determined';

            return false;
        }

        if ($group->match !== ConditionGroup::ALL && $group->match !== ConditionGroup::ANY) {
            $faults[] = \sprintf(
                'a condition group is matched "%s", which is neither "all" nor "any"',
                $group->match
            );

            return false;
        }

        $results = [];

        foreach ($group->members as $member) {
            $results[] = $member instanceof ConditionGroup
                ? $this->group($member, $values, $faults)
                : $this->condition($member, $values, $faults);
        }

        if ($group->match === ConditionGroup::ANY) {
            return \in_array(true, $results, true);
        }

        return !\in_array(false, $results, true);
    }

    /**
     * @param list<string> $faults
     */
    private function condition(RuleCondition $condition, EnvironmentValues $values, array &$faults): bool
    {
        $value = $values->value($condition->attribute);

        if ($value === null) {
            $faults[] = \sprintf(
                '%s could not be determined, because %s. No pattern was evaluated against it.%s',
                $condition->attribute,
                (string) $values->reason($condition->attribute),
                $condition->description === null ? '' : ' The condition reads: ' . $condition->description
            );

            return false;
        }

        return match ($condition->type) {
            RuleCondition::LITERAL => $value === $condition->operand,
            RuleCondition::REGEX   => $this->matchesPattern($condition, $value, $faults),
            RuleCondition::VERSION => $this->satisfiesVersion($condition, $value, $faults),
            default                => $this->unknownType($condition, $faults),
        };
    }

    /**
     * @param list<string> $faults
     */
    private function unknownType(RuleCondition $condition, array &$faults): bool
    {
        $faults[] = \sprintf(
            'a condition on %s is typed "%s", which is not one of literal, regex or version',
            $condition->attribute,
            $condition->type
        );

        return false;
    }

    /**
     * @param list<string> $faults
     */
    private function matchesPattern(RuleCondition $condition, string $value, array &$faults): bool
    {
        if ($condition->operand === '') {
            // An empty expression compiles, and `//` matches every value. The other
            // two types fail closed on an empty operand - a literal can never equal a
            // value, which is guaranteed non-empty, and a version constraint faults -
            // so regex is the one that would turn an exclusion whose body was lost in
            // an edit into a condition that holds everywhere.
            $faults[] = \sprintf(
                'the expression for %s is empty, and an empty expression matches every value. '
                . 'That cannot be what an exclusion meant.',
                $condition->attribute
            );

            return false;
        }

        $delimiter = $this->delimiter($condition->operand);

        if ($delimiter === null) {
            $faults[] = \sprintf(
                'the expression for %s uses every character available as a delimiter, so it '
                . 'cannot be compiled without being rewritten',
                $condition->attribute
            );

            return false;
        }

        // D anchors $ at the end of the subject rather than before a final newline,
        // so an exclusion written as ^(?!host$).*$ means what its author read.
        $expression = $delimiter . $condition->operand . $delimiter . 'D';

        // The warning is captured rather than suppressed. On a *compilation* failure
        // preg_last_error() stays at PREG_INTERNAL_ERROR, so preg_last_error_msg()
        // says only "Internal error" - and compilation is where the likely authoring
        // mistake is. The warning is the one place PCRE names the cause and the
        // offset: "Compilation failed: missing closing parenthesis at offset 9". A
        // fault an author cannot locate is a fault they cannot fix.
        //
        // Runtime failures are unaffected and still read from preg_last_error_msg():
        // exhausting the backtrack limit raises no warning, and security review v2
        // measured that path reporting "Backtrack limit exhausted".
        $warning = null;

        \set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = \preg_match($expression, $value);
        } finally {
            \restore_error_handler();
        }

        if ($result === false) {
            $faults[] = \sprintf(
                'the expression for %s could not be evaluated: %s. Nothing is claimed about '
                . 'the environment when the expression itself cannot be judged.',
                $condition->attribute,
                $this->patternFailureReason($warning)
            );

            return false;
        }

        return $result === 1;
    }

    /**
     * Why the expression could not be evaluated, in as much detail as PCRE gave.
     *
     * The captured warning wins where there is one, because it is the more specific
     * of the two: it carries the cause and the offset, while preg_last_error_msg()
     * has only the coarse code. Its "preg_match(): " prefix is dropped - the reason
     * is read by an operator in a report, not by someone debugging PHP.
     */
    private function patternFailureReason(?string $warning): string
    {
        // Read before anything else runs a PCRE function. preg_replace() below
        // resets the error state - after it, preg_last_error_msg() answers for
        // *that* call and says "No error", so a fallback taken afterwards would
        // report a failed expression as having succeeded. Review s1.v17, finding 2.
        $fallback = \preg_last_error_msg();

        if ($warning === null || \trim($warning) === '') {
            return $fallback;
        }

        $reason = \preg_replace('/^\s*preg_match\(\):\s*/', '', $warning);

        return $reason === null || \trim($reason) === '' ? $fallback : \trim($reason);
    }

    private function delimiter(string $expression): ?string
    {
        foreach (self::DELIMITERS as $delimiter) {
            if (!\str_contains($expression, $delimiter)) {
                return $delimiter;
            }
        }

        return null;
    }

    /**
     * A constraint is an optional comparison operator and a version, as `>=5.4.0`.
     *
     * The operator defaults to equality, so a bare `5.4.0` reads as "exactly this".
     * Comparison is PHP's own, which is why the type exists at all: `5.10` sorts
     * before `5.9` as text and after it as a version. It is also PHP's own in its
     * pickiness - `5.4` is below `5.4.0` - so a constraint is written with as many
     * parts as the value it is compared against carries.
     *
     * @param list<string> $faults
     */
    private function satisfiesVersion(RuleCondition $condition, string $value, array &$faults): bool
    {
        $constraint = \trim($condition->operand);
        $operator   = '=';

        foreach (self::OPERATORS as $candidate) {
            if (\str_starts_with($constraint, $candidate)) {
                $operator   = $candidate;
                $constraint = \trim(\substr($constraint, \strlen($candidate)));

                break;
            }
        }

        if (\preg_match(self::VERSION, $constraint) !== 1) {
            // Two ways to be unreadable, and both fault. A typo such as "=>5.4.0"
            // parses as the operator "=" and the version ">5.4.0", which
            // version_compare answers false to - a claim that the environment does not
            // satisfy the constraint, which nothing here knows. And "5banana" or
            // "5.4 extra" is compared happily and matches, which is the direction that
            // writes a file. Neither says anything about the environment.
            $faults[] = \sprintf(
                'the version constraint for %s is "%s", which does not name a version. A '
                . 'constraint is an optional comparison operator followed by a version, as '
                . '">=5.4.0": digits in dot-separated parts, then at most one suffix. A '
                . 'suffix follows "-", "_", "+" or "~" and starts with a digit, as in '
                . '"8.1.2-1ubuntu2.14", or it is one of PHP\'s version states, as in '
                . '"8.4.0RC1" or "6.0.0-alpha1". A suffix version_compare cannot order, '
                . 'such as "-ubuntu1", is refused rather than compared backwards.',
                $condition->attribute,
                $condition->operand
            );

            return false;
        }

        if (\preg_match(self::VERSION, $value) !== 1) {
            // The same hazard from the other operand. version_compare is as
            // forgiving with the value as with the constraint, and forgiving in the
            // direction that writes: version_compare('Unknown', '9.0', '<') is true,
            // so a value that is not a version matched a ceiling and the object
            // wrote. EnvironmentValues asks whether the value could be determined;
            // this asks whether what was determined is a version at all, which only
            // a version-typed condition needs.
            $faults[] = \sprintf(
                'the value of %s is "%s", which is not a version, so the constraint "%s" '
                . 'cannot be judged against it. Nothing is claimed about an environment whose '
                . 'version cannot be read.',
                $condition->attribute,
                $value,
                $condition->operand
            );

            return false;
        }

        return \version_compare($value, $constraint, $operator);
    }
}
