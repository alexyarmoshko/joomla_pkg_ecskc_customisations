<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Runner;

use PHPUnit\Framework\Attributes\CoversClass;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\PlannedOperation;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\WritePlanner;

#[CoversClass(Runner::class)]
#[CoversClass(WritePlanner::class)]
#[CoversClass(PlannedOperation::class)]
final class DryRunTest extends RunnerHarness
{
    public function testADryRunChangesNothingAtAll(): void
    {
        $this->writeFixture();

        $before = $this->snapshot();
        $report = $this->runner()->dryRun();

        self::assertSame($before, $this->snapshot(), 'A dry run writes nothing anywhere.');
        self::assertFileDoesNotExist(
            $this->paths()->lock(),
            'A dry run does not acquire the write lock, so it does not create one either.'
        );
        self::assertTrue($this->lockIsFree(), 'And nothing is holding one.');
        self::assertFileDoesNotExist($this->paths()->marker());
        self::assertFileDoesNotExist($this->paths()->writeIntent());
        self::assertFileDoesNotExist($this->paths()->eventLog());
        self::assertSame([], $this->backupNames());
        self::assertNotSame([], $report->targets());
    }

    public function testADryRunProducesThePlanAnApplyExecutes(): void
    {
        $this->writeFixture();

        $planned = self::planOf($this->runner()->dryRun());

        // Read from the apply's own report, which carries the plan it executed. The
        // same question asked of the tree afterwards would answer differently,
        // because by then the tree has moved on and a finished target proposes
        // nothing.
        self::assertSame($planned, self::planOf($this->runner()->apply()));
    }

    /**
     * "At v1, will advance to v3 through v2" - the whole walk, not just the head.
     */
    public function testADryRunOfAChainReportsEveryStepItsDisplacementAndItsBackup(): void
    {
        $this->writeFixture();

        $plan = $this->target($this->runner()->dryRun(), self::CHAIN)->plan();

        self::assertCount(2, $plan, 'Both steps of the chain are planned.');

        self::assertSame(self::digest(self::ORIGINAL), $plan[0]->displaced->value);
        self::assertSame(
            $this->paths()->backupFile(self::CHAIN, 1, self::digest(self::ORIGINAL)),
            $plan[0]->backup->value
        );

        // The second step displaces what the first one writes, not what is on disk
        // now. A plan that read the tree once would name the same backup twice.
        self::assertSame(self::digest(self::FIRST), $plan[1]->displaced->value);
        self::assertSame(
            $this->paths()->backupFile(self::CHAIN, 2, self::digest(self::FIRST)),
            $plan[1]->backup->value
        );

        foreach ($plan as $line) {
            self::assertTrue($line->isPerformable());
            self::assertSame(PlannedOperation::NOT_EXECUTED, $line->result->reason);
        }
    }

    public function testACreateDisplacesNothingAndNamesNoBackup(): void
    {
        $this->writeFixture();

        $plan = $this->target($this->runner()->dryRun(), self::CREATED)->plan();

        self::assertCount(1, $plan);
        self::assertFalse($plan[0]->displaced->isKnown());
        self::assertFalse($plan[0]->backup->isKnown());
        self::assertStringContainsString('displaces nothing', $plan[0]->backup->describe());
    }

    public function testADryRunReportsAMissingWritePermissionWithoutWriting(): void
    {
        $this->writeFixture();
        $this->lockDown(\dirname($this->tree->path(self::VENDOR)));

        $before = $this->snapshot();
        $plan   = $this->target($this->runner()->dryRun(), self::VENDOR)->plan();

        self::assertSame($before, $this->snapshot());
        self::assertFalse($plan[0]->isPerformable());
        self::assertStringContainsString('is not writable', \implode('; ', $plan[0]->refusals));
    }

    public function testADryRunReportsABadPayloadHashWithoutWriting(): void
    {
        $this->writeFixture();
        $this->payload('vendor.css.v1', self::SECOND);

        $before = $this->snapshot();
        $plan   = $this->target($this->runner()->dryRun(), self::VENDOR)->plan();

        self::assertSame($before, $this->snapshot());
        self::assertFalse($plan[0]->isPerformable());
        self::assertStringContainsString(
            'hashes to ' . self::digest(self::SECOND),
            \implode('; ', $plan[0]->refusals)
        );
    }

    public function testADryRunReportsAnUnwritableBackupDestinationWithoutWriting(): void
    {
        $this->writeFixture();
        $this->lockDown($this->paths()->backup());

        $before = $this->snapshot();
        $plan   = $this->target($this->runner()->dryRun(), self::VENDOR)->plan();

        self::assertSame($before, $this->snapshot());
        self::assertFalse($plan[0]->isPerformable());
        self::assertStringContainsString(
            'is not a writable directory',
            \implode('; ', $plan[0]->refusals)
        );

        $report = $this->runner()->apply(self::VENDOR);

        self::assertSame(self::ORIGINAL, \file_get_contents($this->tree->path(self::VENDOR)));
        self::assertStringContainsString(
            'is not a writable directory',
            $this->target($report, self::VENDOR)->outcome()->describe()
        );
    }

    /**
     * A regular file part way down the mirrored path blocks every directory below
     * it, exactly as it blocks `mkdir -p`. A plan that climbed past it to the nearest
     * writable ancestor would call the operation performable and leave the apply to
     * find out, at which point it safely refuses the write and the operator has a
     * preview that promised otherwise.
     */
    public function testADryRunRefusesABackupPathBlockedByAFileInTheMiddleOfIt(): void
    {
        $this->writeFixture();

        $blocked = $this->paths()->backup() . '/templates';
        \file_put_contents($blocked, 'not a directory');

        $before = $this->snapshot();
        $plan   = $this->target($this->runner()->dryRun(self::VENDOR), self::VENDOR)->plan();

        self::assertSame($before, $this->snapshot());
        self::assertFalse($plan[0]->isPerformable(), 'The backup folder cannot be made.');
        self::assertStringContainsString(
            $blocked . ' is not a directory',
            \implode('; ', $plan[0]->refusals)
        );

        $report = $this->runner()->apply(self::VENDOR);

        $target = $this->target($report, self::VENDOR);

        self::assertSame(self::ORIGINAL, \file_get_contents($this->tree->path(self::VENDOR)));
        self::assertFileDoesNotExist(
            $this->paths()->backupFile(self::VENDOR, 1, self::digest(self::ORIGINAL))
        );
        self::assertFalse($target->outcome()->isKnown());
        self::assertStringContainsString($blocked . ' is not a directory', $target->outcome()->describe());
    }

    /**
     * The same gate, one level up the mirrored path. Apply walks every component
     * from `backup/` and refuses a symbolic link at any of them. A dry run that
     * only asked the nearest existing directory would miss the link when the tree
     * behind it was already complete, call the operation performable, and leave
     * the apply to refuse it.
     */
    public function testADryRunRefusesABackupPathWhoseAncestorIsASymbolicLink(): void
    {
        $this->writeFixture();

        $outside = $this->tree->path('elsewhere');
        \mkdir($outside . '/et_tennis/css', 0755, true);

        $linked = $this->paths()->backup() . '/templates';
        \symlink($outside, $linked);

        $before = $this->snapshot();
        $plan   = $this->target($this->runner()->dryRun(self::VENDOR), self::VENDOR)->plan();

        self::assertSame($before, $this->snapshot());
        self::assertFalse($plan[0]->isPerformable(), 'The backup folder cannot be made through a link.');
        self::assertStringContainsString(
            $linked . ' is a symbolic link, and nothing under backup/ is written through one',
            \implode('; ', $plan[0]->refusals)
        );

        $report = $this->runner()->apply(self::VENDOR);
        $target = $this->target($report, self::VENDOR);

        self::assertSame(self::ORIGINAL, \file_get_contents($this->tree->path(self::VENDOR)));
        self::assertFileDoesNotExist(
            $this->paths()->backupFile(self::VENDOR, 1, self::digest(self::ORIGINAL))
        );
        self::assertFalse($target->outcome()->isKnown());
        self::assertStringContainsString(
            $linked . ' is a symbolic link, and nothing under backup/ is written through one',
            $target->outcome()->describe()
        );
    }

    /**
     * Mode 0666 is writable and listable, and not searchable. Apply opens the
     * directory and then cannot create the mirrored child. A dry run that asked
     * only is_writable() would call the same tree performable.
     */
    public function testADryRunRefusesABackupDirectoryThatCannotBeSearched(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('Permission bits do not stop the superuser searching a directory.');
        }

        $this->writeFixture();
        $this->lockDown($this->paths()->backup(), 0o666);

        $plan = $this->target($this->runner()->dryRun(self::VENDOR), self::VENDOR)->plan();

        self::assertFalse($plan[0]->isPerformable(), 'The backup child cannot be created.');
        self::assertStringContainsString(
            'is not a searchable directory',
            \implode('; ', $plan[0]->refusals)
        );

        $report = $this->runner()->apply(self::VENDOR);

        self::assertSame(self::ORIGINAL, \file_get_contents($this->tree->path(self::VENDOR)));
        self::assertStringContainsString(
            'is not a searchable directory',
            $this->target($report, self::VENDOR)->outcome()->describe()
        );
    }

    /**
     * Mode 0333 is writable and searchable, and not readable. Apply has to open
     * each directory, and opendir fails. is_writable() alone would let the dry
     * run through.
     */
    public function testADryRunRefusesABackupDirectoryThatCannotBeOpened(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('Permission bits do not stop the superuser opening a directory.');
        }

        $this->writeFixture();
        $this->lockDown($this->paths()->backup(), 0o333);

        $plan = $this->target($this->runner()->dryRun(self::VENDOR), self::VENDOR)->plan();

        self::assertFalse($plan[0]->isPerformable(), 'The backup directory cannot be opened.');
        self::assertStringContainsString(
            'could not be opened',
            \implode('; ', $plan[0]->refusals)
        );

        $report = $this->runner()->apply(self::VENDOR);

        self::assertSame(self::ORIGINAL, \file_get_contents($this->tree->path(self::VENDOR)));
        self::assertStringContainsString(
            'could not be opened',
            $this->target($report, self::VENDOR)->outcome()->describe()
        );
    }

    /**
     * The deny list applies to the plan as well as to the move, and a denied path
     * has no resolved location, so no backup destination is computed for one.
     */
    public function testADryRunReportsADeniedPathWithoutWriting(): void
    {
        $this->tree->write('tmp/denied.css', self::ORIGINAL);
        $this->payload('denied.css.v1', self::FIRST);
        $this->tree->write(self::PACKAGE . '/rules/10-run.xml', \YakShaver\ECSKCCustomisation\Tests\Support\RuleXml::patch(
            \YakShaver\ECSKCCustomisation\Tests\Support\RuleXml::object(
                version: 1,
                dest: 'tmp/denied.css',
                source: 'denied.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            )
        ));

        $before = $this->snapshot();
        $target = $this->target($this->runner()->dryRun(), 'tmp/denied.css');

        self::assertSame($before, $this->snapshot());
        self::assertSame(TargetStatus::FAULTED, $target->status());
        self::assertStringContainsString('tmp', $target->reason());
    }

    public function testADryRunPlansNothingForAFrozenTarget(): void
    {
        $this->writeFixture();
        $this->runner()->freeze(self::VENDOR);

        $target = $this->target($this->runner()->dryRun(), self::VENDOR);

        self::assertSame(TargetStatus::FROZEN, $target->status());
        self::assertSame([], $target->plan());
        self::assertStringContainsString('would be actionable', $target->describe());
    }

    /**
     * A run over one target answers the same as the run over the set, because the
     * single-target run is the whole walk filtered rather than a different walk.
     */
    public function testASingleTargetDryRunMatchesTheWholeSetForThatTarget(): void
    {
        $this->writeFixture();

        self::assertSame(
            self::describe($this->target($this->runner()->dryRun(), self::CHAIN)),
            self::describe($this->target($this->runner()->dryRun(self::CHAIN), self::CHAIN))
        );
        self::assertCount(1, $this->runner()->dryRun(self::CHAIN)->targets());
    }

    /**
     * Every planned operation of a report, as one comparable list.
     *
     * @return list<string>
     */
    private static function planOf(
        \YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport $report
    ): array {
        $lines = [];

        foreach ($report->targets() as $target) {
            foreach ($target->plan() as $planned) {
                $lines[] = \sprintf(
                    '%s | %s | %s | %s',
                    $planned->operation->describe(),
                    $planned->displaced->describe(),
                    $planned->backup->describe(),
                    \implode('; ', $planned->refusals)
                );
            }
        }

        return $lines;
    }

    private static function describe(
        \YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport $target
    ): string {
        return $target->describe() . ' | ' . $target->reason() . ' | ' . $target->backup->describe();
    }
}
