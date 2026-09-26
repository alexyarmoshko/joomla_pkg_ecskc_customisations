<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BackupCatalog;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\BackupEntry;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

#[CoversClass(BackupCatalog::class)]
#[CoversClass(BackupEntry::class)]
final class BackupCatalogTest extends TestCase
{
    private const TARGET = 'templates/et_tennis/css/override.css';

    private TempTree $tree;

    private PackagePaths $paths;

    /** @var list<string> */
    private array $readOnly = [];

    protected function setUp(): void
    {
        $this->tree  = TempTree::create('ecskc-catalog-');
        $this->paths = new PackagePaths($this->tree->path('com_ecskccustom'));

        \mkdir($this->paths->backup(), 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->readOnly as $directory) {
            @\chmod($directory, 0755);
        }

        $this->tree->remove();
    }

    /**
     * Write a backup of TARGET holding $bytes, modified at $mtime.
     */
    private function backup(string $bytes, int $version, int $mtime): string
    {
        $path = $this->paths->backupFile(self::TARGET, $version, \hash('sha256', $bytes));

        if (!\is_dir(\dirname($path))) {
            \mkdir(\dirname($path), 0755, true);
        }

        \file_put_contents($path, $bytes);
        \touch($path, $mtime);

        return $path;
    }

    /**
     * @return list<BackupEntry>|null
     */
    private function list(?string &$reason = null, bool $requireHashMatch = false): ?array
    {
        return (new BackupCatalog($this->paths))->newestFirst(self::TARGET, 'sha256', $reason, $requireHashMatch);
    }

    /**
     * @param list<BackupEntry>|null $entries
     *
     * @return list<string>
     */
    private static function paths(?array $entries): array
    {
        self::assertNotNull($entries);

        return \array_map(static fn (BackupEntry $entry): string => $entry->path, $entries);
    }

    public function testATargetWithNoMirroredDirectoryHasNoBackupsAndNoFault(): void
    {
        $reason = null;

        self::assertSame([], $this->list($reason));
        self::assertNull($reason);
    }

    public function testBackupsAreListedNewestFirstWithTheirNamesDigestAndVersion(): void
    {
        $old = $this->backup('first', 1, 1_000);
        $new = $this->backup('second', 2, 2_000);

        $entries = $this->list();

        self::assertSame([$new, $old], self::paths($entries));
        self::assertSame(\hash('sha256', 'second'), $entries[0]->digest);
        self::assertSame(2, $entries[0]->version);
    }

    /**
     * Two backups written in the same second. The directory's own order must not
     * decide which is restored, so the later name wins.
     */
    public function testBackupsWithTheSameTimeAreOrderedByNameDescending(): void
    {
        $one = $this->backup('first', 1, 1_000);
        $two = $this->backup('second', 2, 1_000);

        self::assertSame([$two, $one], self::paths($this->list()));
    }

    public function testNamesThatAreNotThisTargetsBackupsAreLeftOut(): void
    {
        $kept      = $this->backup('first', 1, 1_000);
        $directory = \dirname($kept);

        \file_put_contents($directory . '/override.css.v1.abc', 'short digest');
        \file_put_contents($directory . '/other.css.v1.' . \hash('sha256', 'x'), 'another target');
        \mkdir($directory . '/override.css.v2.' . \hash('sha256', 'dir'));
        \symlink($kept, $directory . '/override.css.v3.' . \hash('sha256', 'link'));

        self::assertSame([$kept], self::paths($this->list()));
    }

    public function testABackupWhoseBytesNoLongerMatchItsNameIsLeftOutOnlyWhenAsked(): void
    {
        $sound   = $this->backup('first', 1, 1_000);
        $damaged = $this->backup('second', 2, 2_000);
        \file_put_contents($damaged, 'edited since');
        \touch($damaged, 2_000);

        self::assertSame([$damaged, $sound], self::paths($this->list()));
        self::assertSame([$sound], self::paths($this->list(requireHashMatch: true)));
    }

    public function testADirectoryThatCannotBeListedIsAFaultNotAnEmptyChain(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('root ignores directory permissions, so the listing cannot be made to fail');
        }

        $directory = \dirname($this->backup('first', 1, 1_000));
        \chmod($directory, 0311);
        $this->readOnly[] = $directory;

        $reason = null;

        self::assertNull($this->list($reason));
        self::assertStringContainsString('could not be', (string) $reason);
    }

    public function testADirectoryReplacedByASymbolicLinkIsAFaultNotAnEmptyChain(): void
    {
        $directory = \dirname($this->paths->backupFile(self::TARGET, 1, \hash('sha256', 'x')));
        \mkdir(\dirname($directory), 0755, true);
        \mkdir($this->tree->path('elsewhere'));
        \symlink($this->tree->path('elsewhere'), $directory);

        $reason = null;

        self::assertNull($this->list($reason));
        self::assertStringContainsString('could not be inspected', (string) $reason);
    }
}
