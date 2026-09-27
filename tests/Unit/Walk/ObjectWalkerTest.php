<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Walk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition\ConditionEvaluator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentValues;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\Hasher;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path\PathPolicy;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleAction;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleLoader;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\SchemaValidator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\FreezeMarkers;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ActionSelection;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ObjectOutcome;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ObjectWalker;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ProposedOperation;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetState;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\WalkContext;
use YakShaver\ECSKCCustomisation\Tests\Support\RuleXml;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

/**
 * Every status here is produced from bytes actually on disk. A stubbed hasher would
 * make the walk agree with whatever the test asserted, which is the one thing these
 * tests must not be able to do: the whole status model is a comparison between the
 * file's own bytes and two declared hashes, so a test that supplies the comparison
 * result tests nothing.
 */
#[CoversClass(ObjectWalker::class)]
#[CoversClass(WalkContext::class)]
#[CoversClass(ActionSelection::class)]
#[CoversClass(TargetState::class)]
#[CoversClass(TargetStatus::class)]
#[CoversClass(ObjectOutcome::class)]
#[CoversClass(ProposedOperation::class)]
#[CoversClass(FreezeMarkers::class)]
final class ObjectWalkerTest extends TestCase
{
    private const PACKAGE = 'administrator/components/com_ecskccustom';

    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-walk-');
        $this->tree->write(self::PACKAGE . '/rules/index.html', '');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    private const ORIGINAL = "body { color: red; }\r\n.a { top: 0 }";
    private const FIRST    = "body { color: blue; }\r\n.a { top: 0 }";
    private const SECOND   = "body { color: green; }\r\n.a { top: 1 }";
    private const FOREIGN  = "body { color: rebeccapurple; }\r\n/* edited on the server */";

    private static function digest(string $bytes): string
    {
        return \hash('sha256', $bytes);
    }

    private static function environment(): EnvironmentValues
    {
        return new EnvironmentValues([
            EnvironmentValues::SITE_URL       => 'https://ecskc.eu',
            EnvironmentValues::HOST           => 'ecskc.eu',
            EnvironmentValues::JOOMLA_VERSION => '5.4.7',
            EnvironmentValues::PHP_VERSION    => '8.3.6',
        ]);
    }

    /**
     * @return array<string, TargetState> Keyed by target path.
     */
    private function walk(string $xml, ?EnvironmentValues $values = null): array
    {
        $this->tree->write(self::PACKAGE . '/rules/10-walk.xml', $xml);

        $loaded = (new RuleLoader(new SchemaValidator(
            \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/schema/patch.xsd'
        )))->load($this->tree->path(self::PACKAGE . '/rules'));

        self::assertSame([], $loaded->violations, 'the fixture rule file has to load cleanly');

        $walker = new ObjectWalker(
            new PathPolicy($this->tree->root(), $this->tree->path(self::PACKAGE)),
            new Hasher(),
            new ConditionEvaluator(),
            new FreezeMarkers(new PackagePaths($this->tree->path(self::PACKAGE)))
        );

        $states = [];

        foreach ($walker->walk($loaded->ruleSet, $values ?? self::environment()) as $state) {
            $states[$state->targetPath] = $state;
        }

        return $states;
    }

    private function walkOne(string $xml, ?EnvironmentValues $values = null): TargetState
    {
        $states = $this->walk($xml, $values);

        self::assertCount(1, $states);

        return \reset($states);
    }

    public function testATargetHoldingTheHeadPayloadIsOk(): void
    {
        $this->tree->write('templates/et_tennis/css/override.css', self::FIRST);

        $state = $this->walkOne(RuleXml::patch(RuleXml::object(
            version: 1,
            dest: 'templates/et_tennis/css/override.css',
            source: 'override.css.v1',
            sourceHash: self::digest(self::FIRST),
            create: true
        )));

        self::assertSame(TargetStatus::OK, $state->status);
        self::assertSame([], $state->operations());
        self::assertSame(self::digest(self::FIRST), $state->actual->digest);
        self::assertSame(self::digest(self::FIRST), $state->expected->digest);
    }

    public function testATargetAtItsExpectedOriginalIsActionableAndProposesTheCopy(): void
    {
        $this->tree->write('templates/et_tennis/css/vendor.css', self::ORIGINAL);

        $state = $this->walkOne(RuleXml::patch(RuleXml::object(
            version: 1,
            dest: 'templates/et_tennis/css/vendor.css',
            source: 'vendor.css.v1',
            sourceHash: self::digest(self::FIRST),
            expectedHash: self::digest(self::ORIGINAL)
        )));

        self::assertSame(TargetStatus::ACTIONABLE, $state->status);
        self::assertCount(1, $state->operations());

        $operation = $state->operations()[0];

        self::assertTrue($operation->isCopy());
        self::assertSame('vendor.css.v1', $operation->payloadSource);
        self::assertSame(self::digest(self::FIRST), $operation->payloadHash);
        self::assertSame(self::digest(self::ORIGINAL), $state->expected->digest);
    }

    /**
     * Security review v1: a path decided at report time and trusted at write time is
     * a window anything able to swap a path under the site root can use. The proposal
     * therefore carries the root-relative target and nothing an executor could move
     * to without asking path policy again.
     */
    public function testAProposedOperationCarriesNoResolvedPath(): void
    {
        $this->tree->write('templates/et_tennis/css/vendor.css', self::ORIGINAL);

        $state = $this->walkOne(RuleXml::patch(RuleXml::object(
            version: 1,
            dest: 'templates/et_tennis/css/vendor.css',
            source: 'vendor.css.v1',
            sourceHash: self::digest(self::FIRST),
            expectedHash: self::digest(self::ORIGINAL)
        )));

        $operation = $state->operations()[0];

        self::assertSame('templates/et_tennis/css/vendor.css', $operation->targetPath);

        foreach (\get_object_vars($operation) as $name => $value) {
            self::assertThat(
                \is_string($value) && \str_contains($value, $this->tree->root()),
                self::isFalse(),
                \sprintf('%s carries an absolute path resolved before the write', $name)
            );
        }
    }

    public function testAChainAdvancesThroughEveryRevisionInOneRun(): void
    {
        $this->tree->write('templates/et_tennis/css/chain.css', self::ORIGINAL);

        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/chain.css',
                source: 'chain.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/chain.css',
                source: 'chain.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FIRST)
            )
        ));

        self::assertSame(TargetStatus::ACTIONABLE, $state->status);
        self::assertCount(2, $state->operations());
        self::assertSame([1, 2], \array_map(
            static fn (ProposedOperation $operation): int => $operation->version,
            $state->operations()
        ));
    }

    public function testAChainAlreadyAtItsHeadIsOkWhateverStandsBehindIt(): void
    {
        $this->tree->write('templates/et_tennis/css/chain.css', self::SECOND);

        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/chain.css',
                source: 'chain.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/chain.css',
                source: 'chain.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FIRST)
            )
        ));

        self::assertSame(TargetStatus::OK, $state->status);
        self::assertSame([], $state->operations());
        self::assertSame(ObjectOutcome::NOT_MATCHED, $state->objectOutcomes()[0]->result);
        self::assertSame(ObjectOutcome::AT_PAYLOAD, $state->objectOutcomes()[1]->result);
    }

    /**
     * The shape the build warns about: a chain whose first object may not create.
     * Nothing is wrong with the site and nothing is wrong with the bytes, so the
     * target is not applicable rather than blocked.
     */
    public function testAChainThatCannotAdvanceFromAMissingFileIsNotApplicable(): void
    {
        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/absent.css',
                source: 'absent.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: false
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/absent.css',
                source: 'absent.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FIRST)
            )
        ));

        self::assertSame(TargetStatus::NOT_APPLICABLE, $state->status);
        self::assertSame([], $state->operations());
        self::assertStringContainsString('is not allowed to create it', $state->objectOutcomes()[0]->reason);
        self::assertTrue($state->actual->isAbsent());
    }

    public function testADisabledObjectPartWayAlongAChainStrandsAFreshInstall(): void
    {
        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/gap.css',
                source: 'gap.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: true,
                enable: false
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/gap.css',
                source: 'gap.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FIRST)
            )
        ));

        self::assertSame(TargetStatus::INCOMPLETE, $state->status);
        self::assertSame([], $state->operations());
        self::assertSame(ObjectOutcome::DISABLED, $state->objectOutcomes()[0]->result);
        self::assertTrue($state->objectOutcomes()[0]->gatesLaterObjects);
    }

    /**
     * Disabling the last object at a path is how a customisation is switched off.
     * Nothing follows it, so nothing is stranded.
     */
    public function testDisablingTheLastObjectStrandsNothing(): void
    {
        $state = $this->walkOne(RuleXml::patch(RuleXml::object(
            version: 1,
            dest: 'templates/et_tennis/css/off.css',
            source: 'off.css.v1',
            sourceHash: self::digest(self::FIRST),
            create: true,
            enable: false
        )));

        self::assertSame(TargetStatus::NOT_APPLICABLE, $state->status);
        self::assertFalse($state->objectOutcomes()[0]->gatesLaterObjects);
    }

    public function testAnObjectExcludedFromThisHostIsNotApplicable(): void
    {
        $this->tree->write('templates/et_tennis/css/staging-only.css', self::ORIGINAL);

        $state = $this->walkOne(RuleXml::patch(RuleXml::object(
            version: 1,
            dest: 'templates/et_tennis/css/staging-only.css',
            source: 'staging-only.css.v1',
            sourceHash: self::digest(self::FIRST),
            expectedHash: self::digest(self::ORIGINAL),
            conditions: RuleXml::conditions('HOST', 'staging.ecskc.eu')
        )));

        self::assertSame(TargetStatus::NOT_APPLICABLE, $state->status);
        self::assertSame([], $state->operations());
        self::assertSame(ObjectOutcome::CONDITIONS_UNMET, $state->objectOutcomes()[0]->result);
    }

    /**
     * The newest revision is written for another environment and nothing depends on
     * it. The file holds what the rule set writes here, which is what `ok` means, and
     * the qualifier carries the reason instead of an alarm the operator cannot act on.
     */
    public function testAnUnmetConditionOnTheHeadIsOkAndSaysSo(): void
    {
        $this->tree->write('templates/et_tennis/css/gated.css', self::FIRST);

        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/gated.css',
                source: 'gated.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: true
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/gated.css',
                source: 'gated.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FIRST),
                conditions: RuleXml::conditions('HOST', 'staging.ecskc.eu')
            )
        ));

        self::assertSame(TargetStatus::OK, $state->status);
        self::assertSame('ok (unmet)', $state->describe());
        self::assertSame([], $state->operations());
        self::assertStringContainsString('version 2', $state->reason);
        self::assertFalse($state->objectOutcomes()[1]->gatesLaterObjects);
    }

    /**
     * The same skip strands the target once something depends on the revision it
     * stopped at. Here version 3 expects what version 2 writes, and version 2 does not
     * run on this host, so the head cannot be reached and the rule set needs attention.
     */
    public function testAnUnmetConditionThatStrandsALaterRevisionIsIncomplete(): void
    {
        $this->tree->write('templates/et_tennis/css/gated.css', self::FIRST);

        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/gated.css',
                source: 'gated.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: true
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/gated.css',
                source: 'gated.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FIRST),
                conditions: RuleXml::conditions('HOST', 'staging.ecskc.eu')
            )
            . RuleXml::object(
                version: 3,
                dest: 'templates/et_tennis/css/gated.css',
                source: 'gated.css.v3',
                sourceHash: self::digest(self::FOREIGN),
                expectedHash: self::digest(self::SECOND)
            )
        ));

        self::assertSame(TargetStatus::INCOMPLETE, $state->status);
        self::assertSame('incomplete', $state->describe());
        self::assertStringContainsString('version 1', $state->reason);
        self::assertTrue($state->objectOutcomes()[1]->gatesLaterObjects);
    }

    /**
     * A package-owned chain that forks: version 2 writes one payload on this host and
     * another elsewhere, and version 3 only knows the other branch. The walk reaches
     * version 2 and the head is out of reach from there. Review s1.v24, spec finding 1.
     */
    public function testASettledChainThatCannotReachItsHeadIsIncompleteNotOk(): void
    {
        $this->tree->write('templates/et_tennis/css/forked.css', self::SECOND);

        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/forked.css',
                source: 'forked.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: true,
                replace: false
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/forked.css',
                source: 'forked.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FIRST)
            )
            . RuleXml::object(
                version: 3,
                dest: 'templates/et_tennis/css/forked.css',
                source: 'forked.css.v3',
                sourceHash: self::digest(self::FOREIGN),
                expectedHash: self::digest(self::ORIGINAL)
            )
        ));

        self::assertSame(TargetStatus::INCOMPLETE, $state->status);
        self::assertStringContainsString('version 2', $state->reason);
        self::assertSame(ObjectOutcome::AT_PAYLOAD, $state->objectOutcomes()[1]->result);
        self::assertSame(ObjectOutcome::NOT_MATCHED, $state->objectOutcomes()[2]->result);
    }

    /**
     * The same shape one step earlier: an actionable first revision whose successor
     * the walk cannot reach. There is work to do and the head is still out of reach,
     * and of the two facts the second is the one the operator has to act on.
     */
    public function testAChainThatCanAdvanceButNotToItsHeadIsIncompleteNotActionable(): void
    {
        $this->tree->write('templates/et_tennis/css/forked.css', self::ORIGINAL);

        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/forked.css',
                source: 'forked.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: true,
                expectedHash: self::digest(self::ORIGINAL)
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/forked.css',
                source: 'forked.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FOREIGN)
            )
        ));

        self::assertSame(TargetStatus::INCOMPLETE, $state->status);
        self::assertCount(1, $state->operations(), 'the work version 1 can do is still described');
    }

    /**
     * A digest two objects write counts from the first of them. Version 3 expects
     * what version 2 writes, and version 4 writes the same bytes again later, so
     * version 3 is a chain step the walk cannot reach rather than an alternative.
     */
    public function testARepeatedPayloadCountsAsWrittenFromItsFirstPosition(): void
    {
        $this->tree->write('templates/et_tennis/css/vendor.css', self::FIRST);

        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/vendor.css',
                source: 'vendor.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/vendor.css',
                source: 'vendor.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FOREIGN)
            )
            . RuleXml::object(
                version: 3,
                dest: 'templates/et_tennis/css/vendor.css',
                source: 'vendor.css.v3',
                sourceHash: self::digest('version 3'),
                expectedHash: self::digest(self::SECOND)
            )
            . RuleXml::object(
                version: 4,
                dest: 'templates/et_tennis/css/vendor.css',
                source: 'vendor.css.v4',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FOREIGN)
            )
        ));

        self::assertSame(TargetStatus::INCOMPLETE, $state->status);
        self::assertStringContainsString('can be updated to version 1, but not to the latest version', $state->reason);
    }

    /**
     * An object is never its own predecessor. Version 2 expects the bytes it writes
     * itself, and nothing before it writes them, so it is an alternative the walk
     * passes over, not a step that leaves the head out of reach.
     */
    public function testAnObjectsOwnPayloadDoesNotCountAsWrittenBeforeIt(): void
    {
        $this->tree->write('templates/et_tennis/css/vendor.css', self::FIRST);

        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/vendor.css',
                source: 'vendor.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/vendor.css',
                source: 'vendor.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::SECOND)
            )
        ));

        self::assertSame(ObjectOutcome::NOT_MATCHED, $state->objectOutcomes()[1]->result);
        self::assertSame(TargetStatus::OK, $state->status);
    }

    /**
     * A disabled vendor alternative is not a gap in a chain. Nothing expects what it
     * writes, so the target is simply not customised on this vendor build. Review
     * s1.v24, spec finding 3.
     */
    public function testADisabledVendorAlternativeIsNotApplicableRatherThanIncomplete(): void
    {
        $this->tree->write('templates/et_tennis/css/vendor.css', self::ORIGINAL);

        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/vendor.css',
                source: 'vendor.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL),
                enable: false
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/vendor.css',
                source: 'vendor.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FOREIGN)
            )
        ));

        self::assertSame(TargetStatus::NOT_APPLICABLE, $state->status);
        self::assertSame('not applicable (disabled)', $state->describe());
        self::assertSame([], $state->operations());
        self::assertFalse($state->objectOutcomes()[0]->gatesLaterObjects);
        self::assertStringContainsString('switched off', $state->reason);
    }

    public function testADisabledHeadIsHowACustomisationIsSwitchedOff(): void
    {
        $this->tree->write('templates/et_tennis/css/gap.css', self::FIRST);

        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/gap.css',
                source: 'gap.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: true
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/gap.css',
                source: 'gap.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FIRST),
                enable: false
            )
        ));

        self::assertSame(TargetStatus::OK, $state->status);
        self::assertSame('ok (disabled)', $state->describe());
        self::assertSame([], $state->operations());
        self::assertStringContainsString('version 2', $state->reason);
        self::assertSame(ObjectOutcome::DISABLED, $state->objectOutcomes()[1]->result);
        self::assertFalse($state->objectOutcomes()[1]->gatesLaterObjects);
    }

    public function testASkippedHeadKeepsTheStatusInTheClosedVocabulary(): void
    {
        $this->tree->write('templates/et_tennis/css/gap.css', self::FIRST);

        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/gap.css',
                source: 'gap.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: true
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/gap.css',
                source: 'gap.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FIRST),
                enable: false
            )
        ));

        self::assertContains($state->status, TargetStatus::ALL);
        self::assertSame(TargetState::DISABLED, $state->qualifier);
    }

    /**
     * A barrier is a barrier until something gets past it. A rule set may link a later
     * object to any earlier payload, so a skipped step does not settle the walk's
     * result on its own. Review s1.v26, spec finding 1.
     */
    public function testAConditionGateIsLiftedWhenALaterObjectReachesTheHead(): void
    {
        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/lifted.css',
                source: 'lifted.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: true
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/lifted.css',
                source: 'lifted.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FIRST),
                conditions: RuleXml::conditions('HOST', 'staging.ecskc.eu')
            )
            . RuleXml::object(
                version: 3,
                dest: 'templates/et_tennis/css/lifted.css',
                source: 'lifted.css.v3',
                sourceHash: self::digest(self::FOREIGN),
                expectedHash: self::digest(self::SECOND)
            )
            . RuleXml::object(
                version: 4,
                dest: 'templates/et_tennis/css/lifted.css',
                source: 'lifted.css.v4',
                sourceHash: self::digest(self::ORIGINAL),
                expectedHash: self::digest(self::FIRST)
            )
        ));

        self::assertSame(TargetStatus::ACTIONABLE, $state->status);
        self::assertSame('actionable', $state->describe());
        self::assertNull($state->qualifier, 'the skip no longer explains where the walk stands');
        self::assertCount(2, $state->operations());
        self::assertFalse($state->objectOutcomes()[1]->gatesLaterObjects);
        self::assertStringContainsString('a later version moved the file past it', $state->objectOutcomes()[1]->reason);
    }

    public function testAnUnmatchedStepGateIsLiftedWhenALaterObjectReachesTheHead(): void
    {
        $this->tree->write('templates/et_tennis/css/lifted.css', self::ORIGINAL);

        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/lifted.css',
                source: 'lifted.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL),
                create: true
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/lifted.css',
                source: 'lifted.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FOREIGN)
            )
            . RuleXml::object(
                version: 3,
                dest: 'templates/et_tennis/css/lifted.css',
                source: 'lifted.css.v3',
                sourceHash: self::digest(self::FOREIGN),
                expectedHash: self::digest(self::FIRST)
            )
        ));

        self::assertSame(TargetStatus::ACTIONABLE, $state->status);
        self::assertSame(ObjectOutcome::NOT_MATCHED, $state->objectOutcomes()[1]->result);
        self::assertFalse($state->objectOutcomes()[1]->gatesLaterObjects);
    }

    public function testADisabledGateIsLiftedWhenALaterObjectReachesTheHead(): void
    {
        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/lifted.css',
                source: 'lifted.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: true
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/lifted.css',
                source: 'lifted.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FIRST),
                enable: false
            )
            . RuleXml::object(
                version: 3,
                dest: 'templates/et_tennis/css/lifted.css',
                source: 'lifted.css.v3',
                sourceHash: self::digest(self::FOREIGN),
                expectedHash: self::digest(self::SECOND)
            )
            . RuleXml::object(
                version: 4,
                dest: 'templates/et_tennis/css/lifted.css',
                source: 'lifted.css.v4',
                sourceHash: self::digest(self::ORIGINAL),
                expectedHash: self::digest(self::FIRST)
            )
        ));

        self::assertSame(TargetStatus::ACTIONABLE, $state->status);
        self::assertFalse($state->objectOutcomes()[1]->gatesLaterObjects, 'the disabled step');
        self::assertFalse($state->objectOutcomes()[2]->gatesLaterObjects, 'the step it stranded');
    }

    public function testAVendorFileTheVendorChangedIsBlocked(): void
    {
        $this->tree->write('templates/et_tennis/css/vendor.css', self::FOREIGN);

        $state = $this->walkOne(RuleXml::patch(RuleXml::object(
            version: 1,
            dest: 'templates/et_tennis/css/vendor.css',
            source: 'vendor.css.v1',
            sourceHash: self::digest(self::FIRST),
            expectedHash: self::digest(self::ORIGINAL)
        )));

        self::assertSame(TargetStatus::BLOCKED, $state->status);
        self::assertSame([], $state->operations());
        self::assertSame(self::digest(self::ORIGINAL), $state->expected->digest);
        self::assertSame(self::digest(self::FOREIGN), $state->actual->digest);
    }

    /**
     * Vendor alternatives are independent by nature, so one that describes a build
     * this site does not run says nothing about the build it does.
     */
    public function testAVendorAlternativeForAnotherBuildDoesNotMakeTheTargetIncomplete(): void
    {
        $this->tree->write('templates/et_tennis/css/vendor.css', self::FIRST);

        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/vendor.css',
                source: 'vendor.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/vendor.css',
                source: 'vendor.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FOREIGN)
            )
        ));

        self::assertSame(TargetStatus::OK, $state->status);
        self::assertSame([], $state->operations());
    }

    public function testARemovalWhoseTargetIsAlreadyGoneIsOk(): void
    {
        $state = $this->walkOne(RuleXml::patch(RuleXml::object(
            version: 1,
            dest: 'plugins/system/helixultimate/legacy.php'
        )));

        self::assertSame(TargetStatus::OK, $state->status);
        self::assertSame([], $state->operations());
        self::assertTrue($state->expected->isAbsent());
        self::assertTrue($state->actual->isAbsent());
    }

    public function testARemovalOfTheFileItWasWrittenForIsActionable(): void
    {
        $this->tree->write('plugins/system/helixultimate/legacy.php', self::ORIGINAL);

        $state = $this->walkOne(RuleXml::patch(RuleXml::object(
            version: 1,
            dest: 'plugins/system/helixultimate/legacy.php',
            expectedHash: self::digest(self::ORIGINAL)
        )));

        self::assertSame(TargetStatus::ACTIONABLE, $state->status);
        self::assertFalse($state->operations()[0]->isCopy());
        self::assertTrue($state->operations()[0]->backup);
    }

    public function testARemovalOfSomeOtherFileIsBlocked(): void
    {
        $this->tree->write('plugins/system/helixultimate/legacy.php', self::FOREIGN);

        $state = $this->walkOne(RuleXml::patch(RuleXml::object(
            version: 1,
            dest: 'plugins/system/helixultimate/legacy.php',
            expectedHash: self::digest(self::ORIGINAL)
        )));

        self::assertSame(TargetStatus::BLOCKED, $state->status);
        self::assertSame([], $state->operations());
    }

    /**
     * An object whose permissions refuse the write on a chain that has already
     * advanced stops the chain short and makes the target incomplete.
     */
    public function testAChainStoppedByPermissionsIsIncomplete(): void
    {
        $state = $this->walkOne(RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/perm.css',
                source: 'perm.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: true
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/perm.css',
                source: 'perm.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FIRST),
                replace: false
            )
        ));

        self::assertSame(TargetStatus::INCOMPLETE, $state->status);
        self::assertSame(ObjectOutcome::NOT_APPLICABLE, $state->objectOutcomes()[1]->result);
        self::assertStringContainsString('is not allowed to replace it', $state->objectOutcomes()[1]->reason);
    }

    /**
     * A single object with replace=false on a file that matches its expected original
     * is not applicable: nothing else depends on it, and the operator chose not to let
     * it write. This exercises the refusedByPermit path through settle().
     */
    public function testAnObjectThatMayNotReplaceIsNotApplicable(): void
    {
        $this->tree->write('templates/et_tennis/css/readonly.css', self::ORIGINAL);

        $state = $this->walkOne(RuleXml::patch(RuleXml::object(
            version: 1,
            dest: 'templates/et_tennis/css/readonly.css',
            source: 'readonly.css.v1',
            sourceHash: self::digest(self::FIRST),
            expectedHash: self::digest(self::ORIGINAL),
            replace: false
        )));

        self::assertSame(TargetStatus::NOT_APPLICABLE, $state->status);
        self::assertStringContainsString('is allowed to make the change', $state->reason);
    }

    public function testATargetPathPolicyRefusesIsFaultedNotBlocked(): void
    {
        $this->tree->write('configuration.php', '<?php // the site configuration');

        $state = $this->walkOne(RuleXml::patch(RuleXml::object(
            version: 1,
            dest: 'configuration.php',
            source: 'configuration.php.v1',
            sourceHash: self::digest(self::FIRST)
        )));

        self::assertSame(TargetStatus::FAULTED, $state->status);
        self::assertSame([], $state->operations());
        self::assertStringContainsString('the package may not write to this path', $state->reason);
        self::assertNull($state->actual->digest);
        self::assertNotNull($state->actual->reason);
    }

    public function testATargetThatCannotBeReadIsFaultedNotBlocked(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('root reads a file whatever its mode says.');
        }

        $path = $this->tree->write('templates/et_tennis/css/locked.css', self::ORIGINAL);
        \chmod($path, 0o000);

        try {
            $state = $this->walkOne(RuleXml::patch(RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/locked.css',
                source: 'locked.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            )));
        } finally {
            \chmod($path, 0o644);
        }

        self::assertSame(TargetStatus::FAULTED, $state->status);
        self::assertStringContainsString('unreadable', $state->reason);
    }

    public function testAFrozenPathReportsTheStateItWouldOtherwiseHaveAndProposesNothing(): void
    {
        $this->tree->write('templates/et_tennis/css/held.css', self::ORIGINAL);
        $this->tree->write(
            self::PACKAGE . '/backup/templates/et_tennis/css/held.css' . PackagePaths::FREEZE_MARKER,
            ''
        );

        $state = $this->walkOne(RuleXml::patch(RuleXml::object(
            version: 1,
            dest: 'templates/et_tennis/css/held.css',
            source: 'held.css.v1',
            sourceHash: self::digest(self::FIRST),
            expectedHash: self::digest(self::ORIGINAL)
        )));

        self::assertSame(TargetStatus::FROZEN, $state->status);
        self::assertSame('frozen (would be actionable)', $state->describe());
        self::assertSame([], $state->operations());

        $underlying = $state->underlying();

        self::assertNotNull($underlying);
        self::assertSame(TargetStatus::ACTIONABLE, $underlying->status);
        self::assertCount(1, $underlying->operations());
    }

    public function testAMarkerThatIsADanglingSymbolicLinkDoesNotHoldThePath(): void
    {
        $this->tree->write('templates/et_tennis/css/held.css', self::ORIGINAL);
        $this->tree->symlink(
            self::PACKAGE . '/backup/templates/et_tennis/css/held.css' . PackagePaths::FREEZE_MARKER,
            $this->tree->root() . '/nothing-is-here'
        );

        $state = $this->walkOne(RuleXml::patch(RuleXml::object(
            version: 1,
            dest: 'templates/et_tennis/css/held.css',
            source: 'held.css.v1',
            sourceHash: self::digest(self::FIRST),
            expectedHash: self::digest(self::ORIGINAL)
        )));

        self::assertSame(TargetStatus::ACTIONABLE, $state->status);
        self::assertCount(1, $state->operations());
    }

    public function testAnObjectWhoseConditionsFaultedProposesNothingAndChangesNoFile(): void
    {
        $this->tree->write('templates/et_tennis/css/undetermined.css', self::ORIGINAL);

        $xml = RuleXml::patch(RuleXml::object(
            version: 1,
            dest: 'templates/et_tennis/css/undetermined.css',
            source: 'undetermined.css.v1',
            sourceHash: self::digest(self::FIRST),
            expectedHash: self::digest(self::ORIGINAL),
            conditions: RuleXml::conditions('HOST', 'ecskc\.eu', 'regex')
        ));

        // Written before the snapshot, because the walk is handed a rule set that is
        // already installed. Everything the snapshot watches after this is the site.
        $this->tree->write(self::PACKAGE . '/rules/10-walk.xml', $xml);

        $before = $this->snapshot();

        $state = $this->walkOne(
            $xml,
            new EnvironmentValues([], [EnvironmentValues::HOST => 'the request reported no host'])
        );

        self::assertSame(TargetStatus::FAULTED, $state->status);
        self::assertSame([], $state->operations());
        self::assertSame(ObjectOutcome::FAULTED, $state->objectOutcomes()[0]->result);
        self::assertStringContainsString('the request reported no host', $state->reason);

        self::assertSame($before, $this->snapshot());
    }

    /**
     * A fault part way along a chain drops what earlier objects proposed. A target
     * that halts writes nothing at all, rather than the part of itself that was
     * judged before the fault.
     */
    public function testAFaultPartWayAlongAChainDropsWhatEarlierObjectsProposed(): void
    {
        $this->tree->write('templates/et_tennis/css/halted.css', self::ORIGINAL);

        $state = $this->walkOne(
            RuleXml::patch(
                RuleXml::object(
                    version: 1,
                    dest: 'templates/et_tennis/css/halted.css',
                    source: 'halted.css.v1',
                    sourceHash: self::digest(self::FIRST),
                    expectedHash: self::digest(self::ORIGINAL)
                )
                . RuleXml::object(
                    version: 2,
                    dest: 'templates/et_tennis/css/halted.css',
                    source: 'halted.css.v2',
                    sourceHash: self::digest(self::SECOND),
                    expectedHash: self::digest(self::FIRST),
                    conditions: RuleXml::conditions('PHP_VERSION', '>=8.3.0', 'version')
                )
            ),
            new EnvironmentValues(
                [
                    EnvironmentValues::SITE_URL       => 'https://ecskc.eu',
                    EnvironmentValues::HOST           => 'ecskc.eu',
                    EnvironmentValues::JOOMLA_VERSION => '5.4.7',
                ],
                [EnvironmentValues::PHP_VERSION => 'the runtime reported no version']
            )
        );

        self::assertSame(TargetStatus::FAULTED, $state->status);
        self::assertSame([], $state->operations());
        self::assertSame(ObjectOutcome::WOULD_APPLY, $state->objectOutcomes()[0]->result);
    }

    /**
     * The whole vocabulary, in one run, on one tree. A status the walk cannot
     * produce is a status the report can promise and never show.
     */
    public function testEveryTargetStatusIsReachableInOneRun(): void
    {
        $states = $this->walk($this->everyStatusRuleSet(), $this->partlyUndeterminedEnvironment());

        self::assertSame(
            \array_values(TargetStatus::ALL),
            \array_values(\array_unique(\array_map(
                static fn (TargetState $state): string => $state->status,
                \array_values($states)
            ))),
            'the walk has to produce all seven statuses, in the order the fixture lays them out'
        );
    }

    /**
     * Report only. The walk resolves, hashes and reads a freeze marker, and that is
     * the whole of its contact with the site.
     */
    public function testTheWalkCreatesChangesAndRemovesNoFile(): void
    {
        $this->tree->write(self::PACKAGE . '/rules/10-walk.xml', $this->everyStatusRuleSet());

        $before = $this->snapshot();

        $states = $this->walk($this->everyStatusRuleSet(), $this->partlyUndeterminedEnvironment());

        self::assertSame($before, $this->snapshot());

        // The proof is only worth what the run proposed. A snapshot over a fixture
        // that proposed nothing to create and nothing to remove would pass while both
        // paths went unexercised.
        $proposed = [];

        foreach ($states as $state) {
            foreach ($state->operations() as $operation) {
                $proposed[] = $operation->operation . ' ' . $operation->targetPath;
            }
        }

        self::assertContains('copy templates/et_tennis/css/created.css', $proposed);
        self::assertContains('remove plugins/system/helixultimate/comingsoon.php', $proposed);
        self::assertFileDoesNotExist($this->tree->path('templates/et_tennis/css/created.css'));
        self::assertFileExists($this->tree->path('plugins/system/helixultimate/comingsoon.php'));
    }

    /**
     * One target per status, laid out in the order TargetStatus declares them.
     */
    private function everyStatusRuleSet(): string
    {
        $this->tree->write('templates/et_tennis/css/ok.css', self::FIRST);
        $this->tree->write('templates/et_tennis/css/actionable.css', self::ORIGINAL);
        $this->tree->write('templates/et_tennis/css/incomplete.css', self::FIRST);
        $this->tree->write('templates/et_tennis/css/blocked.css', self::FOREIGN);
        $this->tree->write('templates/et_tennis/css/frozen.css', self::ORIGINAL);
        $this->tree->write('templates/et_tennis/css/faulted.css', self::ORIGINAL);
        $this->tree->write('plugins/system/helixultimate/comingsoon.php', self::ORIGINAL);
        $this->tree->write(
            self::PACKAGE . '/backup/templates/et_tennis/css/frozen.css' . PackagePaths::FREEZE_MARKER,
            ''
        );

        // templates/et_tennis/css/created.css is deliberately absent. A proposal to
        // create and a proposal to remove are the two operations that would show up in
        // the snapshot as a new path and a missing one, so the no-write proof needs
        // both under it and not only an overwrite.

        $elsewhere = RuleXml::conditions('HOST', 'staging.ecskc.eu');

        return RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/ok.css',
                source: 'ok.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: true
            )
            . RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/actionable.css',
                source: 'actionable.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            )
            . RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/incomplete.css',
                source: 'incomplete.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: true
            )
            . RuleXml::object(
                version: 2,
                dest: 'templates/et_tennis/css/incomplete.css',
                source: 'incomplete.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FIRST),
                conditions: $elsewhere
            )
            // Version 3 is what makes version 2's unmet condition a gap rather than an
            // answer: it expects what version 2 writes, so the head is out of reach on
            // this host. Without it the target would report ok (unmet).
            . RuleXml::object(
                version: 3,
                dest: 'templates/et_tennis/css/incomplete.css',
                source: 'incomplete.css.v3',
                sourceHash: self::digest(self::FOREIGN),
                expectedHash: self::digest(self::SECOND)
            )
            . RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/blocked.css',
                source: 'blocked.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            )
            . RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/absent.css',
                source: 'absent.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: false
            )
            . RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/frozen.css',
                source: 'frozen.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            )
            . RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/faulted.css',
                source: 'faulted.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL),
                conditions: RuleXml::conditions('JOOMLA_VERSION', '>=5.0.0', 'version')
            )
            . RuleXml::object(
                version: 1,
                dest: 'templates/et_tennis/css/created.css',
                source: 'created.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: true
            )
            . RuleXml::object(
                version: 1,
                dest: 'plugins/system/helixultimate/comingsoon.php',
                expectedHash: self::digest(self::ORIGINAL)
            )
        );
    }

    private function partlyUndeterminedEnvironment(): EnvironmentValues
    {
        return new EnvironmentValues(
            [
                EnvironmentValues::SITE_URL    => 'https://ecskc.eu',
                EnvironmentValues::HOST        => 'ecskc.eu',
                EnvironmentValues::PHP_VERSION => '8.3.6',
            ],
            [EnvironmentValues::JOOMLA_VERSION => 'Joomla did not report its version']
        );
    }

    public function testActionSelectionDistinguishesOutcomes(): void
    {
        $action = RuleAction::remove(false, null);
        $selected = ActionSelection::selected($action);
        self::assertTrue($selected->isSelected());
        self::assertFalse($selected->isUnmet());
        self::assertFalse($selected->isFaulted());
        self::assertSame($action, $selected->action);
        self::assertNull($selected->faultReason);

        $unmet = ActionSelection::unmet();
        self::assertFalse($unmet->isSelected());
        self::assertTrue($unmet->isUnmet());
        self::assertFalse($unmet->isFaulted());
        self::assertNull($unmet->action);
        self::assertNull($unmet->faultReason);

        $faulted = ActionSelection::faulted('fault reason');
        self::assertFalse($faulted->isSelected());
        self::assertFalse($faulted->isUnmet());
        self::assertTrue($faulted->isFaulted());
        self::assertNull($faulted->action);
        self::assertSame('fault reason', $faulted->faultReason);
    }

    /**
     * Every path under the tree with its bytes and its mode.
     *
     * Directories are included, so a walk that created one and left it empty would
     * still be caught, and the digest is of the bytes rather than the size, so a
     * same-length rewrite cannot slip past.
     *
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $found = [];
        $root  = $this->tree->root();

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $relative = \substr($entry->getPathname(), \strlen($root) + 1);

            $found[$relative] = $entry->isDir()
                ? \sprintf('dir %o', $entry->getPerms())
                : \sprintf('file %o %s', $entry->getPerms(), \hash_file('sha256', $entry->getPathname()));
        }

        \ksort($found);

        return $found;
    }
}
