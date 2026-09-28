<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\SchemaValidator;

/**
 * The registry schema and the committed registry.
 */
#[CoversNothing]
final class RegistrySchemaTest extends TestCase
{
    private const REPO = 'https://github.com/alexyarmoshko/joomla_mod_ystides';

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    private static function validator(): SchemaValidator
    {
        return new SchemaValidator(
            self::repositoryRoot() . '/extensions/com_ecskccustom/admin/schema/registry.xsd'
        );
    }

    private static function registry(string $entries): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><registry>' . $entries . '</registry>';
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validRegistries(): array
    {
        $repo = self::REPO;

        return [
            'no entries' => [''],
            'every kind' => [
                '<component element="com_a" client="administrator" repo="' . $repo . '"/>'
                . '<module element="mod_a" client="site" repo="' . $repo . '"/>'
                . '<template element="tpl_a" client="site" repo="' . $repo . '"/>'
                . '<language element="en-GB" client="api" repo="' . $repo . '"/>'
                . '<plugin folder="system" element="a" repo="' . $repo . '"/>'
                . '<package element="pkg_a" repo="' . $repo . '"/>'
                . '<library element="lib_a" repo="' . $repo . '"/>'
                . '<file element="file_a" repo="' . $repo . '"/>'
                . '<cbplugin type="user" folder="plug_a" element="a" repo="' . $repo . '"/>',
            ],
            'a plugin and a module sharing an element' => [
                '<plugin folder="system" element="shared" repo="' . $repo . '"/>'
                . '<module element="shared" client="site" repo="' . $repo . '"/>',
            ],
            'one element in two clients' => [
                '<module element="mod_a" client="site" repo="' . $repo . '"/>'
                . '<module element="mod_a" client="administrator" repo="' . $repo . '"/>',
            ],
            'one plugin element in two folders' => [
                '<plugin folder="system" element="a" repo="' . $repo . '"/>'
                . '<plugin folder="installer" element="a" repo="' . $repo . '"/>',
            ],
        ];
    }

    #[DataProvider('validRegistries')]
    public function testAcceptsAValidRegistry(string $entries): void
    {
        self::assertSame([], self::validator()->validateString(self::registry($entries), 'registry'));
    }

    /**
     * Each invalid registry is pinned to a fragment of the diagnostic it must produce,
     * so it cannot pass by failing for some other reason.
     *
     * @return array<string, array{string, string}>
     */
    public static function invalidRegistries(): array
    {
        $repo = self::REPO;

        return [
            'no repo' => [
                '<package element="pkg_a"/>',
                "The attribute 'repo' is required but missing",
            ],
            'a repo outside github.com' => [
                '<package element="pkg_a" repo="https://gitlab.com/alexyarmoshko/a"/>',
                "attribute 'repo'",
            ],
            'a repo over plain http' => [
                '<package element="pkg_a" repo="http://github.com/alexyarmoshko/a"/>',
                "attribute 'repo'",
            ],
            'a repo naming only an owner' => [
                '<package element="pkg_a" repo="https://github.com/alexyarmoshko"/>',
                "attribute 'repo'",
            ],
            'a plugin with no folder' => [
                '<plugin element="a" repo="' . $repo . '"/>',
                "The attribute 'folder' is required but missing",
            ],
            'a component with no client' => [
                '<component element="com_a" repo="' . $repo . '"/>',
                "The attribute 'client' is required but missing",
            ],
            'a module with no client' => [
                '<module element="mod_a" repo="' . $repo . '"/>',
                "The attribute 'client' is required but missing",
            ],
            'a template with no client' => [
                '<template element="tpl_a" repo="' . $repo . '"/>',
                "The attribute 'client' is required but missing",
            ],
            'a language with no client' => [
                '<language element="en-GB" repo="' . $repo . '"/>',
                "The attribute 'client' is required but missing",
            ],
            'a module in the api client' => [
                '<module element="mod_a" client="api" repo="' . $repo . '"/>',
                "attribute 'client'",
            ],
            'a CB plugin with no type' => [
                '<cbplugin folder="plug_a" element="a" repo="' . $repo . '"/>',
                "The attribute 'type' is required but missing",
            ],
            'a CB plugin with no folder' => [
                '<cbplugin type="user" element="a" repo="' . $repo . '"/>',
                "The attribute 'folder' is required but missing",
            ],
            'an empty element' => [
                '<package element="" repo="' . $repo . '"/>',
                "attribute 'element'",
            ],
            'an attribute the kind does not take' => [
                '<package element="pkg_a" client="site" repo="' . $repo . '"/>',
                "The attribute 'client' is not allowed",
            ],
            'an unknown kind' => [
                '<widget element="a" repo="' . $repo . '"/>',
                "Element 'widget'",
            ],
            'two components with one identity' => [
                '<component element="com_a" client="administrator" repo="' . $repo . '"/>'
                . '<component element="com_a" client="administrator" repo="' . $repo . '"/>',
                'uniqueComponent',
            ],
            'two modules with one identity' => [
                '<module element="mod_a" client="site" repo="' . $repo . '"/>'
                . '<module element="mod_a" client="site" repo="' . $repo . '"/>',
                'uniqueModule',
            ],
            'two templates with one identity' => [
                '<template element="tpl_a" client="site" repo="' . $repo . '"/>'
                . '<template element="tpl_a" client="site" repo="' . $repo . '"/>',
                'uniqueTemplate',
            ],
            'two languages with one identity' => [
                '<language element="en-GB" client="site" repo="' . $repo . '"/>'
                . '<language element="en-GB" client="site" repo="' . $repo . '"/>',
                'uniqueLanguage',
            ],
            'two plugins with one identity' => [
                '<plugin folder="system" element="a" repo="' . $repo . '"/>'
                . '<plugin folder="system" element="a" repo="' . $repo . '"/>',
                'uniquePlugin',
            ],
            'two packages with one identity' => [
                '<package element="pkg_a" repo="' . $repo . '"/><package element="pkg_a" repo="' . $repo . '"/>',
                'uniquePackage',
            ],
            'two libraries with one identity' => [
                '<library element="lib_a" repo="' . $repo . '"/><library element="lib_a" repo="' . $repo . '"/>',
                'uniqueLibrary',
            ],
            'two files with one identity' => [
                '<file element="file_a" repo="' . $repo . '"/><file element="file_a" repo="' . $repo . '"/>',
                'uniqueFile',
            ],
            'two CB plugins with one identity' => [
                '<cbplugin type="user" folder="plug_a" element="a" repo="' . $repo . '"/>'
                . '<cbplugin type="user" folder="plug_a" element="a" repo="' . $repo . '"/>',
                'uniqueCbplugin',
            ],
        ];
    }

    #[DataProvider('invalidRegistries')]
    public function testRejectsAnInvalidRegistryForTheStatedReason(string $entries, string $diagnostic): void
    {
        $violations = self::validator()->validateString(self::registry($entries), 'registry');

        self::assertNotSame([], $violations);
        self::assertStringContainsString($diagnostic, \implode("\n", $violations));
    }

    /**
     * Every extension installed and enabled on the site with a public repository under
     * github.com/alexyarmoshko. `com_yscute` has a repository but is not installed.
     */
    public function testTheCommittedRegistryHoldsExactlyTheSeededEntries(): void
    {
        $path = self::repositoryRoot() . '/data/registry.xml';

        self::assertSame([], self::validator()->validateFile($path));

        $xml = \simplexml_load_file($path);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $entries = [];

        foreach ($xml->children() as $entry) {
            $identity = [$entry->getName()];

            foreach (['type', 'folder', 'element', 'client'] as $attribute) {
                if (isset($entry[$attribute])) {
                    $identity[] = $attribute . '=' . $entry[$attribute];
                }
            }

            $entries[] = \implode(' ', $identity) . ' ' . \substr((string) $entry['repo'], \strlen('https://github.com/'));
        }

        $expected = [
            'package element=pkg_ecskc_customisations alexyarmoshko/joomla_pkg_ecskc_customisations',
            'component element=com_ecskccustom client=administrator alexyarmoshko/joomla_pkg_ecskc_customisations',
            'plugin folder=system element=ecskccustom alexyarmoshko/joomla_pkg_ecskc_customisations',
            'plugin folder=installer element=ecskccustom alexyarmoshko/joomla_pkg_ecskc_customisations',
            'package element=pkg_cbuseronlinestatus alexyarmoshko/joomla_pkg_cbuseronlinestatus',
            'plugin folder=system element=cbuseronlinestatus alexyarmoshko/joomla_pkg_cbuseronlinestatus',
            'module element=mod_cbuseronlinestatus client=site alexyarmoshko/joomla_pkg_cbuseronlinestatus',
            'component element=com_yscbcalendar client=administrator alexyarmoshko/joomla_com_yscbcalendar',
            'component element=com_yscbsubs_saleslist client=administrator alexyarmoshko/joomla_com_yscbsubs_saleslist',
            'component element=com_ysinventory client=administrator alexyarmoshko/joomla_com_ysinventory',
            'module element=mod_yscbsubs_expiredlist client=site alexyarmoshko/joomla_mod_yscbsubs_expiredlist',
            'module element=mod_ystides client=site alexyarmoshko/joomla_mod_ystides',
            'module element=mod_ystipoftheday client=site alexyarmoshko/joomla_mod_ystipoftheday',
            'plugin folder=system element=cbgjguestaccess alexyarmoshko/joomla_plg_cbgjguestaccess',
            'plugin folder=system element=cbgjvisibility alexyarmoshko/joomla_plg_cbgjvisibility',
            'plugin folder=system element=kunenastickycollapse alexyarmoshko/joomla_plg_kunena_stickycollapse',
            'cbplugin type=user folder=plug_yakshavercbbeforeregistrationemailverify element=cbbeforeregverify alexyarmoshko/joomla_plug_cbbeforeregverify',
            'cbplugin type=user folder=plug_yakshavercbgjeventmanager element=cbgjeventmanager alexyarmoshko/joomla_plug_cbgjeventmanager',
        ];

        \sort($entries);
        \sort($expected);

        self::assertCount(18, $entries);
        self::assertSame($expected, $entries);
    }
}
