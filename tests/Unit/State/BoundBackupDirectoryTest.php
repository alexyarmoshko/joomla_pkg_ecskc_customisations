<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\ProcDescriptor;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BoundBackupDirectory;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

/**
 * The binding itself. Freezer and BackupStore tests cover what is written through it.
 */
#[CoversClass(BoundBackupDirectory::class)]
#[CoversClass(ProcDescriptor::class)]
final class BoundBackupDirectoryTest extends TestCase
{
    private const TARGET = 'templates/et_tennis/css/override.css';

    private TempTree $tree;

    private PackagePaths $paths;

    /** @var list<resource> */
    private array $handles = [];

    protected function setUp(): void
    {
        $this->tree  = TempTree::create('ecskc-bound-');
        $this->paths = new PackagePaths($this->tree->path('com_ecskccustom'));

        \mkdir($this->paths->backup() . '/templates/et_tennis/css', 0755, true);
        \mkdir($this->tree->path('outside'), 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->handles as $handle) {
            \closedir($handle);
        }

        $this->tree->remove();
    }

    private function open(): BoundBackupDirectory
    {
        $bound = BoundBackupDirectory::open($this->paths, self::TARGET, false);

        self::assertInstanceOf(BoundBackupDirectory::class, $bound, \is_string($bound) ? $bound : '');

        return $bound;
    }

    /**
     * Hold enough handles on another directory that a freed descriptor number is taken.
     */
    private function occupyFreedDescriptors(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $handle = \opendir($this->tree->path('outside'));

            self::assertNotFalse($handle);
            $this->handles[] = $handle;
        }
    }

    public function testTwoBindingsOfOneDirectoryHoldTheirOwnDescriptors(): void
    {
        $first  = $this->open();
        $second = $this->open();

        try {
            self::assertNotSame($first->proc, $second->proc);
            self::assertSame(\realpath(\dirname($this->paths->freezeMarker(self::TARGET))), \readlink($first->proc));
            self::assertSame(\readlink($first->proc), \readlink($second->proc));
        } finally {
            $first->close();
            $second->close();
        }
    }

    public function testClosingAnEarlierBindingDoesNotDetachALaterOne(): void
    {
        $first  = $this->open();
        $second = $this->open();
        $first->close();
        $this->occupyFreedDescriptors();

        try {
            $probe = $second->child('probe');

            self::assertSame(5, \file_put_contents($probe, 'bound'));
            self::assertFileExists($this->paths->backup() . '/templates/et_tennis/css/probe');
            self::assertSame(['.', '..'], \scandir($this->tree->path('outside')));
        } finally {
            $second->close();
        }
    }

    public function testADescriptorThatMatchedBeforeTheOpenIsNotTheOneBound(): void
    {
        $parent = \dirname($this->paths->freezeMarker(self::TARGET));
        $held   = \opendir($parent);

        self::assertNotFalse($held);
        $this->handles[] = $held;

        $named = \lstat($parent);
        self::assertNotFalse($named);
        $before = ProcDescriptor::pathsFor($named);
        self::assertCount(1, $before);

        $bound = $this->open();

        try {
            self::assertNotContains($bound->proc, $before);
            self::assertSame([$bound->proc], \array_values(\array_diff(ProcDescriptor::pathsFor($named), $before)));
        } finally {
            $bound->close();
        }
    }
}
