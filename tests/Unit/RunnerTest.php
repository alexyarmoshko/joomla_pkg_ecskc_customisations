<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition\ConditionEvaluator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentProviderInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentResolver;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\RawEnvironment;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\Hasher;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path\PathPolicy;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalCheck;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalChecks;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\ReportedValue;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunCounts;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\MergedSetValidator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleLoader;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\SchemaValidator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\FreezeMarkers;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\ReportOnlyHistory;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteHistoryInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ObjectWalker;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
use YakShaver\ECSKCCustomisation\Tests\Support\RuleXml;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

/**
 * The runner assembled as the service provider assembles it, over a real temporary
 * site tree, with the environment provider replaced.
 *
 * Only the provider is replaced, because it is the one collaborator that reads
 * Joomla rather than the filesystem. Everything else is the production object: the
 * rule set is loaded from files an author could have written, the hashes come from
 * bytes on disk, and the report is built from what the walk actually found. A
 * stubbed walk would let this test assert whatever it liked about a report shape
 * nothing produces.
 */
#[CoversClass(Runner::class)]
#[CoversClass(RunReport::class)]
#[CoversClass(RunCounts::class)]
#[CoversClass(TargetReport::class)]
#[CoversClass(ReportOnlyHistory::class)]
final class RunnerTest extends TestCase
{
    private const PACKAGE = 'administrator/components/com_ecskccustom';

    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-runner-');
        $this->tree->write(self::PACKAGE . '/rules/index.html', '');
        $this->tree->write(self::PACKAGE . '/payload/index.html', '');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    // Decision 14: CRLF and the missing final newline are part of a payload's
    // identity, so the fixtures carry them rather than being tidied.
    private const ORIGINAL = "body { color: red; }\r\n.a { top: 0 }";
    private const FIRST    = "body { color: blue; }\r\n.a { top: 0 }";
    private const SECOND   = "body { color: green; }\r\n.a { top: 1 }";
    private const FOREIGN  = "body { color: rebeccapurple; }\r\n/* edited on the server */";

    private static function digest(string $bytes): string
    {
        return \hash('sha256', $bytes);
    }

    public function testEveryRequiredFieldIsPresentOnABlockedIncompleteAndFaultedTarget(): void
    {
        $targets = $this->report()->targets();

        foreach ([TargetStatus::BLOCKED, TargetStatus::INCOMPLETE, TargetStatus::FAULTED] as $status) {
            $target = $this->withStatus($targets, $status);
            $fields = $target->requiredFields();

            self::assertSame(
                TargetReport::REQUIRED_FIELDS,
                \array_keys($fields),
                \sprintf('a %s target has to carry every required field', $status)
            );

            foreach ($fields as $name => $value) {
                self::assertNotSame(
                    '',
                    $value,
                    \sprintf('the %s field of a %s target is empty', $name, $status)
                );
            }
        }
    }

    /**
     * "Where a hash is unavailable the field carries the reason instead of a hash -
     * do not omit the field."
     */
    public function testAFieldThatCannotBeComputedCarriesItsReason(): void
    {
        $faulted = $this->withStatus($this->report()->targets(), TargetStatus::FAULTED);

        self::assertNull($faulted->state->expected->digest);
        self::assertSame(
            $faulted->state->expected->reason,
            $faulted->requiredFields()[TargetReport::EXPECTED_HASH],
            'the expected-hash field of a faulted target has to hold the reason there is no hash'
        );
        self::assertStringContainsString('Joomla did not report its version', $faulted->reason());
    }

    /**
     * Phase 1 has no write path, so the field is a determined negative rather than a
     * fault: there is no record to read and the report says why.
     */
    public function testTheLastPackageWriteFieldSaysWhyThereIsNone(): void
    {
        $report = $this->report();
        $target = $this->withStatus($report->targets(), TargetStatus::BLOCKED);

        self::assertFalse($target->lastPackageWrite->isKnown());
        self::assertTrue($target->lastPackageWrite->isDetermined());
        self::assertStringContainsString(
            'written nothing at any target',
            $target->requiredFields()[TargetReport::LAST_PACKAGE_WRITE]
        );

        self::assertFalse($report->wasInterrupted());
        self::assertTrue($report->interruption->isDetermined());
        self::assertStringContainsString('no run marker', $report->interruption->describe());
    }

    /**
     * A check displaces nothing, so the backup field carries that rather than
     * disappearing from a report-only line.
     */
    public function testTheBackupFieldSaysWhyACheckWritesNone(): void
    {
        $target = $this->withStatus($this->report()->targets(), TargetStatus::ACTIONABLE);

        self::assertFalse($target->backup->isKnown());
        self::assertStringContainsString('nothing is displaced', $target->backup->describe());
    }

    public function testTheOperationalChecksSectionIsPresentAndEmpty(): void
    {
        $report = $this->report();

        self::assertTrue($report->checks->isEmpty());
        self::assertSame([], $report->checks->all());
        self::assertSame(0, $report->counts->checks);
        self::assertSame(0, $report->counts->notifiableChecks);
    }

    /**
     * Phase 4 puts a check in the section. The target counts must not move, because
     * an operational check is not a target path. The core owns the section, so the
     * runner is not handed one; the invariant is a property of the counts, exercised
     * here over the run's real targets with and without a check beside them.
     */
    public function testTargetCountsAreUnaffectedByTheOperationalChecksSection(): void
    {
        $targets = $this->report()->targets();

        $without = RunCounts::of($targets, OperationalChecks::none());
        $with    = RunCounts::of($targets, OperationalChecks::of(new OperationalCheck(
            'system_plugin_enabled',
            'disabled',
            true,
            'the site is missing every rule in override.css'
        )));

        self::assertSame($without->targets, $with->targets);
        self::assertSame($without->byStatus, $with->byStatus);
        self::assertSame(\count($targets), $with->targets);
        self::assertSame(
            $with->targets,
            \array_sum($with->byStatus),
            'every target is counted under exactly one status, and no check is'
        );

        self::assertSame(0, $without->checks);
        self::assertSame(1, $with->checks);
        self::assertSame(1, $with->notifiableChecks);
    }

    public function testTheCountsHoldEveryStatusEvenWhereNothingIsInIt(): void
    {
        $counts = $this->report()->counts;

        self::assertSame(TargetStatus::ALL, \array_keys($counts->byStatus));
        self::assertSame(0, $counts->forStatus(TargetStatus::FROZEN));
        self::assertSame(1, $counts->forStatus(TargetStatus::BLOCKED));
    }

    /**
     * A rule set that does not load ends the run. Reporting zero targets and nothing
     * else would read as a site with no customisations at all.
     */
    public function testARuleSetThatDoesNotLoadFaultsTheRunAndReportsNoTargets(): void
    {
        $this->tree->write(self::PACKAGE . '/rules/10-broken.xml', '<patch hashType="sha256"><nonsense/></patch>');

        $report = $this->build()->check();

        self::assertTrue($report->isFaulted());
        self::assertSame([], $report->targets());
        self::assertSame(0, $report->counts->targets);
        self::assertNotSame([], $report->faults());
        self::assertTrue($report->checks->isEmpty());
    }

    /**
     * The merged set is validated after the files load, and its violations end the
     * run the same way.
     */
    public function testAnInvalidMergedSetFaultsTheRun(): void
    {
        $this->tree->write(self::PACKAGE . '/payload/orphan.css', self::FIRST);
        $this->tree->write(self::PACKAGE . '/rules/10-run.xml', RuleXml::patch(RuleXml::object(
            version: 1,
            dest: 'templates/et_tennis/css/ok.css',
            source: 'ok.css.v1',
            sourceHash: self::digest(self::FIRST),
            create: true
        )));
        $this->tree->write(self::PACKAGE . '/payload/ok.css.v1', self::FIRST);

        $report = $this->build()->check();

        self::assertTrue($report->isFaulted());
        self::assertSame([], $report->targets());
        self::assertStringContainsString('orphan.css', \implode("\n", $report->faults()));
    }

    /**
     * A damaged history record says nothing about the bytes at any target.
     *
     * So it is a fault the report carries beside the walk rather than instead of it,
     * decision 29. Only the history port is substituted here: the event log it reads is
     * ticket 29's, and ticket 34 runs this case again over a real damaged log.
     */
    public function testADamagedHistoryRecordFaultsTheRunAndStillReportsEveryTarget(): void
    {
        $this->writeRuleSet();

        $report = $this->build(new class () implements WriteHistoryInterface {
            public function lastWriteFor(string $targetPath): ReportedValue
            {
                return ReportedValue::unavailable('the event log could not be read');
            }

            public function interruption(): ReportedValue
            {
                return ReportedValue::none('no run marker');
            }

            /**
             * @return list<string>
             */
            public function recordFaults(): array
            {
                return ['the event log has a damaged record on line 2'];
            }
        })->check();

        self::assertTrue($report->isFaulted());
        self::assertNotSame([], $report->targets(), 'The walk stands.');
        self::assertSame(\count($report->targets()), $report->counts->targets);
        self::assertSame(
            ['the event log has a damaged record on line 2'],
            $report->faults()
        );
    }

    /**
     * Report only. The runner loads, validates, resolves, hashes and reads a freeze
     * marker, and that is the whole of its contact with the site.
     */
    public function testTheRunCreatesChangesAndRemovesNoFile(): void
    {
        $this->writeRuleSet();

        $before = $this->snapshot();
        $report = $this->build()->check();
        self::assertSame($before, $this->snapshot());

        $proposed = [];

        foreach ($report->targets() as $target) {
            foreach ($target->operations() as $operation) {
                $proposed[] = $operation->operation . ' ' . $operation->targetPath;
            }
        }

        // The snapshot proves nothing unless the run proposed a creation and a
        // removal as well as an overwrite.
        self::assertContains('copy templates/et_tennis/css/created.css', $proposed);
        self::assertContains('remove plugins/system/helixultimate/comingsoon.php', $proposed);
        self::assertFileDoesNotExist($this->tree->path('templates/et_tennis/css/created.css'));
        self::assertFileExists($this->tree->path('plugins/system/helixultimate/comingsoon.php'));
    }

    public function testTheReportNarratesTheWalkObjectByObject(): void
    {
        $incomplete = $this->withStatus($this->report()->targets(), TargetStatus::INCOMPLETE);

        self::assertCount(3, $incomplete->objectOutcomes());
        self::assertStringContainsString('version 1', $incomplete->objectOutcomes()[0]->describe());
        self::assertSame('templates/et_tennis/css/incomplete.css', $incomplete->targetPath());
        self::assertSame(TargetStatus::INCOMPLETE, $incomplete->describe());
    }

    /**
     * The check has to come back with a report whatever the filesystem says. A payload
     * directory the account cannot list throws out of the merged-set scan, and an
     * uncaught exception here returns nothing at all - not a faulted report, not an
     * empty one. Review s1.v53 spec finding 1; the root and a nested directory fail at
     * different points, so both are driven.
     *
     * @return array<string, array{string}>
     */
    public static function payloadDirectoriesThatCannotBeListed(): array
    {
        return [
            'the payload root'      => [self::PACKAGE . '/payload'],
            'a nested payload folder' => [self::PACKAGE . '/payload/nested'],
        ];
    }

    #[DataProvider('payloadDirectoriesThatCannotBeListed')]
    public function testAnUnreadablePayloadDirectoryFaultsTheReportInsteadOfThrowing(
        string $closedRelative
    ): void {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('Permission bits do not stop the superuser listing the directory.');
        }

        $this->writeRuleSet();
        $this->tree->write(self::PACKAGE . '/payload/nested/hidden.css.v1', self::FIRST);
        $closed = $this->tree->path($closedRelative);
        \chmod($closed, 0o000);

        try {
            $report = $this->build()->check();
        } finally {
            \chmod($closed, 0o755);
        }

        self::assertTrue($report->isFaulted(), 'an unlistable payload directory is a fault');
        self::assertStringContainsString(
            'cannot be read. A directory that cannot be listed may hold a payload',
            \implode("\n", $report->faults())
        );
    }

    private function report(): RunReport
    {
        $this->writeRuleSet();

        $report = $this->build()->check();

        self::assertFalse(
            $report->isFaulted(),
            'the fixture rule set has to load and validate: ' . \implode("\n", $report->faults())
        );

        return $report;
    }

    /**
     * @param list<TargetReport> $targets
     */
    private function withStatus(array $targets, string $status): TargetReport
    {
        foreach ($targets as $target) {
            if ($target->status() === $status) {
                return $target;
            }
        }

        self::fail(\sprintf('the fixture produced no %s target', $status));
    }

    private function build(?WriteHistoryInterface $history = null): Runner
    {
        $paths = new PackagePaths($this->tree->path(self::PACKAGE));

        return new Runner(
            $paths,
            new RuleLoader(new SchemaValidator(
                \dirname(__DIR__, 2) . '/extensions/com_ecskccustom/admin/schema/patch.xsd'
            )),
            new MergedSetValidator($paths->payload()),
            // JOOMLA_VERSION is undeterminable on purpose: it is what faults the one
            // object whose condition reads it, and a fault is the third status the
            // required-field rule names.
            new class () implements EnvironmentProviderInterface {
                public function raw(): RawEnvironment
                {
                    return new RawEnvironment('https://ecskc.eu/', null, null, '8.3.6');
                }
            },
            new EnvironmentResolver(),
            new ObjectWalker(
                new PathPolicy($this->tree->root(), $this->tree->path(self::PACKAGE)),
                new Hasher(),
                new ConditionEvaluator(),
                new FreezeMarkers($paths)
            ),
            $history ?? new ReportOnlyHistory()
        );
    }

    /**
     * One target per status the required-field rule names, plus the ordinary ones.
     */
    private function writeRuleSet(): void
    {
        $this->tree->write('templates/et_tennis/css/ok.css', self::FIRST);
        $this->tree->write('templates/et_tennis/css/actionable.css', self::ORIGINAL);
        $this->tree->write('templates/et_tennis/css/incomplete.css', self::FIRST);
        $this->tree->write('templates/et_tennis/css/blocked.css', self::FOREIGN);
        $this->tree->write('templates/et_tennis/css/faulted.css', self::ORIGINAL);
        $this->tree->write('plugins/system/helixultimate/comingsoon.php', self::ORIGINAL);

        foreach (
            [
            'ok.css.v1', 'actionable.css.v1', 'incomplete.css.v1', 'incomplete.css.v2',
            'incomplete.css.v3', 'blocked.css.v1', 'faulted.css.v1', 'created.css.v1',
            ] as $payload
        ) {
            $this->tree->write(self::PACKAGE . '/payload/' . $payload, self::FIRST);
        }

        $elsewhere = RuleXml::conditions('HOST', 'staging.ecskc.eu');

        $this->tree->write(self::PACKAGE . '/rules/10-run.xml', RuleXml::patch(
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
            // Version 3 is what makes version 2's unmet condition a gap rather than
            // the answer: it expects what version 2 writes, so the head is out of
            // reach on this host.
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
        ));
    }

    /**
     * Every path under the tree with its bytes and its mode.
     *
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $snapshot = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tree->root(), \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $path => $info) {
            $snapshot[(string) $path] = \sprintf(
                '%o %s',
                (int) \fileperms((string) $path),
                \is_dir((string) $path) ? 'dir' : \hash_file('sha256', (string) $path)
            );
        }

        \ksort($snapshot);

        return $snapshot;
    }
}
