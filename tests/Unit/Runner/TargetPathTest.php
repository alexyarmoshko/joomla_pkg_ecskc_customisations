<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Runner;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
use YakShaver\ECSKCCustomisation\Tests\Support\RuleXml;

/**
 * The path a single-target run is asked about comes from request input once the
 * administrator component exists, so the runner has to refuse one it does not manage.
 */
#[CoversClass(Runner::class)]
final class TargetPathTest extends RunnerHarness
{
    private const ELSEWHERE = 'templates/et_tennis/css/elsewhere.css';

    private const UNDECLARED = 'the package does not manage %s, so nothing was done';

    public function testASinglePathCheckReportsTheLineTheWholeSetCheckReports(): void
    {
        $this->writeFixture();
        $this->runner()->apply(self::VENDOR);

        $whole  = $this->runner()->check();
        $single = $this->runner()->check(self::VENDOR);

        self::assertCount(1, $single->targets());
        self::assertFalse($single->isFaulted());
        self::assertEquals($this->target($whole, self::VENDOR), $single->targets()[0]);
        self::assertEquals($whole->interruption, $single->interruption);
        self::assertStringNotContainsString(
            'none',
            $single->targets()[0]->lastPackageWrite->describe(),
            'The apply above is the last package write this line must carry.'
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedRequests(): iterable
    {
        $paths = [
            'undeclared'         => 'images/not-managed.css',
            'empty'              => '',
            'parent'             => '../configuration.php',
            'absolute'           => '/etc/passwd',
            'dot segment'        => './templates/et_tennis/css/vendor.css',
            'NUL byte'           => "templates/et_tennis/css/vendor.css\0.png",
            'trailing slash'     => 'templates/et_tennis/css/vendor.css/',
            'backslash'          => 'templates\\et_tennis\\css\\vendor.css',
        ];

        foreach (['check', 'dryRun', 'apply', 'freeze', 'unfreeze', 'revert', 'redo'] as $operation) {
            foreach ($paths as $name => $path) {
                yield $operation . ', ' . $name => [$operation, $path];
            }
        }
    }

    #[DataProvider('refusedRequests')]
    public function testAPathTheRuleSetDoesNotDeclareIsRefusedWithoutSideEffects(
        string $operation,
        string $path
    ): void {
        $this->writeFixture();
        $before = $this->snapshot();

        /** @var RunReport $report */
        $report = $this->runner()->$operation($path);

        self::assertSame($before, $this->snapshot(), 'A refused path changes nothing in the tree.');
        self::assertSame([], $report->targets());
        self::assertSame(
            [\sprintf(self::UNDECLARED, \addcslashes($path, "\0..\37"))],
            $report->faults()
        );
    }

    public function testADeclaredPathThatDoesNotApplyIsAccepted(): void
    {
        $this->writeFixture();
        $this->payload('elsewhere.css.v1', self::FIRST);
        $this->tree->write(self::PACKAGE . '/rules/20-elsewhere.xml', RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: self::ELSEWHERE,
                source: 'elsewhere.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: true,
                conditions: RuleXml::conditions('HOST', 'staging.ecskc.eu')
            )
        ));

        foreach (['check', 'dryRun', 'apply', 'freeze', 'unfreeze'] as $operation) {
            /** @var RunReport $report */
            $report = $this->runner()->$operation(self::ELSEWHERE);

            self::assertSame([], $report->faults(), $operation);
            self::assertSame(
                $operation === 'freeze' ? TargetStatus::FROZEN : TargetStatus::NOT_APPLICABLE,
                $this->target($report, self::ELSEWHERE)->status(),
                $operation
            );
        }

        foreach (['revert', 'redo'] as $operation) {
            /** @var RunReport $report */
            $report = $this->runner()->$operation(self::ELSEWHERE);

            self::assertNotContains(
                \sprintf(self::UNDECLARED, self::ELSEWHERE),
                $report->faults(),
                $operation
            );
        }
    }
}
