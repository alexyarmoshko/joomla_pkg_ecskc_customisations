<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Package;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use YakShaver\ECSKCCustomisation\Tests\Support\MakefileList;

/**
 * The manifest, access.xml, and config.xml parse, ship, and name only language
 * keys Joomla can find.
 *
 * Joomla reads each key from a different file. The administrator menu loads
 * `com_ecskccustom.sys.ini` (mod_menu's CssMenu). The Options screen and the
 * user permission report load `com_ecskccustom.ini` (com_config's ComponentModel,
 * com_users' DebugHelper). Keys starting with J are Joomla's own.
 */
#[CoversNothing]
final class ComponentManifestTest extends TestCase
{
    /**
     * Source under src/ that never ships, each for a stated reason. Nothing else may be
     * left out of COMPONENT_FILES.
     */
    private const UNSHIPPED = [
        // tools/validate.php runs these at build time. The installed component never does.
        'admin/src/Core/Build/',
    ];

    private static function component(): string
    {
        return \dirname(__DIR__, 3) . '/extensions/com_ecskccustom';
    }

    private static function xml(string $relative): SimpleXMLElement
    {
        $previous = \libxml_use_internal_errors(true);

        try {
            $xml = \simplexml_load_file(self::component() . '/' . $relative);
        } finally {
            \libxml_use_internal_errors($previous);
        }

        self::assertInstanceOf(SimpleXMLElement::class, $xml, $relative . ' must parse.');

        return $xml;
    }

    /**
     * @return list<string>
     */
    private static function keysDefinedIn(string $file): array
    {
        $contents = \file_get_contents(self::component() . '/admin/language/en-GB/' . $file);
        self::assertIsString($contents, $file . ' must be readable.');

        \preg_match_all('/^([A-Z0-9_]+)=/m', $contents, $matches);

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private static function componentFiles(): array
    {
        return MakefileList::read('COMPONENT_FILES');
    }

    public function testTheManifestAddsAnAdministratorMenuEntry(): void
    {
        $menu = self::xml('com_ecskccustom.xml')->administration->menu;

        self::assertCount(1, $menu);
        self::assertSame('COM_ECSKCCUSTOM', (string) $menu);
    }

    /**
     * The top-level entry opens the controller's default view, so the dashboard
     * entry and the controller have to agree on which view that is.
     */
    public function testTheSubmenuOpensTheDashboardTheTargetReportAndThePluginsScreen(): void
    {
        $entries = [];

        foreach (self::xml('com_ecskccustom.xml')->administration->submenu->menu as $menu) {
            $entries[(string) $menu] = (string) $menu['view'];
        }

        self::assertSame([
            'COM_ECSKCCUSTOM_SUBMENU_DASHBOARD' => 'dashboard',
            'COM_ECSKCCUSTOM_SUBMENU_TARGETS'   => 'targets',
            'COM_ECSKCCUSTOM_SUBMENU_PLUGINS'   => 'plugins',
        ], $entries);

        $controller = (string) \file_get_contents(self::component() . '/admin/src/Controller/DisplayController.php');
        self::assertStringContainsString("protected \$default_view = 'dashboard';", $controller);
    }

    public function testTheManifestAndTheBuildShipTheAclFiles(): void
    {
        $shipped = [];

        foreach (self::xml('com_ecskccustom.xml')->administration->files->filename as $file) {
            $shipped[] = (string) $file;
        }

        foreach (['access.xml', 'config.xml'] as $file) {
            self::assertContains($file, $shipped, $file . ' must be in the manifest <files>.');
            self::assertContains('admin/' . $file, self::componentFiles(), $file . ' must be in COMPONENT_FILES.');
        }
    }

    /**
     * Joomla installs only the files the manifest names, so the staged
     * `admin/registry.xml` needs its own `<filename>`. The schema ships under `schema`.
     */
    public function testTheManifestAndTheBuildShipTheRegistryAndItsSchema(): void
    {
        $shipped = [];

        foreach (self::xml('com_ecskccustom.xml')->administration->files->filename as $file) {
            $shipped[] = (string) $file;
        }

        $folders = [];

        foreach (self::xml('com_ecskccustom.xml')->administration->files->folder as $folder) {
            $folders[] = (string) $folder;
        }

        self::assertContains('registry.xml', $shipped);
        self::assertContains('schema', $folders);
        self::assertContains('admin/schema/registry.xsd', self::componentFiles());
        // source(), not read(): without make, read() returns the lists unexpanded.
        self::assertSame(['registry.xml'], MakefileList::source('REGISTRY_FILE'));
        self::assertContains('admin/$(REGISTRY_FILE)', MakefileList::source('COMPONENT_ARCHIVE_FILES'));
    }

    public function testTheBuildShipsTheWritePermissionCheck(): void
    {
        self::assertContains('admin/src/Access/WritePermission.php', self::componentFiles());
    }

    public function testTheManifestShipsTheFormAndLayoutFolders(): void
    {
        $folders = [];

        foreach (self::xml('com_ecskccustom.xml')->administration->files->folder as $folder) {
            $folders[] = (string) $folder;
        }

        self::assertContains('forms', $folders);
        self::assertContains('tmpl', $folders);
    }

    /**
     * The build packages from COMPONENT_FILES alone, so a class, layout, or form
     * missing from it installs as a component that cannot open its own view, or
     * fails on the first check when the missing class is in the core.
     */
    public function testTheBuildShipsEveryClassLayoutAndForm(): void
    {
        $admin = self::component() . '/admin';
        $files = [];

        foreach (['src', 'tmpl', 'forms'] as $folder) {
            $tree = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($admin . '/' . $folder, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($tree as $file) {
                $relative = 'admin/' . \substr($file->getPathname(), \strlen($admin) + 1);

                if (!self::isUnshipped($relative)) {
                    $files[] = $relative;
                }
            }
        }

        self::assertContains('admin/tmpl/targets/default.php', $files);
        self::assertContains('admin/tmpl/dashboard/default.php', $files);
        self::assertContains('admin/tmpl/plugins/default_list.php', $files);
        self::assertContains('admin/forms/filter_plugins.xml', $files);
        self::assertContains('admin/src/Core/Runner.php', $files);
        self::assertSame([], \array_values(\array_diff($files, self::componentFiles())));
    }

    /**
     * An exclusion that matches nothing would hide a renamed file. Each one must
     * still name source that exists and is not shipped.
     */
    public function testEveryUnshippedEntryNamesSourceThatExistsAndDoesNotShip(): void
    {
        foreach (self::UNSHIPPED as $entry) {
            self::assertFileExists(self::component() . '/' . $entry);

            foreach (self::componentFiles() as $shipped) {
                self::assertFalse(
                    \str_starts_with($shipped, $entry),
                    $shipped . ' is in COMPONENT_FILES, but ' . $entry . ' is listed as unshipped.'
                );
            }
        }
    }

    private static function isUnshipped(string $relative): bool
    {
        foreach (self::UNSHIPPED as $entry) {
            if (\str_ends_with($entry, '/') ? \str_starts_with($relative, $entry) : $relative === $entry) {
                return true;
            }
        }

        return false;
    }

    public function testStateFoldersStayOutOfTheManifest(): void
    {
        $folders = [];

        foreach (self::xml('com_ecskccustom.xml')->administration->files->folder as $folder) {
            $folders[] = (string) $folder;
        }

        foreach (['backup', 'staging', 'state'] as $state) {
            self::assertNotContains($state, $folders);
        }
    }

    public function testAccessDeclaresTheCoreActionsAndTheOneWritePermission(): void
    {
        $access = self::xml('admin/access.xml');
        self::assertSame('com_ecskccustom', (string) $access['component']);

        $names = [];

        foreach ($access->section->action as $action) {
            $names[] = (string) $action['name'];
        }

        self::assertSame(['core.admin', 'core.options', 'core.manage', 'ecskccustom.write'], $names);
    }

    public function testConfigHasOnlyAPermissionsFieldsetForThisComponent(): void
    {
        $config = self::xml('admin/config.xml');

        self::assertCount(1, $config->fieldset);
        self::assertSame('permissions', (string) $config->fieldset['name']);
        self::assertCount(1, $config->fieldset->field);

        $field = $config->fieldset->field;
        self::assertSame('rules', (string) $field['type']);
        self::assertSame('com_ecskccustom', (string) $field['component']);
        self::assertSame('component', (string) $field['section']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function languageKeys(): array
    {
        $cases = [];
        $root  = self::component();

        $sources = [
            'com_ecskccustom.xml' => ['//administration/menu | //administration/submenu/menu', 'com_ecskccustom.sys.ini'],
            'admin/access.xml'    => ['//action/@title | //action/@description', 'com_ecskccustom.ini'],
            'admin/config.xml'    => ['//@label | //@description', 'com_ecskccustom.ini'],
            // The filter form renders in the component's own view, which loads the .ini.
            'admin/forms/filter_targets.xml' => ['//@label | //@description | //@hint | //option', 'com_ecskccustom.ini'],
            'admin/forms/filter_plugins.xml' => ['//@label | //@description | //@hint | //option', 'com_ecskccustom.ini'],
        ];

        foreach ($sources as $file => [$xpath, $language]) {
            $xml = \simplexml_load_file($root . '/' . $file);

            if ($xml === false) {
                $cases[$file . ' is unparseable'] = ['', $language];
                continue;
            }

            foreach ($xml->xpath($xpath) as $node) {
                $cases[$file . ' ' . $node] = [(string) $node, $language];
            }
        }

        $cases['Options title'] = ['COM_ECSKCCUSTOM_CONFIGURATION', 'com_ecskccustom.ini'];

        // Keys the layouts and view classes pass to Text. The view runs in the
        // component, so it reads the .ini.
        $code = \array_merge(
            \glob($root . '/admin/tmpl/*/*.php') ?: [],
            \glob($root . '/admin/src/View/*/*.php') ?: []
        );

        foreach ($code as $file) {
            \preg_match_all("/'(COM_ECSKCCUSTOM(?:_[A-Z0-9]+)*)'/", (string) \file_get_contents($file), $matches);

            foreach ($matches[1] as $key) {
                $cases[\basename($file) . ' ' . $key] = [$key, 'com_ecskccustom.ini'];
            }
        }

        return $cases;
    }

    #[DataProvider('languageKeys')]
    public function testALanguageKeyIsDefinedWhereJoomlaReadsIt(string $key, string $language): void
    {
        self::assertNotSame('', $key, 'The file naming the key must parse.');

        if (\str_starts_with($key, 'J')) {
            self::assertMatchesRegularExpression('/^J[A-Z_]+$/', $key);

            return;
        }

        self::assertContains($key, self::keysDefinedIn($language), $key . ' must be in ' . $language . '.');
    }

    public function testTheLanguageKeyProviderFindsTheComponentsOwnKeys(): void
    {
        $keys = \array_column(self::languageKeys(), 0);

        self::assertContains('COM_ECSKCCUSTOM', $keys);
        self::assertContains('COM_ECSKCCUSTOM_ACTION_WRITE', $keys);
        self::assertContains('COM_ECSKCCUSTOM_ACTION_WRITE_DESC', $keys);
        self::assertContains('COM_ECSKCCUSTOM_FILTER_STATUS_SELECT', $keys);
        self::assertContains('COM_ECSKCCUSTOM_FIELD_LAST_PACKAGE_WRITE', $keys);
        self::assertContains('COM_ECSKCCUSTOM_SYSTEM_PLUGIN_UNKNOWN', $keys);
        self::assertContains('COM_ECSKCCUSTOM_SUBMENU_TARGETS', $keys);
        self::assertContains('COM_ECSKCCUSTOM_DASHBOARD_TITLE', $keys);
        self::assertContains('COM_ECSKCCUSTOM_SUBMENU_PLUGINS', $keys);
        self::assertContains('COM_ECSKCCUSTOM_PLUGINS_FILTER_SEARCH_HINT', $keys);
        self::assertContains('COM_ECSKCCUSTOM_PLUGINS_SORT_VERSION_DESC', $keys);
        self::assertContains('COM_ECSKCCUSTOM_PLUGINS_HEADING_REPOSITORY', $keys);
        self::assertContains('COM_ECSKCCUSTOM_PLUGINS_FAULT_HEADING', $keys);
    }
}
