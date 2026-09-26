<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use YakShaver\ECSKCCustomisation\Tests\Support\MakefileList;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

/**
 * The one reader the manifest and package tests use for the Makefile's lists.
 */
#[CoversNothing]
final class MakefileListTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-makefile-');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    /**
     * Two entries on one continued line, a space before the backslash and none after
     * the last entry: formatting make accepts, so the reader has to as well.
     */
    public function testTheSourceReaderAcceptsAnyLayoutMakeAccepts(): void
    {
        $makefile = $this->tree->write('Makefile', "OTHER := x\nFILES:= \\\n\ta.php b.php \\\n  c.php\\\n\td.php\n\nAFTER := y\n");

        self::assertSame(['a.php', 'b.php', 'c.php', 'd.php'], MakefileList::source('FILES', $makefile));
    }

    public function testMakeAndTheSourceReaderAgreeOnAListThatNamesNoVariable(): void
    {
        if (!MakefileList::makeIsAvailable()) {
            self::markTestSkipped('make is not on PATH');
        }

        $makefile = $this->tree->write('Makefile', "FILES := \\\n\ta.php b.php \\\n\tc.php\n");

        self::assertSame(['a.php', 'b.php', 'c.php'], MakefileList::read('FILES', $makefile));
        self::assertSame(MakefileList::read('FILES', $makefile), MakefileList::source('FILES', $makefile));
    }

    public function testMakeExpandsAVariableTheSourceReaderLeavesAlone(): void
    {
        if (!MakefileList::makeIsAvailable()) {
            self::markTestSkipped('make is not on PATH');
        }

        $makefile = $this->tree->write('Makefile', "ROOT := ext\nFILES := \\\n\t$(ROOT)/a.php\n");

        self::assertSame(['ext/a.php'], MakefileList::read('FILES', $makefile));
        self::assertSame(['$(ROOT)/a.php'], MakefileList::source('FILES', $makefile));
    }

    public function testTheRepositoryListsReadTheSameEitherWay(): void
    {
        foreach (['COMPONENT_FILES', 'SYSTEM_PLUGIN_FILES', 'INSTALLER_PLUGIN_FILES', 'CHILD_ARCHIVES'] as $name) {
            self::assertSame(MakefileList::source($name), MakefileList::read($name), $name);
        }
    }
}
