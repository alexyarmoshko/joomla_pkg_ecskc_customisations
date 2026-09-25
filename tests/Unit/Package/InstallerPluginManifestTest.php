<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Package;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The installer plugin's manifest parses, installs every source file, and names
 * language keys its `.sys.ini` defines. The Makefile's file list matches the
 * tree, so ticket 49 can package from it.
 */
#[CoversNothing]
final class InstallerPluginManifestTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    private static function plugin(): string
    {
        return self::root() . '/extensions/plg_installer_ecskccustom';
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
        return self::xml(self::plugin() . '/ecskccustom.xml');
    }

    /**
     * @return list<string>
     */
    private static function treeFiles(): array
    {
        $files = [];
        $tree  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::plugin(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($tree as $file) {
            $files[] = \substr($file->getPathname(), \strlen(self::plugin()) + 1);
        }

        \sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function makefileFiles(): array
    {
        $makefile = \file_get_contents(self::root() . '/Makefile');
        self::assertIsString($makefile);
        self::assertSame(1, \preg_match('/^INSTALLER_PLUGIN_FILES := \\\\\n((?:\t\S+(?: \\\\)?\n)+)/m', $makefile, $match));

        $files = \array_map(
            static fn (string $line): string => \trim($line, " \t\\"),
            \explode("\n", \trim($match[1]))
        );
        \sort($files);

        return $files;
    }

    public function testItIsAnInstallerPluginWithTheElementEcskccustom(): void
    {
        $manifest = self::manifest();

        self::assertSame('plugin', (string) $manifest['type']);
        self::assertSame('installer', (string) $manifest['group']);
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

    public function testTheNamespaceMatchesTheClassAndTheAutoloader(): void
    {
        $namespace = self::manifest()->namespace;

        self::assertSame('src', (string) $namespace['path']);
        self::assertSame('YakShaver\\Plugin\\Installer\\ECSKCCustomisation', (string) $namespace);

        $composer = \json_decode((string) \file_get_contents(self::root() . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(
            'extensions/plg_installer_ecskccustom/src/',
            $composer['autoload']['psr-4']['YakShaver\\Plugin\\Installer\\ECSKCCustomisation\\'] ?? null
        );

        $class = (string) \file_get_contents(self::plugin() . '/src/Extension/ECSKCCustomisationPlugin.php');
        self::assertStringContainsString('namespace ' . $namespace . '\\Extension;', $class);
    }

    public function testTheVersionIsTheComponentsVersion(): void
    {
        $component = self::xml(self::root() . '/extensions/com_ecskccustom/com_ecskccustom.xml');

        self::assertSame((string) $component->version, (string) self::manifest()->version);
    }

    /**
     * Joomla copies only what <files> names, so a file outside every entry
     * installs as a plugin missing it.
     */
    public function testTheManifestInstallsEveryFileInTheTree(): void
    {
        $covered = ['ecskccustom.xml'];

        foreach (self::manifest()->files->children() as $entry) {
            $covered[] = (string) $entry . ($entry->getName() === 'folder' ? '/' : '');
        }

        foreach (self::treeFiles() as $file) {
            $matched = false;

            foreach ($covered as $entry) {
                if (\str_ends_with($entry, '/') ? \str_starts_with($file, $entry) : $file === $entry) {
                    $matched = true;
                }
            }

            self::assertTrue($matched, $file . ' is in the plugin tree but no <files> entry installs it.');
        }

        self::assertContains('src/Extension/ECSKCCustomisationPlugin.php', self::treeFiles());
        self::assertContains('services/provider.php', self::treeFiles());
    }

    public function testTheMakefileListsExactlyTheFilesInTheTree(): void
    {
        self::assertSame(self::treeFiles(), self::makefileFiles());
    }

    public function testTheNameAndDescriptionAreDefinedInTheSysIni(): void
    {
        $manifest = self::manifest();
        $ini      = (string) \file_get_contents(self::plugin() . '/language/en-GB/plg_installer_ecskccustom.sys.ini');
        \preg_match_all('/^([A-Z0-9_]+)=/m', $ini, $matches);

        // Joomla upper-cases the <name> before translating it.
        self::assertContains(\strtoupper((string) $manifest->name), $matches[1]);
        self::assertContains((string) $manifest->description, $matches[1]);
    }

    /**
     * Every key the plugin passes to Text is defined in the file Joomla loads for it.
     */
    public function testEveryMessageKeyIsDefinedInTheIni(): void
    {
        $ini    = (string) \file_get_contents(self::plugin() . '/language/en-GB/plg_installer_ecskccustom.ini');
        $source = (string) \file_get_contents(self::plugin() . '/src/Extension/ECSKCCustomisationPlugin.php');
        \preg_match_all('/^([A-Z0-9_]+)=/m', $ini, $defined);
        \preg_match_all("/'(PLG_INSTALLER_ECSKCCUSTOM_[A-Z0-9_]+)'/", $source, $used);

        self::assertNotSame([], $used[1]);

        foreach ($used[1] as $key) {
            self::assertContains($key, $defined[1]);
        }
    }

    /**
     * Decision 5 and the ticket's constraints: the plugin takes the read-only runner
     * from the component, constructs no core class, never writes, and touches no state.
     */
    public function testThePluginSourceReachesOnlyTheReadOnlyRunner(): void
    {
        foreach (self::treeFiles() as $file) {
            if (!\str_ends_with($file, '.php')) {
                continue;
            }

            // Code only: the docblocks say what the plugin does not do.
            $source = '';

            foreach (\token_get_all((string) \file_get_contents(self::plugin() . '/' . $file)) as $token) {
                if (!\is_array($token) || !\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                    $source .= \is_array($token) ? $token[1] : $token;
                }
            }

            foreach (['->runner(', '->dryRun(', 'WriteRunnerInterface', 'Core\\Runner;', 'Database', 'state/'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, $file . ' must not mention ' . $forbidden . '.');
            }

            self::assertDoesNotMatchRegularExpression('/\bnew\s+(?!ECSKCCustomisationPlugin\b)[A-Z]/', $source, $file . ' constructs a class.');
        }
    }
}
