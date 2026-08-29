<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Package;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;

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
     * @return array<string, array{string, string}>
     */
    public static function expectedPaths(): array
    {
        return [
            'rules'     => ['rules', self::ROOT . '/rules'],
            'payload'   => ['payload', self::ROOT . '/payload'],
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
        // payload/ ships with the package, so Joomla creates it; the other three are
        // made at install time. All four are protected.
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

        foreach (PackagePaths::ALL_FOLDERS as $folder) {
            self::assertStringNotContainsString(
                \sprintf("'%s'", $folder),
                $script,
                \sprintf('%s is named literally in the installer; take it from PackagePaths.', $folder)
            );
        }
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
}
