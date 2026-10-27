<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Runner;

use PHPUnit\Framework\Attributes\CoversClass;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
use YakShaver\ECSKCCustomisation\Tests\Support\CbActivityController;

/**
 * The shipped CB Activity rule and payload, run over a site tree.
 */
#[CoversClass(Runner::class)]
final class CbActivityRuleTest extends RunnerHarness
{
    private const RULE_FILE = '10-comprofiler.xml';

    private const TARGET = CbActivityController::TARGET;

    protected function setUp(): void
    {
        parent::setUp();

        $repo = CbActivityController::repository();
        $rule = (string) \file_get_contents($repo . '/data/rules/' . self::RULE_FILE);

        $this->tree->write(self::PACKAGE . '/rules/' . self::RULE_FILE, $rule);

        \preg_match_all('#<srcPathFileName>([^<]+)</srcPathFileName>#', $rule, $sources);

        foreach ($sources[1] as $source) {
            $this->payload($source, (string) \file_get_contents($repo . '/data/payload/' . $source));
        }
    }

    public function testTheVendorOriginalIsActionable(): void
    {
        $this->tree->write(self::TARGET, CbActivityController::original());

        self::assertSame(
            TargetStatus::ACTIONABLE,
            $this->target($this->runner()->check(self::TARGET), self::TARGET)->status()
        );
    }

    public function testApplyInstallsThePayloadAndBacksUpTheOriginal(): void
    {
        $this->tree->write(self::TARGET, CbActivityController::original());

        $report = $this->runner()->apply(self::TARGET);

        self::assertSame(TargetStatus::OK, $this->target($report, self::TARGET)->status());
        self::assertSame(CbActivityController::payload(), $this->tree->read(self::TARGET));

        $backup = $this->paths()->backupFile(self::TARGET, 1, CbActivityController::ORIGINAL_SHA256);
        self::assertFileExists($backup);
        self::assertSame(CbActivityController::original(), \file_get_contents($backup));
    }

    public function testASecondApplyChangesNothing(): void
    {
        $this->tree->write(self::TARGET, CbActivityController::original());
        $this->runner()->apply(self::TARGET);

        $before = $this->snapshot();
        $events = $this->events();

        $report = $this->runner()->apply(self::TARGET);

        self::assertSame(TargetStatus::OK, $this->target($report, self::TARGET)->status());
        self::assertSame($before, $this->snapshot());
        self::assertSame($events, $this->events());
    }

    public function testThePayloadAlreadyOnDiskIsOkWithoutHistory(): void
    {
        $this->tree->write(self::TARGET, CbActivityController::payload());

        self::assertSame(
            TargetStatus::OK,
            $this->target($this->runner()->check(self::TARGET), self::TARGET)->status()
        );
    }

    /**
     * The fix as it was first hand-applied on the live site, with an editor's
     * indentation left on the blank line above it, is not the payload.
     */
    public function testChangedVendorBytesAreBlockedAndLeftAlone(): void
    {
        $changed = \str_replace(
            "\n\n\t\t\t\$rows\t\t\t\t+=\t\\array_slice( \$this->getRows(",
            "\n\t\t\t\n\t\t\t\$rows\t\t\t\t+=\t\\array_slice( \$this->getRows(",
            CbActivityController::payload()
        );
        self::assertNotSame(CbActivityController::payload(), $changed);
        $this->tree->write(self::TARGET, $changed);

        $report = $this->runner()->apply(self::TARGET);

        self::assertSame(TargetStatus::BLOCKED, $this->target($report, self::TARGET)->status());
        self::assertSame($changed, $this->tree->read(self::TARGET));
        self::assertSame([], $this->backupNames());
    }

    public function testAMissingControllerIsNotCreated(): void
    {
        $report = $this->runner()->apply(self::TARGET);

        self::assertSame(TargetStatus::BLOCKED, $this->target($report, self::TARGET)->status());
        self::assertFileDoesNotExist($this->tree->path(self::TARGET));
    }

    public function testRevertRestoresTheExactOriginal(): void
    {
        $this->tree->write(self::TARGET, CbActivityController::original());
        $this->runner()->apply(self::TARGET);

        $this->runner()->revert(self::TARGET);

        self::assertSame(CbActivityController::original(), $this->tree->read(self::TARGET));
    }
}
