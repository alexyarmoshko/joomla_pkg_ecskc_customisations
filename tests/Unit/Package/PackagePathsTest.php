<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Package;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

#[CoversClass(PackagePaths::class)]
final class PackagePathsTest extends TestCase
{
    private const ROOT = '/site/administrator/components/com_ecskccustom';

    private static function manifestPath(): string
    {
        return \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/com_ecskccustom.xml';
    }

    /**
     * The folders the installed manifest declares, parsed rather than grepped.
     *
     * @return list<string>
     */
    private static function declaredFolders(): array
    {
        $xml = \simplexml_load_file(self::manifestPath());
        self::assertNotFalse($xml, 'The manifest must be well-formed XML.');

        $folders = [];

        foreach ($xml->administration->files->folder as $folder) {
            $folders[] = (string) $folder;
        }

        return $folders;
    }

    /**
     * A quoted folder name in $source is a second copy of the layout.
     */
    private static function assertNoLiteralFolderNames(string $source, string $where): void
    {
        foreach (PackagePaths::ALL_FOLDERS as $folder) {
            self::assertStringNotContainsString(
                \sprintf("'%s'", $folder),
                $source,
                \sprintf('%s is named literally in %s; take it from PackagePaths.', $folder, $where)
            );
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function expectedPaths(): array
    {
        return [
            'rules'     => ['rules', self::ROOT . '/rules'],
            'payload'   => ['payload', self::ROOT . '/payload'],
            'registry'  => ['registry', self::ROOT . '/registry.xml'],
            'registrySchema' => ['registrySchema', self::ROOT . '/schema/registry.xsd'],
            'backup'    => ['backup', self::ROOT . '/backup'],
            'staging'   => ['staging', self::ROOT . '/staging'],
            'state'     => ['state', self::ROOT . '/state'],
            'eventLog'  => ['eventLog', self::ROOT . '/state/events.log'],
            'lock'      => ['lock', self::ROOT . '/state/run.lock'],
            'marker'    => ['marker', self::ROOT . '/state/run.marker'],
        ];
    }

    /**
     * Exact paths, not "starts with the root".
     *
     * The weaker form passed for any wrong path under the root, so it protected
     * nothing about the layout the runner and installer both depend on.
     */
    #[DataProvider('expectedPaths')]
    public function testEachAccessorReturnsItsExactPath(string $accessor, string $expected): void
    {
        $paths = new PackagePaths(self::ROOT);

        self::assertSame($expected, $paths->{$accessor}());
    }

    public function testTheStateFilesUseTheFixedNamesTheRunnerExpects(): void
    {
        // The runner reads these by name rather than discovering them, so the names
        // are part of the contract, not an implementation detail.
        self::assertSame('events.log', PackagePaths::EVENT_LOG);
        self::assertSame('run.lock', PackagePaths::LOCK);
        self::assertSame('run.marker', PackagePaths::MARKER);
    }

    public function testPayloadIsProtectedButNotCreatedByTheInstaller(): void
    {

        self::assertSame(['backup', 'staging', 'state'], PackagePaths::CREATED_FOLDERS);
        self::assertSame(
            ['payload', 'rules', 'backup', 'staging', 'state'],
            PackagePaths::ALL_FOLDERS
        );
    }

    /**
     * The installer must not carry its own copy of the folder policy.
     *
     * It used to, and the two lists disagreeing is how a folder ends up created but
     * unprotected. The installer now loads PackagePaths by path and reads these
     * constants; this test fails if a literal list reappears there.
     */
    public function testTheInstallerTakesItsFolderPolicyFromPackagePaths(): void
    {
        $script = \file_get_contents(
            \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/script.php'
        );
        self::assertIsString($script);

        self::assertStringContainsString('PackagePaths::CREATED_FOLDERS', $script);
        self::assertStringContainsString('PackagePaths::ALL_FOLDERS', $script);

        self::assertNoLiteralFolderNames($script, 'the installer');
    }

    /**
     * StateArchive used to re-declare backup/ and state/ and rebuild their paths
     * from root(). A rename in PackagePaths then produced a silent no-archive:
     * collect() returns early on a directory that is not there, write() returns
     * null, and the uninstall hook enqueues nothing.
     */
    public function testTheArchiveTakesItsFoldersFromPackagePaths(): void
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 3)
            . '/extensions/com_ecskccustom/admin/src/Core/Package/StateArchive.php'
        );
        self::assertIsString($source);

        self::assertStringContainsString('->backup()', $source);
        self::assertStringContainsString('->state()', $source);

        self::assertNoLiteralFolderNames($source, 'StateArchive');
    }

    /**
     * A false return from postflight is discarded by Joomla.
     *
     * InstallerAdapter::triggerManifestScript() guards its abort with
     * `if ($method !== 'postflight')`, so returning false reported a successful
     * install with unprotected folders. Failure has to throw.
     */
    public function testTheInstallerThrowsRatherThanReturningFalseOnFailure(): void
    {
        $script = \file_get_contents(
            \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/script.php'
        );
        self::assertIsString($script);

        $postflight = \substr($script, (int) \strpos($script, 'public function postflight'));
        $postflight = \substr($postflight, 0, (int) \strpos($postflight, "\n    /**"));

        self::assertStringContainsString('throw new RuntimeException', $postflight);
        self::assertStringNotContainsString('return false', $postflight);
    }

    public function testAFreezeMarkerMirrorsTheSiteTreeUnderBackup(): void
    {
        $paths = new PackagePaths(self::ROOT);

        self::assertSame(
            self::ROOT . '/backup/templates/et_tennis/css/override.css.frozen',
            $paths->freezeMarker('templates/et_tennis/css/override.css')
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pathsThatLeaveTheBackupTree(): array
    {
        return [
            'empty'          => [''],
            'absolute'       => ['/etc/passwd'],
            'parent segment' => ['templates/../../etc/passwd'],
            'dot segment'    => ['templates/./override.css'],
            'empty segment'  => ['templates//override.css'],
            'backslash'      => ['templates\\override.css'],
        ];
    }

    /**
     * The runner asks path policy first, so this cannot fire there. It is here so a
     * later caller that skips that step fails loudly instead of testing some other
     * file for existence and reporting a target frozen or free on the answer.
     */
    #[DataProvider('pathsThatLeaveTheBackupTree')]
    public function testAMarkerIsRefusedForAPathThatIsNotCanonical(string $targetPath): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PackagePaths(self::ROOT))->freezeMarker($targetPath);
    }

    public function testABackupMirrorsTheSiteTreeUnderBackupWithItsVersionAndLowerCaseDigest(): void
    {
        $paths  = new PackagePaths(self::ROOT);
        $digest = \hash('sha256', 'displaced');

        self::assertSame(
            self::ROOT . '/backup/templates/et_tennis/css/override.css.v3.' . $digest,
            $paths->backupFile('templates/et_tennis/css/override.css', 3, \strtoupper($digest))
        );
    }

    public function testABackupNameParsesBackToTheVersionAndDigestBackupFileWrote(): void
    {
        $paths  = new PackagePaths(self::ROOT);
        $digest = \hash('sha256', 'displaced');
        $target = 'templates/et_tennis/css/override.css';
        $path   = $paths->backupFile($target, 3, $digest);

        self::assertSame(
            ['version' => 3, 'digest' => $digest],
            PackagePaths::parseBackupName(\basename($path), $target)
        );
    }

    /**
     * Versions a listing may carry that backupFile() would never have written.
     *
     * Casting the digits to an integer accepts all of these; spelling the integer
     * back and comparing is what refuses them. A name the parser accepts is one
     * revert and redo act on, so a name the package did not write is not one.
     *
     * @return array<string, array{string}>
     */
    public static function versionsBackupFileNeverWrites(): array
    {
        return [
            'version zero'       => ['0'],
            'padded version'     => ['03'],
            'padded version one' => ['001'],
            'past PHP_INT_MAX'   => [\str_repeat('9', 25)],
        ];
    }

    #[DataProvider('versionsBackupFileNeverWrites')]
    public function testABackupNameBackupFileCouldNotHaveWrittenIsNotAPackageBackup(string $version): void
    {
        self::assertNull(PackagePaths::parseBackupName(
            'override.css.v' . $version . '.' . \hash('sha256', 'displaced'),
            'templates/et_tennis/css/override.css'
        ));
    }

    #[DataProvider('pathsThatLeaveTheBackupTree')]
    public function testABackupIsRefusedForAPathThatIsNotCanonical(string $targetPath): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PackagePaths(self::ROOT))->backupFile($targetPath, 1, \hash('sha256', ''));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function versionsAndDigestsNoRuleProduces(): array
    {
        return [
            'version zero'     => [0, \hash('sha256', '')],
            'negative version' => [-1, \hash('sha256', '')],
            'empty digest'     => [1, ''],
            'not hex'          => [1, 'not-a-digest'],
            'path in digest'   => [1, 'ab/../cd'],
        ];
    }

    #[DataProvider('versionsAndDigestsNoRuleProduces')]
    public function testABackupIsRefusedForAVersionOrDigestNoRuleProduces(int $version, string $digest): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PackagePaths(self::ROOT))->backupFile('templates/override.css', $version, $digest);
    }

    public function testTheManifestDeclaresPayloadAndRulesAndNoStateFolder(): void
    {
        // The three undeclared folders survive a package update because Joomla
        // removes files by comparing manifests. Declaring one here would silently
        // start deleting a site's backups and event log on every update.
        $declared = self::declaredFolders();

        self::assertContains('payload', $declared);
        self::assertContains('rules', $declared);

        foreach (PackagePaths::CREATED_FOLDERS as $folder) {
            self::assertNotContains(
                $folder,
                $declared,
                \sprintf('%s must not be declared: an update would then delete it.', $folder)
            );
        }
    }

    public function testEveryDeclaredFolderExistsInTheRepository(): void
    {
        // A declared folder that does not ship makes the install fail on a site
        // rather than here.
        $base = \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/';

        foreach (self::declaredFolders() as $folder) {
            self::assertDirectoryExists($base . $folder);
        }
    }

    /**
     * Containment is where a path lands, not how it is spelled.
     *
     * The uninstall archive uses this to refuse a configured temporary folder that
     * sits under the component. A direct child is the obvious case; a ".." alias
     * that re-enters is the one a string prefix would miss, and a sibling whose
     * name merely starts the same way is the one a string prefix would catch.
     */
    public function testContainsFollowsAliasesAndDoesNotPrefixMatch(): void
    {
        $tree = TempTree::create('ecskc-paths-');

        try {
            $tree->write('com_ecskccustom/state/events.log', 'kept');
            $root  = $tree->path('com_ecskccustom');
            $paths = new PackagePaths($root);

            $inside = $root . '/archive-here';
            \mkdir($inside, 0755, true);
            $outside = $tree->path('tmp');
            \mkdir($outside, 0755, true);
            $similar = $tree->path('com_ecskccustom-other');
            \mkdir($similar, 0755, true);

            self::assertTrue($paths->contains($inside));
            self::assertTrue($paths->contains($outside . '/../com_ecskccustom/archive-here'));
            self::assertTrue($paths->contains($root));
            self::assertFalse($paths->contains($outside));
            self::assertFalse($paths->contains($root . '/../tmp'));
            self::assertFalse($paths->contains($similar));
        } finally {
            $tree->remove();
        }
    }
}
