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
     * Digits in dot-separated parts, then at most one suffix. **A suffix is
     * introduced by `-`, `_` or `+`, never by `.`**, though it may contain dots once
     * it has started.
     *
     * That exclusion is the whole point of the shape. An earlier version let a suffix
     * start with a dot as well, which made the two branches ambiguous: `5.4banana`
     * failed as digits-and-dots, backtracked, and was read as the version `5` with
     * the suffix `.4banana`. `5.4foo` and `5.4.0banana` went the same way, and
     * `version_compare` is satisfied by all three under `>=5.4.7` - so the grammar
     * meant to stop an unreadable constraint from permitting a write let three
     * through.
     *
     * Wide enough for what Joomla and PHP actually report: `5.4.7`, `8.3.6`,
     * `6.0.0-alpha1`, `1.0.0+build.5`, and a distribution build's
     * `8.1.2-1ubuntu2.14`. Narrow enough to refuse what `version_compare` accepts
     * without complaint, which is very nearly anything - `5banana`, `5..4`,
     * `5.4 extra`, `5>=4` and `5/4` are each satisfied by `5.4.7` under `>=`.
     *
     * A version written without a separator before its suffix, as `5.4.7RC1`, is
     * refused. That is a fault an author sees and fixes, which is the side to err on.
     */
    private const VERSION = '/\A[0-9]+(\.[0-9]+)*([-_+][0-9A-Za-z]+([._+-][0-9A-Za-z]+)*)?\z/';

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
        $result = @\preg_match($delimiter . $condition->operand . $delimiter . 'D', $value);

        if ($result === false) {
            $faults[] = \sprintf(
                'the expression for %s could not be evaluated: %s. Nothing is claimed about '
                . 'the environment when the expression itself cannot be judged.',
                $condition->attribute,
                \preg_last_error_msg()
            );

            return false;
        }

        return $result === 1;
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
                . '">=5.4.0": digits in dot-separated parts, and any suffix introduced by '
                . '"-", "_" or "+".',
                $condition->attribute,
                $condition->operand
            );

            return false;
        }

        return \version_compare($value, $constraint, $operator);
    }
}
