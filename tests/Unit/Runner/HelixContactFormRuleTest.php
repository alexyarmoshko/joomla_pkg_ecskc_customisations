<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Runner;

use PHPUnit\Framework\Attributes\CoversClass;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;

/**
 * The shipped Helix Ultimate contact-form rule, which recognises two vendor builds.
 *
 * Version 1 expects the 2.2.9 form and version 2 the 2.2.11 form. Neither builds on
 * the other, so a site on either build is carried to its own customised form and no
 * further. The originals are not stored in the repository: each payload differs from
 * its original only in the marker comment and the Cancel button, so taking those out
 * yields the vendor bytes, and the rule's expectedHash proves it.
 */
#[CoversClass(Runner::class)]
final class HelixContactFormRuleTest extends RunnerHarness
{
    private const RULE_FILE = '20-helix-ultimate.xml';

    private const TARGET = 'plugins/system/helixultimate/overrides/com_contact/contact/default_form.php';

    private const STOCK_229_SHA256  = '89c45ba645e29c40fef873e2464af8595fdd4b4a74b9f23f796c9110f529ae5f';
    private const STOCK_2211_SHA256 = '3b40dcc56e1915662608d56ad331c78cdc650d9f8145f58a8228232afaac6317';

    private const MARKER = "/** ECSKC Customisation **/\n";

    protected function setUp(): void
    {
        parent::setUp();

        $rule = (string) \file_get_contents(self::repository() . '/data/rules/' . self::RULE_FILE);

        $this->tree->write(self::PACKAGE . '/rules/' . self::RULE_FILE, $rule);

        \preg_match_all('#<srcPathFileName>([^<]+)</srcPathFileName>#', $rule, $sources);

        foreach ($sources[1] as $source) {
            $this->payload($source, (string) \file_get_contents(self::repository() . '/data/payload/' . $source));
        }
    }

    public function testTheOriginalsAreTheOnesTheRuleNames(): void
    {
        self::assertSame(self::STOCK_229_SHA256, \hash('sha256', self::stock229()));
        self::assertSame(self::STOCK_2211_SHA256, \hash('sha256', self::stock2211()));
    }

    public function testThe2211FormIsActionableToVersion2(): void
    {
        $this->tree->write(self::TARGET, self::stock2211());

        $target = $this->target($this->runner()->check(self::TARGET), self::TARGET);

        self::assertSame(TargetStatus::ACTIONABLE, $target->status());
        self::assertSame('the file can be updated to version 2', $target->reason());
    }

    public function testApplyOnThe2211FormInstallsVersion2AndBacksUpTheOriginal(): void
    {
        $this->tree->write(self::TARGET, self::stock2211());

        $report = $this->runner()->apply(self::TARGET);

        self::assertSame(TargetStatus::OK, $this->target($report, self::TARGET)->status());
        self::assertSame(self::payload2(), $this->tree->read(self::TARGET));

        $backup = $this->paths()->backupFile(self::TARGET, 2, self::STOCK_2211_SHA256);
        self::assertFileExists($backup);
        self::assertSame(self::stock2211(), \file_get_contents($backup));
    }

    public function testASecondApplyChangesNothing(): void
    {
        $this->tree->write(self::TARGET, self::stock2211());
        $this->runner()->apply(self::TARGET);

        $before = $this->snapshot();
        $events = $this->events();

        $report = $this->runner()->apply(self::TARGET);

        self::assertSame(TargetStatus::OK, $this->target($report, self::TARGET)->status());
        self::assertSame($before, $this->snapshot());
        self::assertSame($events, $this->events());
    }

    public function testRevertOnThe2211FormRestoresTheExactOriginal(): void
    {
        $this->tree->write(self::TARGET, self::stock2211());
        $this->runner()->apply(self::TARGET);

        $this->runner()->revert(self::TARGET);

        self::assertSame(self::stock2211(), $this->tree->read(self::TARGET));
    }

    /**
     * A site still on Helix 2.2.9 with version 1 applied, as the live site was before
     * this update, stays ok: version 2 expects a different original, not version 1's
     * payload, so it does not gate the file.
     */
    public function testVersion1AlreadyAppliedStaysOk(): void
    {
        $this->tree->write(self::TARGET, self::payload1());

        $target = $this->target($this->runner()->check(self::TARGET), self::TARGET);

        self::assertSame(TargetStatus::OK, $target->status());
        self::assertSame('the file already has the version 1 changes', $target->reason());
    }

    public function testThe229FormIsStillActionableToVersion1Only(): void
    {
        $this->tree->write(self::TARGET, self::stock229());

        $report = $this->runner()->apply(self::TARGET);

        self::assertSame(TargetStatus::OK, $this->target($report, self::TARGET)->status());
        self::assertSame(self::payload1(), $this->tree->read(self::TARGET));
    }

    public function testVersion2AlreadyAppliedIsOk(): void
    {
        $this->tree->write(self::TARGET, self::payload2());

        self::assertSame(
            TargetStatus::OK,
            $this->target($this->runner()->check(self::TARGET), self::TARGET)->status()
        );
    }

    public function testAnUnknownBuildIsBlockedAndLeftAlone(): void
    {
        $changed = self::stock2211() . "\n";
        $this->tree->write(self::TARGET, $changed);

        $report = $this->runner()->apply(self::TARGET);

        self::assertSame(TargetStatus::BLOCKED, $this->target($report, self::TARGET)->status());
        self::assertSame($changed, $this->tree->read(self::TARGET));
        self::assertSame([], $this->backupNames());
    }

    private static function repository(): string
    {
        return \dirname(__DIR__, 3);
    }

    private static function payload1(): string
    {
        return (string) \file_get_contents(self::repository() . '/data/payload/' . self::TARGET . '.v1');
    }

    private static function payload2(): string
    {
        return (string) \file_get_contents(self::repository() . '/data/payload/' . self::TARGET . '.v2');
    }

    private static function stock229(): string
    {
        return self::without(self::payload1(), "\t\t\t\t<button class=\"btn btn-secondary\" type=\"button\" onclick=\"window.history.back();\"><?php echo JText::_('JCANCEL'); ?></button>\n");
    }

    private static function stock2211(): string
    {
        return self::without(self::payload2(), "                <button class=\"btn btn-secondary\" type=\"button\" onclick=\"window.history.back();\"><?php echo Text::_('JCANCEL'); ?></button>\n");
    }

    private static function without(string $payload, string $button): string
    {
        self::assertSame(1, \substr_count($payload, self::MARKER), 'The payload carries the marker once.');
        self::assertSame(1, \substr_count($payload, $button), 'The payload carries the Cancel button once.');

        return \str_replace([self::MARKER, $button], '', $payload);
    }
}
