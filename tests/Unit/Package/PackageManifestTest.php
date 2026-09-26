<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Package;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The package manifest installs the component and both plugins, component first,
 * blocks their separate uninstall, and agrees with the Makefile on the child
 * archives and the version.
 */
#[CoversNothing]
final class PackageManifestTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    private static function package(): string
    {
        return self::root() . '/extensions/pkg_ecskc_customisations';
    }

    private static function xml(string $path): SimpleXMLElement
    {
        $previous = \libxml_use_internal_errors(true);

        try {
            $xml = \simplexml_load_file($path);
        } finally {
            \libxml_use_internal_errors($previous);
        }

        self::assertInstanceOf(SimpleXMLElement::class, $xml, $path . ' must parse.');

        return $xml;
    }

    private static function manifest(): SimpleXMLElement
    {
        return self::xml(self::package() . '/pkg_ecskc_customisations.xml');
    }

    private static function makefile(): string
    {
        $makefile = \file_get_contents(self::root() . '/Makefile');
        self::assertIsString($makefile);

        return $makefile;
    }

    /**
     * @return list<string>
     */
    private static function makefileList(string $name): array
    {
        self::assertSame(
            1,
            \preg_match('/^' . $name . ' := \\\\\n((?:\t\S+(?: \\\\)?\n)+)/m', self::makefile(), $match),
            $name . ' must be a one-entry-per-line list in the Makefile.'
        );

        return \array_map(
            static fn (string $line): string => \trim($line, " \t\\"),
            \explode("\n", \trim($match[1]))
        );
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private static function children(): array
    {
        $children = [];

        foreach (self::manifest()->files->children() as $child) {
            $children[] = $child;
        }

        return $children;
    }

    public function testItIsAnUpgradablePackageNamedPkgEcskcCustomisations(): void
    {
        $manifest = self::manifest();

        self::assertSame('package', (string) $manifest['type']);
        self::assertSame('upgrade', (string) $manifest['method']);
        // Joomla stores the package element as `pkg_` plus <packagename>.
        self::assertSame('ecskc_customisations', (string) $manifest->packagename);
        self::assertSame('script.php', (string) $manifest->scriptfile);
        self::assertFileExists(self::package() . '/script.php');
    }

    /**
     * PackageManifest reads `1` or `true`; InstallerAdapter then refuses to
     * uninstall a child on its own.
     */
    public function testItBlocksChildUninstall(): void
    {
        self::assertSame('true', (string) self::manifest()->blockChildUninstall);
    }

    /**
     * Joomla installs the children in document order. The component goes first
     * so the installer plugin's checks during this package's own update run the
     * new component code (code_review_s4.v8.md finding 2).
     */
    public function testItInstallsTheComponentAndThenBothPlugins(): void
    {
        self::assertSame('packages', (string) self::manifest()->files['folder']);

        $children = [];

        foreach (self::children() as $child) {
            $children[] = [
                (string) $child,
                (string) $child['type'],
                (string) $child['id'],
                (string) $child['group'],
            ];
        }

        self::assertSame([
            ['com_ecskccustom.zip', 'component', 'com_ecskccustom', ''],
            ['plg_system_ecskccustom.zip', 'plugin', 'ecskccustom', 'system'],
            ['plg_installer_ecskccustom.zip', 'plugin', 'ecskccustom', 'installer'],
        ], $children);
    }

    /**
     * The `id` and `group` are how Joomla finds each child again on uninstall,
     * so they must name what the child's own manifest installs.
     */
    public function testEachChildEntryNamesTheExtensionItsManifestInstalls(): void
    {
        $component = self::xml(self::root() . '/extensions/com_ecskccustom/com_ecskccustom.xml');
        self::assertSame('com_ecskccustom', \strtolower((string) $component->name));

        foreach (['system', 'installer'] as $group) {
            $plugin = self::xml(self::root() . '/extensions/plg_' . $group . '_ecskccustom/ecskccustom.xml');
            self::assertSame($group, (string) $plugin['group']);

            $elements = [];

            foreach ($plugin->files->children() as $entry) {
                if (isset($entry['plugin'])) {
                    $elements[] = (string) $entry['plugin'];
                }
            }

            self::assertSame(['ecskccustom'], $elements);
        }
    }

    public function testTheMakefileBuildsExactlyTheChildArchivesTheManifestNames(): void
    {
        $named = \array_map(static fn (SimpleXMLElement $child): string => (string) $child, self::children());

        self::assertSame($named, self::makefileList('CHILD_ARCHIVES'));
    }

    /**
     * The build writes each child under the name the manifest gives it, from the
     * staging directory that holds that child's file list.
     */
    public function testTheBuildPackagesEachChildFromItsOwnFileList(): void
    {
        $makefile = self::makefile();

        foreach ([
            'com_ecskccustom.zip,$(1)/com,$(COMPONENT_ARCHIVE_FILES)',
            'plg_system_ecskccustom.zip,$(1)/plg_system,$(SYSTEM_PLUGIN_FILES)',
            'plg_installer_ecskccustom.zip,$(1)/plg_installer,$(INSTALLER_PLUGIN_FILES)',
            '$(call package,$(2),$(1)/pkg,$(PKG_ARCHIVE_FILES))',
        ] as $call) {
            self::assertStringContainsString($call, $makefile);
        }
    }

    /**
     * The build stages the manifest and the script from this folder, so a file
     * added here would be source nothing ships.
     */
    public function testThePackageFolderHoldsExactlyTheManifestAndTheScript(): void
    {
        $files = \array_values(\array_diff(\scandir(self::package()) ?: [], ['.', '..']));
        \sort($files);

        self::assertSame(['pkg_ecskc_customisations.xml', 'script.php'], $files);
        self::assertStringContainsString(
            '$(call stage_files,$(1)/pkg,$(2)/$(PKG_ROOT),$(PKG_NAME).xml script.php)',
            self::makefile()
        );
    }

    public function testEveryChildDeclaresThePackageVersion(): void
    {
        $version = (string) self::manifest()->version;

        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);

        foreach ([
            'extensions/com_ecskccustom/com_ecskccustom.xml',
            'extensions/plg_system_ecskccustom/ecskccustom.xml',
            'extensions/plg_installer_ecskccustom/ecskccustom.xml',
        ] as $child) {
            self::assertSame($version, (string) self::xml(self::root() . '/' . $child)->version, $child);
        }
    }

    /**
     * Decision 13: the Makefile reads VERSION from the package manifest, and both
     * builds check every manifest against it before packaging.
     */
    public function testBothBuildsCheckTheVersionsBeforePackaging(): void
    {
        $makefile = self::makefile();

        self::assertStringContainsString('MANIFEST := $(PKG_ROOT)/$(PKG_NAME).xml', $makefile);
        self::assertMatchesRegularExpression('/^override VERSION := .*\$\(MANIFEST\)\)$/m', $makefile);
        self::assertSame(
            [
                '$(MANIFEST)',
                '$(COM_MANIFEST)',
                '$(SYSTEM_PLUGIN_ROOT)/ecskccustom.xml',
                '$(INSTALLER_PLUGIN_ROOT)/ecskccustom.xml',
            ],
            self::makefileList('VERSIONED_MANIFESTS')
        );


        foreach (['dist_release', 'dist_dev'] as $target) {
            self::assertSame(1, \preg_match('/^' . $target . ':\n((?:\t.*\n)+)/m', $makefile, $match), $target);
            $recipe = $match[1];

            $check = \strpos($recipe, '$(call check_versions,');
            $build = \strpos($recipe, '$(call build_package,');

            self::assertIsInt($check, $target . ' must check the versions.');
            self::assertIsInt($build, $target . ' must build the package.');
            self::assertLessThan($build, $check, $target . ' must check the versions before packaging.');
        }
    }

    /**
     * release and dist_release take only dist_release's output locations from
     * outside the Makefile, refuse anything else before any other step, the
     * guard cannot be emptied from the command line, and no byte input reads
     * the environment through `?=`.
     */
    public function testTheReleaseTakesOnlyItsOutputLocationsFromOutside(): void
    {
        $makefile = self::makefile();

        self::assertStringContainsString("\noverride RELEASE_FREE := INSTALL_DIR BUILD_DIR\n", $makefile);
        self::assertStringContainsString("\noverride release_overrides = ", $makefile);
        self::assertStringContainsString("override define refuse_release_overrides\n\t@if [ -n \"$(release_overrides)\" ]; then \\\n", $makefile);
        self::assertStringContainsString("\nrelease:\n\t$(refuse_release_overrides)\n", $makefile);
        self::assertStringContainsString("\ndist_release:\n\t$(refuse_release_overrides)\n", $makefile);

        \preg_match_all('/^(\w+) \?=/m', $makefile, $defaults);
        self::assertSame(
            ['DEPS_CMD', 'TEST_CMD', 'JZIP_MASTER', 'INSTALL_DIR', 'BUILD_DIR', 'RELEASE_NOTES', 'VERSION_ROOT'],
            $defaults[1],
            'a `?=` variable takes its value from the environment, so it must not feed dist_release.'
        );
    }

    /**
     * Decision 12: version 1 ships no update server, and state folders never
     * appear in any manifest.
     */
    public function testTheManifestNamesNoUpdateServerAndNoStateFolder(): void
    {
        $manifest = self::manifest();

        self::assertCount(0, $manifest->updateservers);

        $text = (string) \file_get_contents(self::package() . '/pkg_ecskc_customisations.xml');

        foreach (['backup', 'staging', 'state'] as $state) {
            self::assertStringNotContainsString('>' . $state . '<', $text);
        }
    }
}
