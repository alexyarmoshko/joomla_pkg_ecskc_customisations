<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Package;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

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
        $makefile = \file_get_contents(\dirname(__DIR__, 3) . '/Makefile');
        self::assertIsString($makefile);
        self::assertSame(1, \preg_match('/^COMPONENT_FILES := \\\\\n((?:\t\S+(?: \\\\)?\n)+)/m', $makefile, $match));

        return \array_map(
            static fn (string $line): string => \trim($line, " \t\\"),
            \explode("\n", \trim($match[1]))
        );
    }

    public function testTheManifestAddsAnAdministratorMenuEntry(): void
    {
        $menu = self::xml('com_ecskccustom.xml')->administration->menu;

        self::assertCount(1, $menu);
        self::assertSame('COM_ECSKCCUSTOM', (string) $menu);
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

    public function testTheBuildShipsTheWritePermissionCheck(): void
    {
        self::assertContains('admin/src/Access/WritePermission.php', self::componentFiles());
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
            'com_ecskccustom.xml' => ['//administration/menu', 'com_ecskccustom.sys.ini'],
            'admin/access.xml'    => ['//action/@title | //action/@description', 'com_ecskccustom.ini'],
            'admin/config.xml'    => ['//@label | //@description', 'com_ecskccustom.ini'],
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
    }
}
