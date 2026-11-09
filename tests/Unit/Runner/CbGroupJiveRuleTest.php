<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Runner;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
use YakShaver\ECSKCCustomisation\Tests\Support\CbGroupJiveFiles;

/**
 * The shipped CB GroupJive rules and payloads, run over a site tree.
 */
#[CoversClass(Runner::class)]
final class CbGroupJiveRuleTest extends RunnerHarness
{
    private const RULE_FILE = '10-comprofiler.xml';

    protected function setUp(): void
    {
        parent::setUp();

        $repo = CbGroupJiveFiles::repository();
        $rule = (string) \file_get_contents($repo . '/data/rules/' . self::RULE_FILE);

        $this->tree->write(self::PACKAGE . '/rules/' . self::RULE_FILE, $rule);

        \preg_match_all('#<srcPathFileName>([^<]+)</srcPathFileName>#', $rule, $sources);

        foreach ($sources[1] as $source) {
            $this->payload($source, (string) \file_get_contents($repo . '/data/payload/' . $source));
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function targets(): array
    {
        return [
            'events component' => [CbGroupJiveFiles::EVENTS],
            'module'           => [CbGroupJiveFiles::MODULE],
        ];
    }

    #[DataProvider('targets')]
    public function testTheVendorOriginalIsActionable(string $target): void
    {
        $this->tree->write($target, CbGroupJiveFiles::original($target));

        self::assertSame(
            TargetStatus::ACTIONABLE,
            $this->target($this->runner()->check($target), $target)->status()
        );
    }

    #[DataProvider('targets')]
    public function testApplyInstallsThePayloadAndBacksUpTheOriginal(string $target): void
    {
        $this->tree->write($target, CbGroupJiveFiles::original($target));

        $report = $this->runner()->apply($target);

        self::assertSame(TargetStatus::OK, $this->target($report, $target)->status());
        self::assertSame(CbGroupJiveFiles::payload($target), $this->tree->read($target));

        $backup = $this->paths()->backupFile($target, 1, CbGroupJiveFiles::FILES[$target][0]);
        self::assertFileExists($backup);
        self::assertSame(CbGroupJiveFiles::original($target), \file_get_contents($backup));
    }

    #[DataProvider('targets')]
    public function testASecondApplyChangesNothing(string $target): void
    {
        $this->tree->write($target, CbGroupJiveFiles::original($target));
        $this->runner()->apply($target);

        $before = $this->snapshot();
        $events = $this->events();

        $report = $this->runner()->apply($target);

        self::assertSame(TargetStatus::OK, $this->target($report, $target)->status());
        self::assertSame($before, $this->snapshot());
        self::assertSame($events, $this->events());
    }

    #[DataProvider('targets')]
    public function testThePayloadAlreadyOnDiskIsOkWithoutHistory(string $target): void
    {
        $this->tree->write($target, CbGroupJiveFiles::payload($target));

        self::assertSame(
            TargetStatus::OK,
            $this->target($this->runner()->check($target), $target)->status()
        );
    }

    /**
     * A later GroupJive build, here stood in for by one extra byte, is not
     * recognised, so the whole-file payload cannot roll it back.
     */
    #[DataProvider('targets')]
    public function testChangedVendorBytesAreBlockedAndLeftAlone(string $target): void
    {
        $changed = CbGroupJiveFiles::original($target) . "\n";
        $this->tree->write($target, $changed);

        $report = $this->runner()->apply($target);

        self::assertSame(TargetStatus::BLOCKED, $this->target($report, $target)->status());
        self::assertSame($changed, $this->tree->read($target));
        self::assertSame([], $this->backupNames());
    }

    #[DataProvider('targets')]
    public function testAMissingFileIsNotCreated(string $target): void
    {
        $report = $this->runner()->apply($target);

        self::assertSame(TargetStatus::BLOCKED, $this->target($report, $target)->status());
        self::assertFileDoesNotExist($this->tree->path($target));
    }

    #[DataProvider('targets')]
    public function testRevertRestoresTheExactOriginal(string $target): void
    {
        $this->tree->write($target, CbGroupJiveFiles::original($target));
        $this->runner()->apply($target);

        $this->runner()->revert($target);

        self::assertSame(CbGroupJiveFiles::original($target), $this->tree->read($target));
    }
}
