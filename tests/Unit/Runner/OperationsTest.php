<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Runner;

use PHPUnit\Framework\Attributes\CoversClass;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventRecord;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunLock;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\TargetWriter;
use YakShaver\ECSKCCustomisation\Tests\Support\RuleXml;

#[CoversClass(Runner::class)]
final class OperationsTest extends RunnerHarness
{
    private function freezeMarker(string $targetPath): string
    {
        return $this->paths()->freezeMarker($targetPath);
    }

    public function testAFrozenTargetReportsItsUnderlyingStateAndTakesNoWrite(): void
    {
        $this->writeFixture();
        $this->runner()->freeze(self::VENDOR);

        foreach (
            [
                'apply'  => fn () => $this->runner()->apply(self::VENDOR),
                'revert' => fn () => $this->runner()->revert(self::VENDOR),
                'redo'   => fn () => $this->runner()->redo(self::VENDOR),
            ] as $name => $operation
        ) {
            $report = $operation();
            $target = $this->target($report, self::VENDOR);

            self::assertSame(
                self::ORIGINAL,
                \file_get_contents($this->tree->path(self::VENDOR)),
                \sprintf('%s wrote to a frozen path', $name)
            );
            self::assertSame(TargetStatus::FROZEN, $target->status());
            self::assertStringContainsString('would be actionable', $target->describe());
        }
    }

    public function testFreezeTakesTheRunLockAndRecordsTheMarkerChange(): void
    {
        $this->writeFixture();

        $held = new RunLock($this->paths());
        self::assertTrue($held->acquire());

        try {
            $this->runner()->freeze(self::VENDOR);
            self::assertFileDoesNotExist(
                $this->freezeMarker(self::VENDOR),
                'A freeze that could not take the lock touches no marker.'
            );
        } finally {
            $held->release();
        }

        $this->runner()->freeze(self::VENDOR);

        self::assertFileExists($this->freezeMarker(self::VENDOR));

        $events = $this->events();
        self::assertCount(1, $events);
        self::assertSame(EventRecord::FREEZE, $events[0]['event']);
        self::assertSame(EventRecord::ABSENT, $events[0]['pre']);
        self::assertSame(EventRecord::PRESENT, $events[0]['post']);
        self::assertTrue($this->lockIsFree());
    }

    public function testAFreezeThatHasReturnedExcludesALaterApply(): void
    {
        $this->writeFixture();
        $this->runner()->freeze(self::VENDOR);

        $this->runner()->apply();

        self::assertSame(self::ORIGINAL, \file_get_contents($this->tree->path(self::VENDOR)));
        self::assertSame(self::FIRST, \file_get_contents($this->tree->path(self::CREATED)));
    }

    /**
     * And the other half: an apply holding the lock excludes a freeze that starts
     * while it runs, so the freeze cannot return part-way through a write.
     */
    public function testAnApplyThatHoldsTheLockExcludesFreezeAndUnfreeze(): void
    {
        $this->writeFixture();

        $freeze   = null;
        $unfreeze = null;

        $this->runner(function (string $point) use (&$freeze, &$unfreeze): void {
            if ($point !== TargetWriter::STAGED || $freeze !== null) {
                return;
            }

            $freeze   = $this->runner()->freeze(self::CHAIN);
            $unfreeze = $this->runner()->unfreeze(self::CHAIN);
        })->apply();

        self::assertNotNull($freeze, 'The apply never reached the checkpoint.');
        self::assertFileDoesNotExist(
            $this->freezeMarker(self::CHAIN),
            'A freeze cannot return while an apply holds the lock.'
        );
        self::assertTrue($freeze->isFaulted());
        self::assertTrue($unfreeze?->isFaulted());
        self::assertStringContainsString(RunLock::IN_PROGRESS, \implode("\n", $freeze->faults()));
    }

    public function testUnfreezeRemovesAMarkerThatIsAnEmptyDirectory(): void
    {
        $this->writeFixture();

        $marker = $this->freezeMarker(self::VENDOR);
        \mkdir(\dirname($marker), 0o755, true);
        \mkdir($marker, 0o755);

        $report = $this->runner()->unfreeze(self::VENDOR);

        self::assertFileDoesNotExist($marker);
        self::assertSame(TargetStatus::ACTIONABLE, $this->target($report, self::VENDOR)->status());
    }

    public function testASymbolicLinkIsNotAFreezeMarker(): void
    {
        $this->writeFixture();

        $marker = $this->freezeMarker(self::VENDOR);
        \mkdir(\dirname($marker), 0o755, true);
        \symlink($this->tree->path(self::CHAIN), $marker);

        $report = $this->runner()->unfreeze(self::VENDOR);

        self::assertSame(
            self::ORIGINAL,
            \file_get_contents($this->tree->path(self::CHAIN)),
            'The link target is untouched.'
        );
        self::assertSame(
            TargetStatus::ACTIONABLE,
            $this->target($report, self::VENDOR)->status(),
            'The path was never frozen, whatever the link is.'
        );
    }

    public function testRevertStepsBackOneObjectAndWarnsThatApplyWouldUndoIt(): void
    {
        $this->writeFixture();
        $this->runner()->apply(self::CHAIN);

        $report = $this->runner()->revert(self::CHAIN);

        self::assertSame(
            self::FIRST,
            \file_get_contents($this->tree->path(self::CHAIN)),
            'One object back, not all the way to the vendor original.'
        );
        self::assertStringContainsString(
            'carries ' . self::CHAIN . ' forward',
            (string) $this->target($report, self::CHAIN)->warning
        );

        $this->runner()->revert(self::CHAIN);

        self::assertSame(self::ORIGINAL, \file_get_contents($this->tree->path(self::CHAIN)));
    }

    /**
     * The backup field says what this run preserved. Revert, unfreeze and redo write
     * no backup, so none may borrow the destination the next apply would write. Each
     * leaves its target actionable, which is when that destination exists to be
     * borrowed. Freeze is left out: a frozen target is planned nothing.
     */
    public function testASingleTargetOperationReportsNoBackupItDidNotWrite(): void
    {
        $this->writeFixture();
        $this->runner()->apply(self::VENDOR);

        $reports = ['revert' => $this->runner()->revert(self::VENDOR)];

        $this->runner()->freeze(self::VENDOR);
        $reports['unfreeze'] = $this->runner()->unfreeze(self::VENDOR);

        $this->runner()->apply(self::CHAIN);
        $this->runner()->revert(self::CHAIN);
        $this->runner()->revert(self::CHAIN);
        $reports['redo'] = $this->runner()->redo(self::CHAIN);

        foreach ($reports as $name => $report) {
            $targetPath = $name === 'redo' ? self::CHAIN : self::VENDOR;
            $target     = $this->target($report, $targetPath);

            self::assertNotSame(
                [],
                $target->plan(),
                \sprintf('%s left nothing for the next apply, so this proves nothing', $name)
            );
            self::assertFalse(
                $target->backup->isKnown(),
                \sprintf('%s reported the backup %s', $name, $target->backup->describe())
            );
        }
    }

    public function testRedoStepsForwardOneObjectAndWritesNoBackupOfShippedBytes(): void
    {
        $this->writeFixture();
        $this->runner()->apply(self::CHAIN);
        $this->runner()->revert(self::CHAIN);
        $this->runner()->revert(self::CHAIN);

        $backups = $this->backupNames();

        $this->runner()->redo(self::CHAIN);

        self::assertSame(self::FIRST, \file_get_contents($this->tree->path(self::CHAIN)));

        $this->runner()->redo(self::CHAIN);

        self::assertSame(self::SECOND, \file_get_contents($this->tree->path(self::CHAIN)));
        self::assertSame(
            $backups,
            $this->backupNames(),
            'A redo displaces reproducible bytes, so it writes no backup.'
        );

        $kinds = \array_column($this->events(), 'event');
        self::assertSame(2, \count(\array_keys($kinds, EventRecord::REDO, true)));
    }

    public function testRevertRefusesAnUnrecordedLocalEdit(): void
    {
        $this->writeFixture();
        $this->runner()->apply(self::VENDOR);

        $this->tree->write(self::VENDOR, self::FOREIGN);

        $report = $this->runner()->revert(self::VENDOR);

        self::assertSame(
            self::FOREIGN,
            \file_get_contents($this->tree->path(self::VENDOR)),
            'The only copy of an edit nobody recorded is not overwritten.'
        );
        self::assertFalse($this->target($report, self::VENDOR)->outcome()->isKnown());
    }

    public function testRevertRestoresTheNewestBackupWhenAVersionHasSeveral(): void
    {
        $this->writeFixture();
        $this->runner()->apply(self::VENDOR);

        $second = "body { color: teal; }\r\n";
        $this->tree->write(self::VENDOR, $second);
        $this->tree->write(self::PACKAGE . '/rules/10-run.xml', RuleXml::patch(RuleXml::object(
            version: 1,
            dest: self::VENDOR,
            source: 'vendor.css.v1',
            sourceHash: self::digest(self::FIRST),
            expectedHash: self::digest($second)
        )));

        $this->runner()->apply(self::VENDOR);
        $this->runner()->revert(self::VENDOR);

        self::assertSame(
            $second,
            \file_get_contents($this->tree->path(self::VENDOR)),
            'The newest backup at that version is the step back.'
        );
    }

    public function testAPayloadIsNeverWrittenThroughASymbolicLink(): void
    {
        // A second tree, because the link has to leave the site root and the site
        // root is the first tree. A link to a sibling directory inside it resolves
        // inside the site and is not what this proves.
        $elsewhere = \YakShaver\ECSKCCustomisation\Tests\Support\TempTree::create('ecskc-outside-');
        $outside   = $elsewhere->write('elsewhere.css', self::ORIGINAL);

        $this->payload('linked.css.v1', self::FIRST);
        $this->tree->symlink('templates/et_tennis/css/linked.css', $outside);

        $this->tree->write(self::PACKAGE . '/rules/10-run.xml', RuleXml::patch(RuleXml::object(
            version: 1,
            dest: 'templates/et_tennis/css/linked.css',
            source: 'linked.css.v1',
            sourceHash: self::digest(self::FIRST),
            expectedHash: self::digest(self::ORIGINAL)
        )));

        try {
            $this->runner()->apply();

            self::assertSame(
                self::ORIGINAL,
                \file_get_contents($outside),
                'Nothing was written through the link.'
            );
            self::assertSame([], $this->events());
        } finally {
            $elsewhere->remove();
        }
    }

    /**
     * The deny list holds at the apply as well as at the plan, so a rule naming a
     * denied name writes nothing even where the path resolves inside the site.
     */
    public function testADeniedNameIsNeverWritten(): void
    {
        $this->tree->write('templates/et_tennis/.htaccess', self::ORIGINAL);
        $this->payload('htaccess.v1', self::FIRST);
        $this->tree->write(self::PACKAGE . '/rules/10-run.xml', RuleXml::patch(RuleXml::object(
            version: 1,
            dest: 'templates/et_tennis/.htaccess',
            source: 'htaccess.v1',
            sourceHash: self::digest(self::FIRST),
            expectedHash: self::digest(self::ORIGINAL)
        )));

        $this->runner()->apply();

        self::assertSame(
            self::ORIGINAL,
            \file_get_contents($this->tree->path('templates/et_tennis/.htaccess'))
        );
        self::assertSame([], $this->events());
        self::assertSame([], $this->backupNames());
    }

    public function testTheEventLogRecordsMutationsAndNothingElse(): void
    {
        $this->writeFixture();

        $this->runner()->check();
        $this->runner()->dryRun();
        $this->runner()->apply();
        $this->runner()->check();
        $this->runner()->revert(self::CHAIN);
        $this->runner()->redo(self::CHAIN);
        $this->runner()->freeze(self::VENDOR);
        $this->runner()->unfreeze(self::VENDOR);
        $this->runner()->dryRun();

        $kinds = \array_unique(\array_column($this->events(), 'event'));
        \sort($kinds);

        self::assertSame(
            [
                EventRecord::FREEZE,
                EventRecord::REDO,
                EventRecord::REMOVE,
                EventRecord::REVERT,
                EventRecord::UNFREEZE,
                EventRecord::WRITE,
            ],
            $kinds
        );

        foreach ($this->events() as $record) {
            self::assertArrayHasKey('id', $record, 'Every record carries its correlation id.');
        }
    }

    /**
     * Folder guards are the package's, not history, and a run leaves them alone.
     */
    public function testARunLeavesTheFolderGuardsWhereTheyAre(): void
    {
        $this->writeFixture();

        foreach (PackagePaths::CREATED_FOLDERS as $folder) {
            \file_put_contents(
                $this->tree->path(self::PACKAGE . '/' . $folder) . '/' . PackagePaths::HTACCESS,
                'Deny from all'
            );
        }

        $this->runner()->apply();

        foreach (PackagePaths::CREATED_FOLDERS as $folder) {
            self::assertFileExists(
                $this->tree->path(self::PACKAGE . '/' . $folder) . '/' . PackagePaths::HTACCESS
            );
        }
    }

    public function testRevertAndRedoAreRefusedWhileAnotherRunHoldsTheLock(): void
    {
        $this->writeFixture();
        $this->runner()->apply(self::CHAIN);

        $held = new RunLock($this->paths());
        self::assertTrue($held->acquire());

        try {
            $revert = $this->runner()->revert(self::CHAIN);
            $redo   = $this->runner()->redo(self::CHAIN);
        } finally {
            $held->release();
        }

        self::assertSame(self::SECOND, \file_get_contents($this->tree->path(self::CHAIN)));
        self::assertTrue($revert->isFaulted());
        self::assertTrue($redo->isFaulted());
        self::assertStringContainsString(RunLock::IN_PROGRESS, \implode("\n", $revert->faults()));
        self::assertStringContainsString(RunLock::IN_PROGRESS, \implode("\n", $redo->faults()));
    }
}
