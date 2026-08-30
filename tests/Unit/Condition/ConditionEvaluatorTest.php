<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Condition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition\ConditionEvaluator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition\ConditionGroup;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition\ConditionOutcome;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition\RuleCondition;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentValues;

#[CoversClass(ConditionEvaluator::class)]
#[CoversClass(ConditionGroup::class)]
#[CoversClass(ConditionOutcome::class)]
#[CoversClass(RuleCondition::class)]
#[CoversClass(EnvironmentValues::class)]
final class ConditionEvaluatorTest extends TestCase
{
    /**
     * The production site as the package finds it, so a test overrides the one
     * attribute it is about.
     */
    private const PRODUCTION = [
        EnvironmentValues::SITE_URL       => 'https://ecskc.eu',
        EnvironmentValues::HOST           => 'ecskc.eu',
        EnvironmentValues::JOOMLA_VERSION => '5.4.7',
        EnvironmentValues::PHP_VERSION    => '8.3.31',
    ];

    private ConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new ConditionEvaluator();
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private static function values(array $overrides = []): EnvironmentValues
    {
        return new EnvironmentValues(\array_merge(self::PRODUCTION, $overrides));
    }

    private static function condition(
        string $attribute,
        string $operand,
        string $type = RuleCondition::LITERAL,
    ): RuleCondition {
        return new RuleCondition($type, $attribute, $operand);
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function outcome(ConditionGroup $group, array $overrides = []): ConditionOutcome
    {
        return $this->evaluator->evaluate($group, self::values($overrides));
    }

    public function testAnActionWithNoConditionsAlwaysApplies(): void
    {
        $outcome = $this->evaluator->evaluate(null, self::values());

        self::assertTrue($outcome->isMatched());
        self::assertSame([], $outcome->reasons);
    }

    public function testALiteralConditionMatchesTheValueExactly(): void
    {
        $group = new ConditionGroup(ConditionGroup::ALL, [
            self::condition(EnvironmentValues::HOST, 'ecskc.eu'),
        ]);

        self::assertTrue($this->outcome($group)->isMatched());
        self::assertSame(ConditionOutcome::NOT_MATCHED, $this->outcome($group, [
            EnvironmentValues::HOST => 'dev.example.invalid',
        ])->status);
    }

    public function testAnAllGroupNeedsEveryMemberAndAnAnyGroupNeedsOne(): void
    {
        $members = [
            self::condition(EnvironmentValues::HOST, 'ecskc.eu'),
            self::condition(EnvironmentValues::PHP_VERSION, 'not this'),
        ];

        self::assertFalse($this->outcome(new ConditionGroup(ConditionGroup::ALL, $members))->isMatched());
        self::assertTrue($this->outcome(new ConditionGroup(ConditionGroup::ANY, $members))->isMatched());
    }

    /**
     * The sample schema's shape: this host, and either of those two versions.
     * Groups nest, which is why the language needs no second element type.
     */
    public function testGroupsNest(): void
    {
        $group = new ConditionGroup(ConditionGroup::ALL, [
            self::condition(EnvironmentValues::HOST, 'ecskc.eu'),
            new ConditionGroup(ConditionGroup::ANY, [
                self::condition(EnvironmentValues::JOOMLA_VERSION, '>=5.4.0', RuleCondition::VERSION),
                self::condition(EnvironmentValues::PHP_VERSION, '>=8.2', RuleCondition::VERSION),
            ]),
        ]);

        self::assertTrue($this->outcome($group)->isMatched());
        self::assertFalse($this->outcome($group, [EnvironmentValues::HOST => 'elsewhere'])->isMatched());
        self::assertFalse($this->outcome($group, [
            EnvironmentValues::JOOMLA_VERSION => '5.3.0',
            EnvironmentValues::PHP_VERSION    => '8.1.0',
        ])->isMatched());
    }

    /**
     * The exclusion from brief section 6, doing its job: everywhere except
     * production.
     */
    public function testAnAnchoredExclusionExcludesTheHostItNames(): void
    {
        $group = new ConditionGroup(ConditionGroup::ALL, [
            self::condition(EnvironmentValues::HOST, '^(?!ecskc\.eu$).*$', RuleCondition::REGEX),
        ]);

        self::assertSame(ConditionOutcome::NOT_MATCHED, $this->outcome($group)->status);
        self::assertTrue($this->outcome($group, [EnvironmentValues::HOST => 'dev.example.invalid'])->isMatched());
    }

    /**
     * The same exclusion written against the wrong attribute, which is why SITE_URL
     * and HOST are two attributes and not one.
     *
     * A host-shaped pattern never matches a URL-shaped value, so the negative
     * lookahead is satisfied by every site including the one it names, the condition
     * holds, and the customisation applies on production. Nothing here reports a
     * problem: the pattern is valid and the value is determined. It fails open, and
     * asserting that it does is the point.
     */
    public function testAHostShapedExclusionFailsOpenAgainstSiteUrl(): void
    {
        $group = new ConditionGroup(ConditionGroup::ALL, [
            self::condition(EnvironmentValues::SITE_URL, '^(?!ecskc\.eu$).*$', RuleCondition::REGEX),
        ]);

        self::assertTrue($this->outcome($group)->isMatched());
    }

    /**
     * And the reverse, which fails open the same way.
     */
    public function testAUrlShapedExclusionFailsOpenAgainstHost(): void
    {
        $group = new ConditionGroup(ConditionGroup::ALL, [
            self::condition(EnvironmentValues::HOST, '^(?!https://ecskc\.eu$).*$', RuleCondition::REGEX),
        ]);

        self::assertTrue($this->outcome($group)->isMatched());
    }

    /**
     * Written against the attribute it was drawn for, the URL-shaped exclusion
     * excludes production as its author intended. The delimiter this needs is not
     * the first candidate, since the expression contains slashes of its own.
     */
    public function testAUrlShapedExclusionExcludesProductionWhenWrittenAgainstSiteUrl(): void
    {
        $group = new ConditionGroup(ConditionGroup::ALL, [
            self::condition(EnvironmentValues::SITE_URL, '^(?!https://ecskc\.eu$).*$', RuleCondition::REGEX),
        ]);

        self::assertSame(ConditionOutcome::NOT_MATCHED, $this->outcome($group)->status);
        self::assertTrue($this->outcome($group, [
            EnvironmentValues::SITE_URL => 'https://dev.example.invalid',
        ])->isMatched());
    }

    public function testAnUndeterminableValueFaultsRatherThanReadingAsNotThatHost(): void
    {
        $group = new ConditionGroup(ConditionGroup::ALL, [
            self::condition(EnvironmentValues::HOST, '^(?!ecskc\.eu$).*$', RuleCondition::REGEX),
        ]);

        $outcome = $this->outcome($group, [EnvironmentValues::HOST => null]);

        self::assertSame(ConditionOutcome::FAULTED, $outcome->status);
        self::assertTrue($outcome->isFaulted());
        self::assertFalse($outcome->isMatched());
        self::assertStringContainsString('HOST could not be determined', $outcome->reasons[0]);
    }

    /**
     * That no pattern is evaluated against an undeterminable value, asserted
     * directly rather than inferred from an exclusion that happens to pass.
     *
     * The expression cannot compile. Evaluating it against anything reports that it
     * could not be evaluated, so a faulted outcome carrying only the undeterminable
     * value and never that message is evidence the expression was never reached.
     * The test below holds the probe honest by showing the message does appear when
     * the value is determined.
     */
    public function testNoPatternIsEvaluatedAgainstAnUndeterminableValue(): void
    {
        $group = new ConditionGroup(ConditionGroup::ALL, [
            self::condition(EnvironmentValues::HOST, '^(unclosed', RuleCondition::REGEX),
        ]);

        $outcome = $this->outcome($group, [EnvironmentValues::HOST => null]);

        self::assertSame(ConditionOutcome::FAULTED, $outcome->status);
        self::assertCount(1, $outcome->reasons);
        self::assertStringContainsString('HOST could not be determined', $outcome->reasons[0]);
        self::assertStringNotContainsString('could not be evaluated', $outcome->reasons[0]);
    }

    public function testTheProbeExpressionDoesReportItselfWhenTheValueIsDetermined(): void
    {
        $group = new ConditionGroup(ConditionGroup::ALL, [
            self::condition(EnvironmentValues::HOST, '^(unclosed', RuleCondition::REGEX),
        ]);

        $outcome = $this->outcome($group);

        self::assertSame(ConditionOutcome::FAULTED, $outcome->status);
        self::assertStringContainsString('could not be evaluated', $outcome->reasons[0]);
    }

    /**
     * A fault anywhere faults the whole outcome, including inside an `any` group
     * whose other member matches.
     *
     * The alternative applies the customisation while an attribute the rule set
     * asked about was unreadable, and never tells the operator. The governing rule
     * is to act only when the result is certain, and this is not certain.
     */
    public function testAFaultInsideAnAnyGroupFaultsTheOutcomeEvenWhenAnotherMemberMatches(): void
    {
        $group = new ConditionGroup(ConditionGroup::ANY, [
            self::condition(EnvironmentValues::HOST, 'ecskc.eu'),
            self::condition(EnvironmentValues::SITE_URL, 'https://ecskc.eu'),
        ]);

        $outcome = $this->outcome($group, [EnvironmentValues::SITE_URL => null]);

        self::assertSame(ConditionOutcome::FAULTED, $outcome->status);
    }

    /**
     * Every member is evaluated, so an operator sees every unreadable attribute in
     * one report. It also means the outcome cannot depend on the order the author
     * happened to write the members in.
     */
    public function testEveryFaultIsReportedRatherThanTheFirst(): void
    {
        $group = new ConditionGroup(ConditionGroup::ALL, [
            self::condition(EnvironmentValues::HOST, 'ecskc.eu'),
            new ConditionGroup(ConditionGroup::ANY, [
                self::condition(EnvironmentValues::SITE_URL, 'https://ecskc.eu'),
                self::condition(EnvironmentValues::PHP_VERSION, '>=8.2', RuleCondition::VERSION),
            ]),
        ]);

        $outcome = $this->outcome($group, [
            EnvironmentValues::SITE_URL    => null,
            EnvironmentValues::PHP_VERSION => null,
        ]);

        self::assertSame(ConditionOutcome::FAULTED, $outcome->status);
        self::assertCount(2, $outcome->reasons);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function versionConstraints(): array
    {
        return [
            'at or above the floor'   => ['5.4.7', '>=5.4.0', true],
            'below the floor'         => ['5.3.9', '>=5.4.0', false],
            'above a ceiling'         => ['5.4.7', '<5.4.0', false],
            'exact by default'        => ['5.4.7', '5.4.7', true],
            'exact and different'     => ['5.4.7', '5.4.6', false],
            'explicit equality'       => ['5.4.7', '==5.4.7', true],
            'inequality'              => ['5.4.7', '!=5.4.6', true],
            'spaced constraint'       => ['5.4.7', '>= 5.4.0', true],
            // 5.10 sorts before 5.9 as text and after it as a version. This is the
            // whole reason version is a type of its own.
            'ten sorts above nine'    => ['5.10.0', '>5.9.0', true],
            'nine sorts below ten'    => ['5.9.0', '>5.10.0', false],
        ];
    }

    #[DataProvider('versionConstraints')]
    public function testVersionComparisonIsByVersionAndNotByText(
        string $value,
        string $constraint,
        bool $expected,
    ): void {
        $group = new ConditionGroup(ConditionGroup::ALL, [
            self::condition(EnvironmentValues::JOOMLA_VERSION, $constraint, RuleCondition::VERSION),
        ]);

        self::assertSame(
            $expected,
            $this->outcome($group, [EnvironmentValues::JOOMLA_VERSION => $value])->isMatched()
        );
    }

    /**
     * @return array<string, array{RuleCondition, string}>
     */
    public static function unjudgeableConditions(): array
    {
        return [
            'an operator and no version' => [
                new RuleCondition(RuleCondition::VERSION, EnvironmentValues::PHP_VERSION, '>='),
                'does not name a version',
            ],
            // Parses as the operator "=" and the version ">5.4.0", which
            // version_compare answers false to. False would be a claim about the
            // environment, and the constraint was never legible.
            'a transposed operator' => [
                new RuleCondition(RuleCondition::VERSION, EnvironmentValues::JOOMLA_VERSION, '=>5.4.0'),
                'does not name a version',
            ],
            'an operator this language has no name for' => [
                new RuleCondition(RuleCondition::VERSION, EnvironmentValues::JOOMLA_VERSION, '~>5.4'),
                'does not name a version',
            ],
            'a version that is a word' => [
                new RuleCondition(RuleCondition::VERSION, EnvironmentValues::JOOMLA_VERSION, 'latest'),
                'does not name a version',
            ],
            // These begin with a digit, so a first-character check passes them through
            // to version_compare, which compares anything at all: each of them is
            // satisfied by 5.4.7 under >=. Matching is the direction that writes a file.
            'digits and then a word' => [
                new RuleCondition(RuleCondition::VERSION, EnvironmentValues::JOOMLA_VERSION, '>=5banana'),
                'does not name a version',
            ],
            'an empty part' => [
                new RuleCondition(RuleCondition::VERSION, EnvironmentValues::JOOMLA_VERSION, '>=5..4'),
                'does not name a version',
            ],
            'a version and a stray word' => [
                new RuleCondition(RuleCondition::VERSION, EnvironmentValues::JOOMLA_VERSION, '>=5.4 extra'),
                'does not name a version',
            ],
            'an operator in the version' => [
                new RuleCondition(RuleCondition::VERSION, EnvironmentValues::JOOMLA_VERSION, '>=5>=4'),
                'does not name a version',
            ],
            'a slash in the version' => [
                new RuleCondition(RuleCondition::VERSION, EnvironmentValues::JOOMLA_VERSION, '>=5/4'),
                'does not name a version',
            ],
            // A dot-introduced suffix made the grammar's two branches ambiguous:
            // "5.4banana" backtracked into the version "5" with the suffix ".4banana".
            // version_compare is satisfied by all three of these under >=5.4.7.
            'a dotted part ending in a word' => [
                new RuleCondition(RuleCondition::VERSION, EnvironmentValues::JOOMLA_VERSION, '>=5.4banana'),
                'does not name a version',
            ],
            'a dotted part that is a word' => [
                new RuleCondition(RuleCondition::VERSION, EnvironmentValues::JOOMLA_VERSION, '>=5.4foo'),
                'does not name a version',
            ],
            'a third part ending in a word' => [
                new RuleCondition(RuleCondition::VERSION, EnvironmentValues::JOOMLA_VERSION, '>=5.4.0banana'),
                'does not name a version',
            ],
            // An empty expression compiles, and `//` matches every value: an
            // exclusion whose body was lost in an edit would hold everywhere,
            // production included.
            'an empty expression' => [
                new RuleCondition(RuleCondition::REGEX, EnvironmentValues::HOST, ''),
                'matches every value',
            ],
            'a type outside the three' => [
                new RuleCondition('glob', EnvironmentValues::HOST, 'ecskc.*'),
                'not one of literal, regex or version',
            ],
            'an attribute outside the vocabulary' => [
                new RuleCondition(RuleCondition::LITERAL, 'DATABASE_PREFIX', 'jos_'),
                'DATABASE_PREFIX could not be determined',
            ],
        ];
    }

    /**
     * None of these knows anything about the environment, so none of them may answer
     * false. False is a claim, and the claim would be the author's own condition
     * quietly reversed.
     */
    #[DataProvider('unjudgeableConditions')]
    public function testAConditionThatCannotBeJudgedFaults(RuleCondition $condition, string $expected): void
    {
        $outcome = $this->outcome(new ConditionGroup(ConditionGroup::ALL, [$condition]));

        self::assertSame(ConditionOutcome::FAULTED, $outcome->status);
        self::assertStringContainsString($expected, $outcome->reasons[0]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedConstraints(): array
    {
        return [
            'digits and then a word'      => ['>=5banana'],
            'an empty part'               => ['>=5..4'],
            'a version and a stray word'  => ['>=5.4 extra'],
            'an operator in the version'  => ['>=5>=4'],
            'a slash in the version'      => ['>=5/4'],
            'a dotted part ending in a word' => ['>=5.4banana'],
            'a dotted part that is a word'   => ['>=5.4foo'],
            'a third part ending in a word'  => ['>=5.4.0banana'],
        ];
    }

    /**
     * Each of these is satisfied by 5.4.7 under version_compare, so before the
     * grammar was checked in full they MATCHED. Faulting is not enough on its own:
     * what matters is that an unreadable constraint cannot be the thing that lets a
     * write happen.
     */
    #[DataProvider('malformedConstraints')]
    public function testAMalformedConstraintNeverMatches(string $constraint): void
    {
        $group = new ConditionGroup(ConditionGroup::ALL, [
            self::condition(EnvironmentValues::JOOMLA_VERSION, $constraint, RuleCondition::VERSION),
        ]);

        self::assertFalse($this->outcome($group)->isMatched());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function realVersionShapes(): array
    {
        return [
            'two parts'              => ['>=8.2'],
            'three parts'            => ['>=5.4.0'],
            'a prerelease suffix'    => ['>=5.4.7-rc1'],
            'a build suffix'         => ['>=1.0.0+build.5'],
            // What PHP_VERSION actually holds on a distribution build.
            'a distribution build'   => ['>=8.1.2-1ubuntu2.14'],
        ];
    }

    /**
     * The other half of narrowing the grammar: it still has to accept the shapes
     * Joomla and PHP really report, or a legitimate constraint would fault and the
     * customisation would never apply.
     */
    #[DataProvider('realVersionShapes')]
    public function testARealVersionShapeIsStillJudged(string $constraint): void
    {
        $group = new ConditionGroup(ConditionGroup::ALL, [
            self::condition(EnvironmentValues::JOOMLA_VERSION, $constraint, RuleCondition::VERSION),
        ]);

        self::assertFalse($this->outcome($group)->isFaulted());
    }

    /**
     * The empty expression's own regression: before it faulted it MATCHED, which is
     * the direction that applies a customisation rather than withholding one.
     */
    public function testAnEmptyExpressionDoesNotMatchEveryHost(): void
    {
        $group = new ConditionGroup(ConditionGroup::ALL, [
            self::condition(EnvironmentValues::HOST, '', RuleCondition::REGEX),
        ]);

        self::assertFalse($this->outcome($group)->isMatched());
    }

    /**
     * Both are unreachable through the schema, which requires a match mode from two
     * names and at least one member. Neither is given an answer it cannot justify:
     * an empty group is vacuously true under one mode and false under the other.
     */
    public function testAGroupTheSchemaCouldNotProduceFaultsRatherThanGuessing(): void
    {
        $empty = $this->outcome(new ConditionGroup(ConditionGroup::ALL, []));
        $mode  = $this->outcome(new ConditionGroup('most', [
            self::condition(EnvironmentValues::HOST, 'ecskc.eu'),
        ]));

        self::assertSame(ConditionOutcome::FAULTED, $empty->status);
        self::assertStringContainsString('no members', $empty->reasons[0]);
        self::assertSame(ConditionOutcome::FAULTED, $mode->status);
        self::assertStringContainsString('neither "all" nor "any"', $mode->reasons[0]);
    }

    /**
     * A fault names the author's own description of the condition where there is
     * one, so the operator reading the report knows which rule went unanswered
     * rather than only which attribute.
     */
    public function testAFaultCarriesTheAuthorsDescription(): void
    {
        $group = new ConditionGroup(ConditionGroup::ALL, [
            new RuleCondition(
                RuleCondition::REGEX,
                EnvironmentValues::HOST,
                '^(?!ecskc\.eu$).*$',
                'Any host except production'
            ),
        ]);

        $outcome = $this->outcome($group, [EnvironmentValues::HOST => null]);

        self::assertStringContainsString('Any host except production', $outcome->reasons[0]);
    }
}
