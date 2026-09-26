<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Package;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use YakShaver\ECSKCCustomisation\Tests\Support\MakefileList;

/**
 * Both plugins' manifests parse, install every source file, and name language keys
 * their `.sys.ini` defines. The build packages each plugin archive from its Makefile
 * list, and that list names exactly the files in the plugin's tree.
 *
 * The checks the two plugins share take the plugin from a data provider. What only
 * one of them promises, the system plugin's distance from the component and the
 * installer plugin's read-only runner, is a test of its own.
 */
#[CoversNothing]
final class PluginManifestTest extends TestCase
{
    private const SYSTEM    = 'system';
    private const INSTALLER = 'installer';

    /** Each plugin's group, as its manifest, directory, namespace and Makefile list spell it. */
    private const PLUGINS = [
        self::SYSTEM    => ['System', 'SYSTEM_PLUGIN_FILES'],
        self::INSTALLER => ['Installer', 'INSTALLER_PLUGIN_FILES'],
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function plugins(): iterable
    {
        foreach (\array_keys(self::PLUGINS) as $group) {
            yield $group => [$group];
        }
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    private static function plugin(string $group): string
    {
        return self::root() . '/extensions/plg_' . $group . '_ecskccustom';
    }

    private static function namespace(string $group): string
    {
        return 'YakShaver\\Plugin\\' . self::PLUGINS[$group][0] . '\\ECSKCCustomisation';
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

    private static function manifest(string $group): SimpleXMLElement
    {
        return self::xml(self::plugin($group) . '/ecskccustom.xml');
    }

    /**
     * @return list<string>
     */
    private static function treeFiles(string $group): array
    {
        $root  = self::plugin($group);
        $files = [];
        $tree  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($tree as $file) {
            $files[] = \substr($file->getPathname(), \strlen($root) + 1);
        }

        \sort($files);

        return $files;
    }

    /**
     * The plugin's PHP with comments removed: the docblocks say what it does not touch.
     *
     * @return array<string, string> Keyed by the file's path in the plugin.
     */
    private static function code(string $group): array
    {
        $code = [];

        foreach (self::treeFiles($group) as $file) {
            if (!\str_ends_with($file, '.php')) {
                continue;
            }

            $code[$file] = '';

            foreach (\token_get_all((string) \file_get_contents(self::plugin($group) . '/' . $file)) as $token) {
                if (!\is_array($token) || !\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                    $code[$file] .= \is_array($token) ? $token[1] : $token;
                }
            }
        }

        return $code;
    }

    #[DataProvider('plugins')]
    public function testItIsAnUpgradablePluginOfItsGroupWithTheElementEcskccustom(string $group): void
    {
        $manifest = self::manifest($group);

        self::assertSame('plugin', (string) $manifest['type']);
        self::assertSame($group, (string) $manifest['group']);
        self::assertSame('upgrade', (string) $manifest['method']);

        // Joomla takes a plugin's element from the `plugin` attribute in <files>.
        $elements = [];

        foreach ($manifest->files->children() as $entry) {
            if (isset($entry['plugin'])) {
                $elements[] = (string) $entry['plugin'];
            }
        }

        self::assertSame(['ecskccustom'], $elements);
    }

    #[DataProvider('plugins')]
    public function testTheNamespaceMatchesTheClassAndTheAutoloader(string $group): void
    {
        $namespace = self::manifest($group)->namespace;

        self::assertSame('src', (string) $namespace['path']);
        self::assertSame(self::namespace($group), (string) $namespace);

        $composer = \json_decode((string) \file_get_contents(self::root() . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(
            'extensions/plg_' . $group . '_ecskccustom/src/',
            $composer['autoload']['psr-4'][self::namespace($group) . '\\'] ?? null
        );

        $class = (string) \file_get_contents(self::plugin($group) . '/src/Extension/ECSKCCustomisationPlugin.php');
        self::assertStringContainsString('namespace ' . $namespace . '\\Extension;', $class);
    }

    #[DataProvider('plugins')]
    public function testTheVersionIsTheComponentsVersion(string $group): void
    {
        $component = self::xml(self::root() . '/extensions/com_ecskccustom/com_ecskccustom.xml');

        self::assertSame((string) $component->version, (string) self::manifest($group)->version);
    }

    /**
     * Joomla copies only what <files> names, so a file outside every entry
     * installs as a plugin missing it.
     */
    #[DataProvider('plugins')]
    public function testTheManifestInstallsEveryFileInTheTree(string $group): void
    {
        $covered = ['ecskccustom.xml'];

        foreach (self::manifest($group)->files->children() as $entry) {
            $covered[] = (string) $entry . ($entry->getName() === 'folder' ? '/' : '');
        }

        foreach (self::treeFiles($group) as $file) {
            $matched = false;

            foreach ($covered as $entry) {
                if (\str_ends_with($entry, '/') ? \str_starts_with($file, $entry) : $file === $entry) {
                    $matched = true;
                }
            }

            self::assertTrue($matched, $file . ' is in the plugin tree but no <files> entry installs it.');
        }

        self::assertContains('src/Extension/ECSKCCustomisationPlugin.php', self::treeFiles($group));
        self::assertContains('services/provider.php', self::treeFiles($group));
    }

    /**
     * The list is the expected side. The tree is read independently, so a file added
     * to the plugin and left out of the build fails here.
     */
    #[DataProvider('plugins')]
    public function testTheMakefileListsExactlyTheFilesInTheTree(string $group): void
    {
        $listed = MakefileList::read(self::PLUGINS[$group][1]);
        \sort($listed);

        self::assertSame(self::treeFiles($group), $listed);
    }

    #[DataProvider('plugins')]
    public function testTheNameAndDescriptionAreDefinedInTheSysIni(string $group): void
    {
        $manifest = self::manifest($group);
        $ini      = (string) \file_get_contents(self::plugin($group) . '/language/en-GB/plg_' . $group . '_ecskccustom.sys.ini');
        \preg_match_all('/^([A-Z0-9_]+)=/m', $ini, $matches);

        // Joomla upper-cases the <name> before translating it.
        self::assertContains(\strtoupper((string) $manifest->name), $matches[1]);
        self::assertContains((string) $manifest->description, $matches[1]);
    }

    /**
     * Decision 20: the system plugin runs on every front-end request, so it must not
     * reach the component, the rule set, the state folders, or the database.
     */
    public function testTheSystemPluginReachesNothingOfTheComponent(): void
    {
        foreach (self::code(self::SYSTEM) as $file => $source) {
            self::assertStringContainsString('ECSKCCustomisationPlugin', $source);

            foreach (['ECSKCCustomisation\\Administrator', 'com_ecskccustom', 'Database', 'bootComponent', 'state/'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, $file . ' must not mention ' . $forbidden . '.');
            }
        }
    }

    /**
     * Every key the installer plugin passes to Text is defined in the file Joomla loads for it.
     */
    public function testEveryInstallerMessageKeyIsDefinedInTheIni(): void
    {
        $plugin = self::plugin(self::INSTALLER);
        $ini    = (string) \file_get_contents($plugin . '/language/en-GB/plg_installer_ecskccustom.ini');
        $source = (string) \file_get_contents($plugin . '/src/Extension/ECSKCCustomisationPlugin.php');
        \preg_match_all('/^([A-Z0-9_]+)=/m', $ini, $defined);
        \preg_match_all("/'(PLG_INSTALLER_ECSKCCUSTOM_[A-Z0-9_]+)'/", $source, $used);

        self::assertNotSame([], $used[1]);

        foreach ($used[1] as $key) {
            self::assertContains($key, $defined[1]);
        }
    }

    /**
     * Decision 5: the installer plugin takes the read-only runner from the component,
     * constructs no core class, never writes, and touches no state.
     */
    public function testTheInstallerPluginReachesOnlyTheReadOnlyRunner(): void
    {
        foreach (self::code(self::INSTALLER) as $file => $source) {
            foreach (['->runner(', '->dryRun(', 'WriteRunnerInterface', 'Core\\Runner;', 'Database', 'state/'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, $file . ' must not mention ' . $forbidden . '.');
            }

            self::assertDoesNotMatchRegularExpression('/\bnew\s+(?!ECSKCCustomisationPlugin\b)[A-Z]/', $source, $file . ' constructs a class.');
        }
    }
}
