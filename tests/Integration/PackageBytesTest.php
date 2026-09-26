<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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

    private static string $zip;

    private static TempTree $scratch;

    /** Each archive in the package, with the Makefile list that decides its entries. */
    private const ARCHIVE_LISTS = [
        'packages/com_ecskccustom.zip'           => 'COMPONENT_ARCHIVE_FILES',
        'packages/plg_system_ecskccustom.zip'    => 'SYSTEM_PLUGIN_FILES',
        'packages/plg_installer_ecskccustom.zip' => 'INSTALLER_PLUGIN_FILES',
    ];

    public static function setUpBeforeClass(): void
    {
        self::$repo    = \dirname(__DIR__, 2);
        self::$zip     = self::$repo . '/installation/dev/pkg_ecskc_customisations-v0-1-0.zip';
        self::$scratch = TempTree::create('ecskc-package-bytes-');
    }

    public static function tearDownAfterClass(): void
    {
        self::$scratch->remove();
    }

    public function testDistDevProducesAPackageWhoseComponentCarriesTheAuthoredBytes(): void
    {
        $this->requireMake();
        $this->runMake(['dist_dev']);

        self::assertFileExists(self::$zip);

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
        $this->requireMake();
        $this->runMake(['dist_dev']);

        $package = new ZipArchive();
        self::assertTrue($package->open(self::$zip, ZipArchive::RDONLY));

        try {
            self::assertSame($this->makeList('PKG_ARCHIVE_FILES'), self::entries($package), 'the package');
        } finally {
            $package->close();
        }

        foreach (self::ARCHIVE_LISTS as $child => $list) {
            $archive = $this->openChild($child);

            try {
                self::assertSame($this->makeList($list), self::entries($archive), $child);
            } finally {
                $archive->close();
            }
        }
    }

    /**
     * Decision 12: no staged manifest keeps an update server.
     */
    public function testNoManifestInTheDevPackageNamesAnUpdateServer(): void
    {
        $this->requireMake();
        $this->runMake(['dist_dev']);

        $manifests = [
            'packages/com_ecskccustom.zip'           => 'com_ecskccustom.xml',
            'packages/plg_system_ecskccustom.zip'    => 'ecskccustom.xml',
            'packages/plg_installer_ecskccustom.zip' => 'ecskccustom.xml',
        ];

        $package = new ZipArchive();
        self::assertTrue($package->open(self::$zip, ZipArchive::RDONLY));

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
     * All four archives take the commit date and jzip writes every header field,
     * so a rebuild of the same commit is the same bytes.
     */
    public function testTwoDevBuildsFromTheSameCommitProduceTheSamePackage(): void
    {
        $this->requireMake();

        $this->runMake(['dist_dev']);
        $first = \hash_file('sha256', self::$zip);

        $this->runMake(['dist_dev']);
        self::assertSame($first, \hash_file('sha256', self::$zip));
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

            \mkdir(\dirname($root . '/' . $manifest), 0755, true);
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

    public function testVendoredJzipMatchesTheMaster(): void
    {
        $vendored = self::$repo . '/tools/jzip.php';
        $master   = \getenv('HOME') . '/repos/ys_toolbox/src/jzip/jzip.php';

        self::assertFileExists($vendored);

        // The master lives beside this repository on the authoring machine and is
        // reachable nowhere else - not in the DDEV container, not on CI. Skipping
        // there loses no cover: `make lint` runs the same `cmp` on the host, and
        // `make release` runs lint, so a drifted copy still cannot be published.
        if (!\is_file($master)) {
            self::markTestSkipped($master . ' is not present, so the vendored copy cannot be compared here');
        }

        self::assertFileEquals($master, $vendored);
    }

    public function testDistDevDeletesTheZipBeforeValidate(): void
    {
        $this->requireMake();
        $this->runMake(['dist_dev']);
        self::assertFileExists(self::$zip);

        $makefile = (string) \file_get_contents(self::$repo . '/Makefile');

        self::assertMatchesRegularExpression(
            '/^dist_dev:\n\t@rm -f "\$\(DEV_ZIP\)"\n\t\$\(MAKE\) validate/m',
            $makefile,
            'dist_dev must delete the zip before validate, so a failed check cannot leave a stale artefact'
        );
    }

    /**
     * @return list<string>
     */
    private function payloadFiles(): array
    {
        return [
            'apple-touch-icon.png.v1',
            'apple-touch-icon-precomposed.png.v1',
            'components/com_comprofiler/plugin/language/cbpaidsubscriptions-en-gb/language.php.v1',
            'components/com_comprofiler/plugin/language/cbpaidsubscriptions-en-gb/override.php.v1',
            'components/com_comprofiler/plugin/user/plug_cbgallery/library/Controller/FolderController.php.v1',
            'components/com_comprofiler/plugin/user/plug_cbgallery/library/Controller/FolderController.php.v2',
            'components/com_kunena/template/nts_kbee/config/config.xml.v1',
            'components/com_kunena/template/nts_kbee/layouts/topic/edit/default.php.v1',
            'components/com_kunena/template/nts_kbee/layouts/topic/edit/default.php.v2',
            'components/com_kunena/template/nts_kbee/layouts/widget/error/default.php.v1',
            'components/com_kunena/template/nts_kbee/layouts/widget/error/default.php.v2',
            'plugins/system/helixultimate/overrides/com_contact/contact/default_form.php.v1',
            'plugins/system/helixultimate/overrides_legacy/com_contact/contact/default_form.php.v1',
            'templates/et_tennis/css/override.css',
            'templates/et_tennis/options.xml.v1',
        ];
    }

    /**
     * @return list<string>
     */
    private function ruleFiles(): array
    {
        return [
            '10-comprofiler.xml',
            '20-helix-ultimate.xml',
            '30-nts-kbee.xml',
            '40-et-tennis.xml',
            '50-site-icons.xml',
        ];
    }

    /**
     * Skip rather than fail where the build itself cannot run.
     *
     * These two tests drive `make`. The DDEV web container has no `make`, so
     * without this they report a missing build tool as a packaging defect. The
     * host builds releases and has it, so the gate still holds where it matters.
     */
    private function requireMake(): void
    {
        foreach (\explode(\PATH_SEPARATOR, (string) \getenv('PATH')) as $dir) {
            if ($dir !== '' && \is_executable($dir . '/make')) {
                return;
            }
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
     * @return array{int, string} The exit status, and stderr when there is any, else stdout.
     */
    private function make(array $args): array
    {
        $pipes   = [];
        $process = \proc_open(
            \array_merge(['make', '-s', '-C', self::$repo], $args),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        self::assertIsResource($process, 'Could not start make');

        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $status = \proc_close($process);

        return [$status, $stdout . $stderr];
    }

    /**
     * The expanded value of a Makefile list, one entry per line, as make sees it.
     *
     * @return list<string>
     */
    private function makeList(string $name): array
    {
        [$status, $output] = $this->make([
            '--eval',
            'print_list: ; @printf "%s\\n" $($(LIST))',
            'print_list',
            'LIST=' . $name,
        ]);

        self::assertSame(0, $status, $output);

        $entries = \explode("\n", \trim($output));
        self::assertNotSame([''], $entries, $name . ' must not be empty');

        return $entries;
    }

    /**
     * Copy a child archive out of the dev package and open it. ZipArchive reads
     * only from a file, so the child lands in this test's scratch tree first.
     */
    private function openChild(string $entry): ZipArchive
    {
        $package = new ZipArchive();
        self::assertTrue($package->open(self::$zip, ZipArchive::RDONLY));

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
