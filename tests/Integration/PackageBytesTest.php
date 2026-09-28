<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\ECSKCCustomisation\Tests\Support\ChildProcess;
use YakShaver\ECSKCCustomisation\Tests\Support\MakefileList;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;
use ZipArchive;

/**
 * The package nests the component and both plugins, each archive holds exactly
 * its file list, and the component inside it carries the authored payload bytes
 * unchanged.
 */
#[CoversNothing]
final class PackageBytesTest extends TestCase
{
    private static string $repo;

    /** The package the content tests read, built on first use and then shared. */
    private static ?string $zip = null;

    /** The package manifest's version, which the Makefile names the ZIP and the release after. */
    private static string $version;

    /** The ZIP's file name, as the Makefile derives it from the version. */
    private static string $zipName;

    private static TempTree $scratch;

    private const UPDATE_URL = 'https://raw.githubusercontent.com/alexyarmoshko/joomla_update_system/refs/heads/main/manifests/pkg_ecskc_customisations.update.xml';

    /** Each archive in the package, with the Makefile list that decides its entries. */
    private const ARCHIVE_LISTS = [
        'packages/com_ecskccustom.zip'           => 'COMPONENT_ARCHIVE_FILES',
        'packages/plg_system_ecskccustom.zip'    => 'SYSTEM_PLUGIN_FILES',
        'packages/plg_installer_ecskccustom.zip' => 'INSTALLER_PLUGIN_FILES',
    ];

    public static function setUpBeforeClass(): void
    {
        self::$repo = \dirname(__DIR__, 2);

        $manifest = \simplexml_load_file(self::$repo . '/extensions/pkg_ecskc_customisations/pkg_ecskc_customisations.xml');
        self::assertNotFalse($manifest);

        self::$version = (string) $manifest->version;
        self::$zipName = 'pkg_ecskc_customisations-v' . \str_replace('.', '-', self::$version) . '.zip';
        self::$scratch = TempTree::create('ecskc-package-bytes-');
    }

    public static function tearDownAfterClass(): void
    {
        self::$scratch->remove();
        self::$zip = null;
    }

    public function testDistDevProducesAPackageWhoseComponentCarriesTheAuthoredBytes(): void
    {
        self::assertFileExists($this->package());

        $archive = $this->openChild('packages/com_ecskccustom.zip');

        try {
            foreach ($this->payloadFiles() as $relative) {
                $authored = self::$repo . '/data/payload/' . $relative;
                $entry    = 'admin/payload/' . $relative;
                $bytes    = $archive->getFromName($entry);

                self::assertNotFalse($bytes, $entry . ' is missing from the package');
                self::assertSame(
                    \hash_file('sha256', $authored),
                    \hash('sha256', $bytes),
                    $relative . ' changed in the package'
                );
            }

            foreach ($this->ruleFiles() as $relative) {
                $authored = self::$repo . '/data/rules/' . $relative;
                $entry    = 'admin/rules/' . $relative;
                $bytes    = $archive->getFromName($entry);

                self::assertNotFalse($bytes, $entry . ' is missing from the package');
                self::assertSame(
                    \hash_file('sha256', $authored),
                    \hash('sha256', $bytes),
                    $relative . ' changed in the package'
                );
            }

            $registry = $archive->getFromName('admin/registry.xml');
            self::assertNotFalse($registry, 'admin/registry.xml is missing from the package');
            self::assertSame(
                \hash_file('sha256', self::$repo . '/data/registry.xml'),
                \hash('sha256', $registry),
                'data/registry.xml changed in the package'
            );
            self::assertNotFalse(
                $archive->getFromName('admin/schema/registry.xsd'),
                'admin/schema/registry.xsd is missing from the package'
            );

            self::assertNotFalse($archive->getFromName('LICENSE'), 'LICENSE is missing from the package');
            self::assertNotFalse($archive->getFromName('script.php'), 'script.php is missing from the archive root');
            self::assertFalse(
                $archive->locateName('params.ini'),
                'params.ini must not ship; a packager that walked a directory would pick it up'
            );
        } finally {
            $archive->close();
        }
    }

    /**
     * The build packages every archive from an explicit list. An entry the list
     * does not name is a file nobody chose to publish.
     */
    public function testEveryArchiveHoldsExactlyItsFileListInOrder(): void
    {
        $package = new ZipArchive();
        self::assertTrue($package->open($this->package(), ZipArchive::RDONLY));

        try {
            self::assertSame(MakefileList::read('PKG_ARCHIVE_FILES'), self::entries($package), 'the package');
        } finally {
            $package->close();
        }

        foreach (self::ARCHIVE_LISTS as $child => $list) {
            $archive = $this->openChild($child);

            try {
                self::assertSame(MakefileList::read($list), self::entries($archive), $child);
            } finally {
                $archive->close();
            }
        }
    }

    /**
     * Decision 52: the release registers an update server, a dev package does not.
     */
    public function testNoManifestInTheDevPackageNamesAnUpdateServer(): void
    {
        $manifests = [
            'packages/com_ecskccustom.zip'           => 'com_ecskccustom.xml',
            'packages/plg_system_ecskccustom.zip'    => 'ecskccustom.xml',
            'packages/plg_installer_ecskccustom.zip' => 'ecskccustom.xml',
        ];

        $package = new ZipArchive();
        self::assertTrue($package->open($this->package(), ZipArchive::RDONLY));

        try {
            $bytes = $package->getFromName('pkg_ecskc_customisations.xml');
            self::assertIsString($bytes);
            self::assertStringNotContainsString('updateservers', $bytes);
        } finally {
            $package->close();
        }

        foreach ($manifests as $child => $manifest) {
            $archive = $this->openChild($child);

            try {
                $bytes = $archive->getFromName($manifest);
                self::assertIsString($bytes, $child . ' must hold ' . $manifest);
                self::assertStringNotContainsString('updateservers', $bytes, $child);
            } finally {
                $archive->close();
            }
        }
    }

    /**
     * Decision 52: the outer package registers the update-system URL, and no child
     * registers one, so Joomla offers one update for the bundle.
     */
    public function testOnlyThePackageManifestRegistersTheUpdateServer(): void
    {
        $manifest = \simplexml_load_file(self::$repo . '/extensions/pkg_ecskc_customisations/pkg_ecskc_customisations.xml');
        self::assertNotFalse($manifest);

        $servers = $manifest->xpath('/extension/updateservers/server');
        self::assertIsArray($servers);
        self::assertCount(1, $servers);
        self::assertSame('extension', (string) $servers[0]['type']);
        self::assertSame(self::UPDATE_URL, \trim((string) $servers[0]));

        foreach ([
            'extensions/com_ecskccustom/com_ecskccustom.xml',
            'extensions/plg_system_ecskccustom/ecskccustom.xml',
            'extensions/plg_installer_ecskccustom/ecskccustom.xml',
        ] as $child) {
            self::assertStringNotContainsString(
                'updateservers',
                (string) \file_get_contents(self::$repo . '/' . $child),
                $child . ' must not register its own update server'
            );
        }
    }

    public function testNoArchiveCarriesAnUpdateDescriptor(): void
    {
        $package = new ZipArchive();
        self::assertTrue($package->open($this->package(), ZipArchive::RDONLY));

        try {
            $entries = self::entries($package);
        } finally {
            $package->close();
        }

        foreach (\array_keys(self::ARCHIVE_LISTS) as $child) {
            $archive = $this->openChild($child);

            try {
                $entries = \array_merge($entries, self::entries($archive));
            } finally {
                $archive->close();
            }
        }

        self::assertSame([], \preg_grep('/\\.update\\.xml$/', $entries));
    }

    /**
     * update_manifest fills the release URL and the exact ZIP's checksum into a copy
     * of the template, and leaves the template itself alone.
     */
    public function testTheDescriptorDescribesTheBuiltPackage(): void
    {
        $this->requireMake();

        $template = self::$repo . '/pkg_ecskc_customisations.update.xml';
        $before   = \hash_file('sha256', $template);
        $install  = self::$scratch->path('descriptor');
        $zip      = $install . '/release/' . self::$zipName;

        \mkdir(\dirname($zip), 0755, true);
        \file_put_contents($zip, 'fixture archive bytes');

        [$status, $output] = $this->make(['update_manifest', 'INSTALL_DIR=' . $install]);
        self::assertSame(0, $status, $output);

        $descriptor = \simplexml_load_file($install . '/release/pkg_ecskc_customisations.update.xml');
        self::assertNotFalse($descriptor);

        $update = $descriptor->update;
        self::assertSame('pkg_ecskc_customisations', (string) $update->element);
        self::assertSame('package', (string) $update->type);
        self::assertSame('site', (string) $update->client, 'a package is client 0; Joomla assumes administrator without it');
        self::assertSame(self::$version, (string) $update->version);
        self::assertSame(
            'https://github.com/alexyarmoshko/joomla_pkg_ecskc_customisations/releases/download/'
            . self::$version . '/' . self::$zipName,
            (string) $update->downloads->downloadurl
        );
        self::assertSame(\hash_file('sha256', $zip), (string) $update->sha256);
        self::assertSame($before, \hash_file('sha256', $template), 'the tracked template must not change');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenTemplates(): iterable
    {
        $placeholder = '<sha256>' . \str_repeat('0', 64) . '</sha256>';
        $real        = '<sha256>' . \str_repeat('a', 64) . '</sha256>';
        $url         = '<downloadurl type="full" format="zip">https://example.invalid/a.zip</downloadurl>';

        yield 'a committed checksum' => [\str_replace($placeholder, $real, self::template()), 'is not a template'];
        yield 'no checksum' => [\str_replace($placeholder, '', self::template()), 'is not a template'];
        yield 'a second checksum' => [\str_replace($placeholder, $placeholder . $real, self::template()), 'is not a template'];
        yield 'no download URL' => [
            (string) \preg_replace('#<downloadurl[^>]*>[^<]*</downloadurl>#', '', self::template()),
            'exactly one <sha256> and one <downloadurl>',
        ];
        yield 'a second download URL' => [
            (string) \preg_replace('#(<downloadurl[^>]*>[^<]*</downloadurl>)#', '$1' . "\n" . $url, self::template()),
            'exactly one <sha256> and one <downloadurl>',
        ];
        yield 'another element' => [
            \str_replace('<element>pkg_ecskc_customisations</element>', '<element>com_ecskccustom</element>', self::template()),
            'must describe element pkg_ecskc_customisations, type package, client site',
        ];
        yield 'no client' => [
            \str_replace('<client>site</client>', '', self::template()),
            'must describe element pkg_ecskc_customisations, type package, client site',
        ];
    }

    /**
     * The gates run against a copy of the Makefile in a scratch tree, because the
     * release targets refuse a command-line template path.
     */
    #[DataProvider('brokenTemplates')]
    public function testTheDescriptorBuildRefusesABrokenTemplate(string $template, string $failure): void
    {
        $this->requireMake();

        $root = self::$scratch->path('broken-' . \md5($template));
        $zip  = $root . '/installation/release/' . self::$zipName;

        \mkdir(\dirname($zip), 0755, true);
        \mkdir($root . '/extensions/pkg_ecskc_customisations', 0755, true);
        \copy(self::$repo . '/Makefile', $root . '/Makefile');
        \copy(
            self::$repo . '/extensions/pkg_ecskc_customisations/pkg_ecskc_customisations.xml',
            $root . '/extensions/pkg_ecskc_customisations/pkg_ecskc_customisations.xml'
        );
        \file_put_contents($root . '/pkg_ecskc_customisations.update.xml', $template);
        \file_put_contents($zip, 'fixture archive bytes');

        [$status, $output] = $this->make(['update_manifest'], $root);

        self::assertNotSame(0, $status, $output);
        self::assertStringContainsString('FAIL: ', $output);
        self::assertStringContainsString($failure, $output);
        self::assertFileDoesNotExist($root . '/installation/release/pkg_ecskc_customisations.update.xml');
        self::assertFileDoesNotExist($root . '/installation/release/pkg_ecskc_customisations.update.xml.part');
    }

    /**
     * All four archives take the commit date and jzip writes every header field,
     * so a rebuild of the same commit is the same bytes.
     */
    public function testTwoDevBuildsFromTheSameCommitProduceTheSamePackage(): void
    {
        $first = \hash_file('sha256', $this->build('reproducible-1'));

        self::assertSame($first, \hash_file('sha256', $this->build('reproducible-2')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function manifests(): iterable
    {
        foreach ([
            'extensions/pkg_ecskc_customisations/pkg_ecskc_customisations.xml',
            'extensions/com_ecskccustom/com_ecskccustom.xml',
            'extensions/plg_system_ecskccustom/ecskccustom.xml',
            'extensions/plg_installer_ecskccustom/ecskccustom.xml',
            'pkg_ecskc_customisations.update.xml',
        ] as $manifest) {
            yield $manifest => [$manifest];
        }
    }

    /**
     * dist_dev and dist_release run the same check against their source root;
     * `make versions` runs it against a copy with one manifest changed.
     */
    #[DataProvider('manifests')]
    public function testAVersionMismatchInAnyManifestFailsNamingTheFile(string $mismatched): void
    {
        $this->requireMake();

        $root = self::$scratch->path(\str_replace('/', '_', $mismatched));

        foreach (self::manifests() as [$manifest]) {
            $bytes = (string) \file_get_contents(self::$repo . '/' . $manifest);

            if ($manifest === $mismatched) {
                $bytes = (string) \preg_replace('#<version>[^<]*</version>#', '<version>9.9.9</version>', $bytes, 1);
            }

            if (!\is_dir(\dirname($root . '/' . $manifest))) {
                \mkdir(\dirname($root . '/' . $manifest), 0755, true);
            }

            \file_put_contents($root . '/' . $manifest, $bytes);
        }

        [$status, $output] = $this->make(['versions', 'VERSION_ROOT=' . $root]);

        self::assertNotSame(0, $status, 'a mismatched ' . $mismatched . ' must fail the build');
        self::assertStringContainsString($root . '/' . $mismatched . " declares version '9.9.9'", $output);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function releaseOverrides(): iterable
    {
        yield 'package manifest' => [['MANIFEST=extensions/com_ecskccustom/com_ecskccustom.xml'], 'MANIFEST'];
        yield 'empty manifest list' => [['VERSIONED_MANIFESTS='], 'VERSIONED_MANIFESTS'];
        yield 'version check macro' => [['check_versions='], 'check_versions'];
        yield 'component root' => [['COM_ROOT=elsewhere'], 'COM_ROOT'];
        yield 'copier' => [['COPY=elsewhere.php'], 'COPY'];
        yield 'widened allowlist' => [
            ['RELEASE_FREE=INSTALL_DIR BUILD_DIR RELEASE_FREE check_versions', 'check_versions='],
            'check_versions',
        ];
        yield 'emptied override list' => [['release_overrides=', 'check_versions='], 'check_versions'];
        yield 'emptied guard' => [['refuse_release_overrides=', 'check_versions='], 'check_versions'];
    }

    /**
     * dist_release refuses a command-line variable before it reads the tag, so
     * this holds on any checkout.
     *
     * @param list<string> $assignments
     */
    #[DataProvider('releaseOverrides')]
    public function testTheReleaseRefusesAVariableFromTheCommandLine(array $assignments, string $name): void
    {
        $this->requireMake();

        [$status, $output] = $this->make(\array_merge(['dist_release'], $assignments));

        self::assertNotSame(0, $status, $output);
        self::assertStringContainsString('FAIL: ' . $name . ' set outside the Makefile', $output);
    }

    /**
     * Both release targets open with the same guard. A dry run shows the guard's
     * first command without running anything, so a bypass cannot reach `git tag`.
     *
     * @param list<string> $assignments
     */
    #[DataProvider('releaseOverrides')]
    public function testBothReleaseTargetsOpenWithTheGuard(array $assignments, string $name): void
    {
        $this->requireMake();

        foreach (['release', 'dist_release'] as $target) {
            [$status, $output] = $this->make(\array_merge(['-n', $target], $assignments));

            self::assertSame(0, $status, $output);
            self::assertStringStartsWith(
                'if [ -n "' . $name . '" ]; then echo "FAIL: ' . $name . ' set outside the Makefile";',
                $output,
                $target . ' must refuse ' . $name . ' before any other step'
            );
        }
    }

    public function testMatchingVersionsPassTheCheck(): void
    {
        $this->requireMake();

        [$status, $output] = $this->make(['versions']);

        self::assertSame(0, $status, $output);
    }

    /**
     * dist_dev deletes the old package before it validates, so a build that fails
     * validation cannot leave the previous package looking like its output.
     */
    public function testAFailedValidationLeavesNoStalePackageBehind(): void
    {
        $this->requireMake();

        $stale  = self::$scratch->write('stale/installation/dev/' . self::$zipName, 'the package an earlier build left');
        $refuse = self::$scratch->write('stale/refuse.php', "<?php\n\nfwrite(STDERR, \"validation refused\\n\");\nexit(1);\n");

        [$status, $output] = $this->make([
            'dist_dev',
            'BUILD_DIR=' . self::$scratch->path('stale/build'),
            'INSTALL_DIR=' . self::$scratch->path('stale/installation'),
            'VALIDATE=' . $refuse,
        ]);

        self::assertNotSame(0, $status, $output);
        self::assertStringContainsString('validation refused', $output);
        self::assertFileDoesNotExist($stale);
    }

    /**
     * Work item 2 of Phase 5: the build checks the payloads inside the package it
     * just wrote, and deletes a package that fails. VerifyPackageScriptTest covers
     * what the check refuses.
     */
    public function testAPackageThatFailsThePayloadCheckIsDeleted(): void
    {
        $this->requireMake();

        $refuse = self::$scratch->write('unverified/refuse.php', "<?php\n\nfwrite(STDERR, \"payload check refused\\n\");\nexit(1);\n");

        [$status, $output] = $this->make([
            'dist_dev',
            'BUILD_DIR=' . self::$scratch->path('unverified/build'),
            'INSTALL_DIR=' . self::$scratch->path('unverified/installation'),
            'VERIFY=' . $refuse,
        ]);

        self::assertNotSame(0, $status, $output);
        self::assertStringContainsString('payload check refused', $output);
        self::assertStringContainsString('deleted - its payload bytes do not match its rules', $output);
        self::assertFileDoesNotExist(self::$scratch->path('unverified/installation/dev/' . self::$zipName));
    }

    public function testTheBuiltPackagePassesThePayloadCheck(): void
    {
        [$status, $stdout, $stderr] = ChildProcess::run([
            \PHP_BINARY,
            self::$repo . '/tools/verify_package.php',
            $this->package(),
        ]);

        self::assertSame(0, $status, $stderr);
        self::assertStringEndsWith(": payload hashes verified\n", $stdout);
    }

    /**
     * @return list<string>
     */
    private function payloadFiles(): array
    {
        return self::authoredFiles(self::$repo . '/data/payload');
    }

    /**
     * @return list<string>
     */
    private function ruleFiles(): array
    {
        return self::authoredFiles(self::$repo . '/data/rules');
    }

    /**
     * Every authored file under `$root`, relative to it, read from the tree rather than
     * from the Makefile, so a file the build leaves out fails the byte comparison.
     * An `index.html` folder guard is not authored content and is left out.
     *
     * @return list<string>
     */
    private static function authoredFiles(string $root): array
    {
        $files = [];
        $tree  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($tree as $file) {
            if ($file->getFilename() !== 'index.html') {
                $files[] = \substr($file->getPathname(), \strlen($root) + 1);
            }
        }

        \sort($files);
        self::assertNotSame([], $files, $root . ' holds no authored file');

        return $files;
    }

    /**
     * A dev build into its own directories under this suite's scratch tree. The suite
     * never writes the repository's build/ or installation/, so two runs cannot
     * overwrite each other's output.
     *
     * @return string The package ZIP.
     */
    private function build(string $name): string
    {
        $this->requireMake();

        $root = self::$scratch->path($name);

        $this->runMake(['dist_dev', 'BUILD_DIR=' . $root . '/build', 'INSTALL_DIR=' . $root . '/installation']);

        return $root . '/installation/dev/' . self::$zipName;
    }

    /**
     * The package the content tests read. They inspect the same source, so one build
     * serves them all.
     */
    private function package(): string
    {
        return self::$zip ??= $this->build('content');
    }

    /**
     * Skip rather than fail where the build itself cannot run.
     *
     * Every test that drives `make` calls this. The DDEV web container has no
     * `make`, so without this they report a missing build tool as a packaging
     * defect. The host builds releases and has it, so the gate still holds where it
     * matters.
     */
    private function requireMake(): void
    {
        if (MakefileList::makeIsAvailable()) {
            return;
        }

        self::markTestSkipped('make is not on PATH, so the build cannot be driven here');
    }

    /**
     * @param list<string> $args
     */
    private function runMake(array $args): void
    {
        [$status, $output] = $this->make($args);

        self::assertSame(0, $status, $output);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string} The exit status, and stdout followed by stderr.
     */
    private function make(array $args, ?string $dir = null): array
    {
        [$status, $stdout, $stderr] = ChildProcess::run(
            \array_merge(['make', '-s', '-C', $dir ?? self::$repo], $args)
        );

        return [$status, $stdout . $stderr];
    }

    /**
     * Copy a child archive out of the dev package and open it. ZipArchive reads
     * only from a file, so the child lands in this test's scratch tree first.
     */
    private function openChild(string $entry): ZipArchive
    {
        $package = new ZipArchive();
        self::assertTrue($package->open($this->package(), ZipArchive::RDONLY));

        try {
            $bytes = $package->getFromName($entry);
        } finally {
            $package->close();
        }

        self::assertIsString($bytes, $entry . ' is missing from the package');

        $path  = self::$scratch->write(\basename($entry), $bytes);
        $child = new ZipArchive();
        self::assertTrue($child->open($path, ZipArchive::RDONLY), $entry . ' must open as a ZIP');

        return $child;
    }

    private static function template(): string
    {
        return (string) \file_get_contents(\dirname(__DIR__, 2) . '/pkg_ecskc_customisations.update.xml');
    }

    /**
     * @return list<string>
     */
    private static function entries(ZipArchive $archive): array
    {
        $entries = [];

        for ($i = 0; $i < $archive->numFiles; $i++) {
            $entries[] = (string) $archive->getNameIndex($i);
        }

        return $entries;
    }
}
