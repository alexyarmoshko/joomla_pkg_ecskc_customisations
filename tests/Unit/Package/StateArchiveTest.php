<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Package;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\StateArchive;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\StateArchiveFailure;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunLock;
use YakShaver\ECSKCCustomisation\Tests\Support\RunLockProbe;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;
use ZipArchive;

#[CoversClass(StateArchive::class)]
final class StateArchiveTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-archive-');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    /**
     * The package tree with the folders the installer creates already in place.
     *
     * `archive()` takes `run.lock` inside `state/`, so a tree without that folder
     * would fail every case with a lock it could not open. An installed component
     * always has the three folders, decision 25 of `PackagePaths::CREATED_FOLDERS`.
     */
    private function paths(): PackagePaths
    {
        $paths = new PackagePaths($this->tree->path('com_ecskccustom'));

        foreach (PackagePaths::CREATED_FOLDERS as $folder) {
            $directory = $this->tree->path('com_ecskccustom/' . $folder);

            if (!\is_dir($directory)) {
                \mkdir($directory, 0755, true);
            }
        }

        return $paths;
    }

    private function destination(): string
    {
        $directory = $this->tree->path('tmp');
        \mkdir($directory, 0755, true);

        return $directory;
    }

    /**
     * Everything in a directory, hidden entries included: a leftover staging
     * directory is hidden, and a test that only looked at visible names would not
     * see one.
     *
     * @return list<string>
     */
    private static function everythingIn(string $directory): array
    {
        $names = \array_values(\array_diff(\scandir($directory) ?: [], ['.', '..']));

        return \array_map(static fn (string $name): string => $directory . '/' . $name, $names);
    }

    /**
     * @return array<string, string> entry name => bytes
     */
    private function entriesOf(string $archive): array
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive) === true, $archive . ' must open as a zip.');

        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name           = $zip->getNameIndex($index);
            $entries[$name] = $zip->getFromIndex($index);
        }

        $zip->close();

        return $entries;
    }

    public function testItArchivesTheBackupAndStateTreesWithTheirBytes(): void
    {
        $this->tree->write(
            'com_ecskccustom/backup/templates/et_tennis/css/override.css.v1.abc123',
            "body {}\r\n"
        );
        $this->tree->write('com_ecskccustom/backup/templates/et_tennis/css/override.css.frozen', '');
        $this->tree->write('com_ecskccustom/state/events.log', "one\ntwo\n");

        $archive = (new StateArchive($this->paths()))->archive(fn (): string => $this->destination());

        self::assertIsString($archive);
        self::assertSame(
            [
                'backup/templates/et_tennis/css/override.css.frozen'    => '',
                'backup/templates/et_tennis/css/override.css.v1.abc123' => "body {}\r\n",
                'state/events.log'                                      => "one\ntwo\n",
            ],
            $this->entriesOf($archive)
        );
    }

    public function testItLeavesStagingAndTheFolderGuardsOut(): void
    {
        $this->tree->write('com_ecskccustom/backup/.htaccess', 'Deny from all');
        $this->tree->write('com_ecskccustom/backup/index.html', '<html />');
        $this->tree->write('com_ecskccustom/state/.htaccess', 'Deny from all');
        $this->tree->write('com_ecskccustom/state/events.log', 'kept');
        $this->tree->write('com_ecskccustom/staging/override.css.part', 'transient');

        $archive = (new StateArchive($this->paths()))->archive(fn (): string => $this->destination());

        self::assertIsString($archive);
        self::assertSame(['state/events.log' => 'kept'], $this->entriesOf($archive));
    }

    /**
     * A guard's name only means "guard" at a folder's own root. Deeper, it mirrors a
     * site path, and a site's own .htaccess is exactly the kind of file the package
     * displaces and must hand back.
     */
    public function testItKeepsANestedFileNamedLikeAGuard(): void
    {
        $this->tree->write('com_ecskccustom/backup/administrator/.htaccess.v1.abc123', 'site rules');
        $this->tree->write('com_ecskccustom/backup/index.html', '<html />');

        $archive = (new StateArchive($this->paths()))->archive(fn (): string => $this->destination());

        self::assertIsString($archive);
        self::assertSame(
            ['backup/administrator/.htaccess.v1.abc123' => 'site rules'],
            $this->entriesOf($archive)
        );
    }

    public function testItWritesNoArchiveWhenThereIsNoHistory(): void
    {
        $this->tree->write('com_ecskccustom/backup/.htaccess', 'Deny from all');
        $this->tree->write('com_ecskccustom/state/.htaccess', 'Deny from all');

        $destination = $this->destination();

        self::assertNull((new StateArchive($this->paths()))->archive(fn (): string => $destination));
        self::assertSame([], \glob($destination . '/*'));
    }

    public function testItSkipsSymbolicLinksRatherThanFollowingThemOutOfTheTree(): void
    {
        $this->tree->write('outside/secret.txt', 'not package state');
        $this->tree->write('com_ecskccustom/state/events.log', 'kept');
        $this->tree->symlink('com_ecskccustom/backup/secret.txt', $this->tree->path('outside/secret.txt'));

        $archive = (new StateArchive($this->paths()))->archive(fn (): string => $this->destination());

        self::assertIsString($archive);
        self::assertSame(['state/events.log' => 'kept'], $this->entriesOf($archive));
    }

    public function testTheArchiveIsNamedForItsTimeAndIsNotReadableByOthers(): void
    {
        $this->tree->write('com_ecskccustom/state/events.log', 'kept');

        $archive = (new StateArchive($this->paths()))->archive(fn (): string => $this->destination(), 1_772_000_000);

        self::assertIsString($archive);
        self::assertMatchesRegularExpression(
            '/\/com_ecskccustom-state-20260225-061320Z-[0-9a-f]{12}\.zip\z/',
            $archive
        );
        self::assertSame('0600', \substr(\sprintf('%o', \fileperms($archive)), -4));
    }

    /**
     * The mode is not merely attempted.
     *
     * ZipArchive creates the file during close() with whatever the process umask
     * allows, so the archive exists before its mode is set. This asserts the mode the
     * operator is promised, under a umask that would otherwise leave the file
     * world-readable.
     */
    public function testItGivesTheArchiveModeRegardlessOfTheProcessUmask(): void
    {
        $this->tree->write('com_ecskccustom/state/events.log', 'kept');

        $umask = \umask(0);

        try {
            $archive = (new StateArchive($this->paths()))->archive(fn (): string => $this->destination());
        } finally {
            \umask($umask);
        }

        self::assertIsString($archive);
        self::assertSame('0600', \substr(\sprintf('%o', \fileperms($archive)), -4));
    }

    /**
     * Nothing of the package's is left in a folder it does not own.
     *
     * The archive is built inside a private staging directory, so a successful write
     * has to take that directory away again. A leftover would be a 0700 directory in
     * the site's temporary folder that nothing ever cleans up.
     */
    public function testItLeavesOnlyTheArchiveBehind(): void
    {
        $this->tree->write('com_ecskccustom/state/events.log', 'kept');

        $destination = $this->destination();
        $archive     = (new StateArchive($this->paths()))->archive(fn (): string => $destination);

        self::assertIsString($archive);
        self::assertSame([$archive], self::everythingIn($destination));
    }

    /**
     * A destination that cannot be written to fails before any archive exists, rather
     * than falling back to writing somewhere else.
     */
    public function testItRefusesADestinationItCannotWriteTo(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('Root ignores the directory mode this case depends on.');
        }

        $this->tree->write('com_ecskccustom/state/events.log', 'kept');

        $destination = $this->destination();
        \chmod($destination, 0500);

        try {
            (new StateArchive($this->paths()))->archive(fn (): string => $destination);
            self::fail('A destination that cannot be written to must raise.');
        } catch (StateArchiveFailure $failure) {
            self::assertSame(StateArchiveFailure::UNWRITABLE, $failure->reason);
            self::assertSame($destination, $failure->path);
        } finally {
            \chmod($destination, 0755);
        }

        self::assertSame([], self::everythingIn($destination));
    }

    /**
     * A link with the destination's name is not the destination.
     *
     * `is_dir()` says yes to a link to a directory, so a link left in the site's
     * temporary folder would send an archive of displaced site files wherever it
     * points. The installer refuses one by the same rule; this proves the core does
     * not rely on being called by an installer that checked.
     */
    public function testItRefusesADestinationThatIsASymbolicLink(): void
    {
        $this->tree->write('com_ecskccustom/state/events.log', 'kept');
        $this->tree->write('elsewhere/.htaccess', 'someone else\'s rules');

        $link = $this->tree->path('linked-destination');
        \symlink($this->tree->path('elsewhere'), $link);

        try {
            (new StateArchive($this->paths()))->archive(fn (): string => $link);
            self::fail('A linked destination must raise.');
        } catch (StateArchiveFailure $failure) {
            self::assertSame(StateArchiveFailure::UNWRITABLE, $failure->reason);
            self::assertSame($link, $failure->path);
        }

        self::assertSame(
            [$this->tree->path('elsewhere') . '/.htaccess'],
            self::everythingIn($this->tree->path('elsewhere')),
            'Nothing may be written through the link, and its guard must be untouched.'
        );
        self::assertSame("someone else's rules", $this->tree->read('elsewhere/.htaccess'));
    }

    /**
     * A destination under the component is not a destination.
     *
     * Joomla deletes the component tree after the uninstall hook returns. An
     * archive written into a child of that tree is deleted with it, while the
     * operator is told the history was saved.
     */
    public function testItRefusesADestinationInsideTheComponentTree(): void
    {
        $this->tree->write('com_ecskccustom/state/events.log', 'kept');

        $inside = $this->tree->path('com_ecskccustom') . '/archive-here';
        \mkdir($inside, 0755, true);

        try {
            (new StateArchive($this->paths()))->archive(fn (): string => $inside);
            self::fail('A destination inside the component tree must raise.');
        } catch (StateArchiveFailure $failure) {
            self::assertSame(StateArchiveFailure::UNWRITABLE, $failure->reason);
            self::assertSame($inside, $failure->path);
        }

        self::assertSame([], self::everythingIn($inside));
    }

    /**
     * A ".." spelling that lands inside the component is the same destination.
     *
     * The configured temporary path can alias into the tree without being a
     * lexical child of it. The check has to resolve, not compare strings.
     */
    public function testItRefusesADestinationThatAliasesIntoTheComponentTree(): void
    {
        $this->tree->write('com_ecskccustom/state/events.log', 'kept');

        $inside = $this->tree->path('com_ecskccustom') . '/archive-here';
        \mkdir($inside, 0755, true);
        $this->destination();

        $alias = $this->tree->path('tmp') . '/../com_ecskccustom/archive-here';

        try {
            (new StateArchive($this->paths()))->archive(fn (): string => $alias);
            self::fail('A destination that aliases into the component tree must raise.');
        } catch (StateArchiveFailure $failure) {
            self::assertSame(StateArchiveFailure::UNWRITABLE, $failure->reason);
            self::assertSame($alias, $failure->path);
        }

        self::assertSame([], self::everythingIn($inside));
    }

    /**
     * A ".." spelling that leaves the component is a real destination.
     */
    public function testItAcceptsADestinationThatAliasesOutOfTheComponentTree(): void
    {
        $this->tree->write('com_ecskccustom/state/events.log', 'kept');
        $this->destination();

        $alias   = $this->tree->path('com_ecskccustom') . '/../tmp';
        $archive = (new StateArchive($this->paths()))->archive(fn (): string => $alias);

        self::assertIsString($archive);
        self::assertFileExists($archive);
        self::assertSame(
            [(string) \realpath($archive)],
            \array_map(
                static fn (string $path): string => (string) \realpath($path),
                self::everythingIn($this->tree->path('tmp'))
            )
        );
    }

    /**
     * A source the process cannot read is named as the source it is.
     *
     * Measured on PHP 8.3: ZipArchive accepts such a file at addFile() and fails at
     * close(), where the destination is the only path in hand. Reporting that path
     * would send the operator to check a folder that is fine.
     */
    public function testItNamesAnUnreadableSourceRatherThanTheDestination(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('Root reads a file whatever its mode.');
        }

        $log = $this->tree->write('com_ecskccustom/state/events.log', 'kept');
        \chmod($log, 0000);

        $destination = $this->destination();

        try {
            (new StateArchive($this->paths()))->archive(fn (): string => $destination);
            self::fail('An unreadable source must raise.');
        } catch (StateArchiveFailure $failure) {
            self::assertSame(StateArchiveFailure::UNREADABLE, $failure->reason);
            self::assertSame($log, $failure->path);
        } finally {
            \chmod($log, 0644);
        }

        self::assertSame([], self::everythingIn($destination));
    }

    public function testItLeavesNoPartialArchiveUnderTheFinalName(): void
    {
        $this->tree->write('com_ecskccustom/state/events.log', 'kept');

        $destination = $this->tree->path('tmp');

        try {
            (new StateArchive($this->paths()))->archive(fn (): string => $destination);
            self::fail('An unwritable destination must raise.');
        } catch (StateArchiveFailure $failure) {
            self::assertSame(StateArchiveFailure::UNWRITABLE, $failure->reason);
            self::assertSame($destination, $failure->path);
        }

        self::assertFalse(\is_dir($destination));
    }

    /**
     * A destination provider that must not be called.
     *
     * Passed wherever the archive is expected to decide against writing. A closure
     * that returned a path would let the test pass while the operation quietly
     * created a folder in the site's temporary directory for an archive it never
     * wrote; one that raises turns that into a failure.
     */
    private function refusingDestination(): \Closure
    {
        return static function (): string {
            self::fail('The destination provider must not be invoked.');
        };
    }

    /**
     * Can a fresh handle take the lock exclusively? Proof the archive released it.
     */
    private function lockIsFree(): bool
    {
        return RunLockProbe::isFree($this->paths());
    }

    public function testAHeldRunLockFailsTheArchiveWithoutAskingForADestination(): void
    {
        $this->tree->write('com_ecskccustom/state/events.log', 'kept');

        $paths = $this->paths();
        $held  = new RunLock($paths);
        self::assertTrue($held->acquire());

        $destination = $this->tree->root() . '/tmp';

        try {
            (new StateArchive($paths))->archive($this->refusingDestination());
            self::fail('A held run lock must fail the archive.');
        } catch (StateArchiveFailure $failure) {
            self::assertSame(StateArchiveFailure::RUN_LOCKED, $failure->reason);
            self::assertSame($paths->lock(), $failure->path);
        } finally {
            $held->release();
        }

        self::assertFalse(\is_dir($destination), 'A refused archive creates no destination folder.');
    }

    public function testAReadHoldingTheRunLockFailsTheArchiveAsAReadNotAWrite(): void
    {
        $this->tree->write('com_ecskccustom/state/events.log', 'kept');

        $paths = $this->paths();
        $made  = new RunLock($paths);
        self::assertTrue($made->acquire());
        $made->release();

        $reading = new RunLock($paths);
        self::assertTrue($reading->observe());

        try {
            (new StateArchive($paths))->archive($this->refusingDestination());
            self::fail('A read holding the run lock must fail the archive.');
        } catch (StateArchiveFailure $failure) {
            self::assertSame(StateArchiveFailure::REPORT_READING, $failure->reason);
            self::assertSame($paths->lock(), $failure->path);
        } finally {
            $reading->release();
        }

        self::assertFalse(\is_dir($this->tree->root() . '/tmp'));
    }

    public function testARunLockThatCannotBeOpenedFailsTheArchiveAsUnavailable(): void
    {
        $this->tree->write('com_ecskccustom/state/events.log', 'kept');

        $paths = $this->paths();
        \mkdir($paths->lock());

        try {
            (new StateArchive($paths))->archive($this->refusingDestination());
            self::fail('A lock file that cannot be opened must fail the archive.');
        } catch (StateArchiveFailure $failure) {
            self::assertSame(StateArchiveFailure::LOCK_UNAVAILABLE, $failure->reason);
            self::assertSame($paths->lock(), $failure->path);
        }

        self::assertFalse(\is_dir($this->tree->root() . '/tmp'));
    }

    /**
     * The walk happens under the lock, so a tree that holds only the installer's
     * guards is decided after the lock is taken - and taking the lock is what
     * creates `run.lock`. A `contents()` that counted it would make every tree
     * look as though it had history.
     */
    public function testAGuardOnlyTreeWritesNoArchiveAfterTheLockIsTaken(): void
    {
        $this->tree->write('com_ecskccustom/backup/.htaccess', 'Deny from all');
        $this->tree->write('com_ecskccustom/backup/index.html', '<html />');
        $this->tree->write('com_ecskccustom/state/.htaccess', 'Deny from all');
        $this->tree->write('com_ecskccustom/state/index.html', '<html />');

        $paths = $this->paths();

        self::assertNull((new StateArchive($paths))->archive($this->refusingDestination()));
        self::assertFileExists($paths->lock(), 'Taking the lock creates the lock file.');
        self::assertFalse(
            \is_dir($this->tree->root() . '/tmp'),
            'Locking must not create a destination folder.'
        );
        self::assertTrue($this->lockIsFree(), 'The flock is released before the call returns.');
    }

    public function testASuccessfulArchiveOmitsTheLockTheEmptyLogAndPublicationTemporaries(): void
    {
        $this->tree->write('com_ecskccustom/backup/templates/et_tennis/css/override.css.v1.abc', 'old');
        $this->tree->write('com_ecskccustom/state/events.log', '');
        $this->tree->write('com_ecskccustom/state/run.marker.0123456789ab.tmp', 'half a marker');
        $this->tree->write('com_ecskccustom/state/write.intent.0123456789ab.tmp', 'half an intent');
        $this->tree->write('com_ecskccustom/state/events.log.0123456789ab.tmp', 'half a log');
        $this->tree->write('com_ecskccustom/state/run.marker', 'a complete marker');

        $archive = (new StateArchive($this->paths()))->archive(fn (): string => $this->destination());

        self::assertIsString($archive);
        self::assertSame(
            [
                'backup/templates/et_tennis/css/override.css.v1.abc' => 'old',
                'state/run.marker'                                   => 'a complete marker',
            ],
            $this->entriesOf($archive)
        );
        self::assertTrue($this->lockIsFree(), 'The flock is released before the call returns.');
    }

    /**
     * A nested file that happens to carry a state name is a mirrored site path, not
     * the package's own machinery, and is archived as content.
     */
    public function testItKeepsANestedFileNamedLikeStateMachinery(): void
    {
        $this->tree->write('com_ecskccustom/state/nested/run.lock', 'content');

        $archive = (new StateArchive($this->paths()))->archive(fn (): string => $this->destination());

        self::assertIsString($archive);
        self::assertSame(['state/nested/run.lock' => 'content'], $this->entriesOf($archive));
    }

    public function testTheFlockIsReleasedAfterADestinationProviderThrows(): void
    {
        $this->tree->write('com_ecskccustom/state/events.log', 'kept');

        try {
            (new StateArchive($this->paths()))->archive(static function (): string {
                throw new \RuntimeException('no destination');
            });
            self::fail('A destination provider that raises must not be swallowed.');
        } catch (\RuntimeException $error) {
            self::assertSame('no destination', $error->getMessage());
        }

        self::assertTrue($this->lockIsFree(), 'The flock is released after a provider failure.');
    }

    public function testTheFlockIsReleasedAfterAnArchiveFailure(): void
    {
        $this->tree->write('com_ecskccustom/state/events.log', 'kept');

        $destination = $this->tree->path('tmp');

        try {
            (new StateArchive($this->paths()))->archive(fn (): string => $destination);
            self::fail('An unwritable destination must raise.');
        } catch (StateArchiveFailure $failure) {
            self::assertSame(StateArchiveFailure::UNWRITABLE, $failure->reason);
        }

        self::assertTrue($this->lockIsFree(), 'The flock is released after an archive failure.');
    }
}
