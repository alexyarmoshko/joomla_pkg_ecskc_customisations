<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryEntry;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryLoader;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryLoadResult;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\SchemaValidator;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

#[CoversClass(RegistryLoader::class)]
#[CoversClass(RegistryLoadResult::class)]
#[CoversClass(RegistryEntry::class)]
final class RegistryLoaderTest extends TestCase
{
    private const REPO = 'https://github.com/alexyarmoshko/joomla_mod_ystides';

    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-registry-');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    private static function loader(): RegistryLoader
    {
        return new RegistryLoader(new SchemaValidator(
            self::repositoryRoot() . '/extensions/com_ecskccustom/admin/schema/registry.xsd'
        ));
    }

    private function registryFile(string $bytes): string
    {
        return $this->tree->write('registry.xml', $bytes);
    }

    private static function registry(string $entries): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><registry>' . $entries . '</registry>';
    }

    /**
     * @return list<string>
     */
    private static function identities(RegistryLoadResult $result): array
    {
        return \array_map(
            static fn (RegistryEntry $e): string => \implode(
                ' ',
                [$e->kind, $e->cbType, $e->folder, $e->element, (string) $e->clientId, $e->repo]
            ),
            $result->entries
        );
    }

    public function testAMissingFileIsALoadFaultNamingIt(): void
    {
        $path   = $this->tree->path('registry.xml');
        $result = self::loader()->load($path);

        self::assertFalse($result->isValid());
        self::assertSame([], $result->entries);
        self::assertSame([$path . ': the registry file does not exist'], $result->violations);
    }

    public function testADirectoryInPlaceOfTheFileIsALoadFault(): void
    {
        $this->tree->write('registry.xml/index.html', '');
        $path   = $this->tree->path('registry.xml');
        $result = self::loader()->load($path);

        self::assertFalse($result->isValid());
        self::assertSame([$path . ': the registry file does not exist'], $result->violations);
    }

    public function testASymbolicLinkIsALoadFaultEvenToAValidRegistry(): void
    {
        $this->tree->write('elsewhere.xml', self::registry('<package element="pkg_a" repo="' . self::REPO . '"/>'));
        $path   = $this->tree->symlink('registry.xml', $this->tree->path('elsewhere.xml'));
        $result = self::loader()->load($path);

        self::assertFalse($result->isValid());
        self::assertSame([], $result->entries);
        self::assertStringStartsWith($path . ': is a symbolic link', $result->violations[0]);
    }

    public function testMalformedXmlIsALoadFaultNamingTheParseError(): void
    {
        $path   = $this->registryFile('<?xml version="1.0"?><registry><package element="a"');
        $result = self::loader()->load($path);

        self::assertFalse($result->isValid());
        self::assertSame([], $result->entries);
        self::assertStringStartsWith($path . ': ', $result->violations[0]);
        self::assertStringContainsString('(line 1)', $result->violations[0]);
    }

    public function testASchemaFailureIsALoadFaultNamingTheViolation(): void
    {
        $path   = $this->registryFile(self::registry(
            '<package element="pkg_a" repo="' . self::REPO . '"/>'
            . '<plugin element="a" repo="' . self::REPO . '"/>'
        ));
        $result = self::loader()->load($path);

        self::assertFalse($result->isValid());
        self::assertSame([], $result->entries, 'A registry that fails the schema yields no entries, not the valid ones.');
        self::assertStringStartsWith($path . ': ', $result->violations[0]);
        self::assertStringContainsString(
            "The attribute 'folder' is required but missing",
            \implode("\n", $result->violations)
        );
    }

    public function testAnEmptyRegistryLoadsWithNoEntries(): void
    {
        $result = self::loader()->load($this->registryFile(self::registry('')));

        self::assertTrue($result->isValid());
        self::assertSame([], $result->entries);
    }

    public function testClientMapsToClientIdAsJoomlaNumbersIt(): void
    {
        $result = self::loader()->load($this->registryFile(self::registry(
            '<module element="mod_a" client="site" repo="' . self::REPO . '"/>'
            . '<component element="com_a" client="administrator" repo="' . self::REPO . '"/>'
            . '<language element="en-GB" client="api" repo="' . self::REPO . '"/>'
            . '<plugin folder="system" element="a" repo="' . self::REPO . '"/>'
            . '<package element="pkg_a" repo="' . self::REPO . '"/>'
        )));

        self::assertSame([], $result->violations);
        self::assertSame(
            [0, 1, 3, 0, 0],
            \array_map(static fn (RegistryEntry $e): int => $e->clientId, $result->entries)
        );
    }

    public function testEveryKindLoadsWithItsIdentityInDocumentOrder(): void
    {
        $repo   = self::REPO;
        $result = self::loader()->load($this->registryFile(self::registry(
            '<component element="com_a" client="administrator" repo="' . $repo . '"/>'
            . "\n    <!-- a comment between entries -->\n"
            . '<module element="mod_a" client="site" repo="' . $repo . '"/>'
            . '<template element="tpl_a" client="administrator" repo="' . $repo . '"/>'
            . '<language element="en-GB" client="api" repo="' . $repo . '"/>'
            . '<plugin folder="system" element="a" repo="' . $repo . '"/>'
            . '<package element="pkg_a" repo="' . $repo . '"/>'
            . '<library element="lib_a" repo="' . $repo . '"/>'
            . '<file element="file_a" repo="' . $repo . '"/>'
            . '<cbplugin type="user" folder="plug_a" element="a" repo="' . $repo . '"/>'
        )));

        self::assertSame([], $result->violations);
        self::assertSame(
            [
                "component   com_a 1 $repo",
                "module   mod_a 0 $repo",
                "template   tpl_a 1 $repo",
                "language   en-GB 3 $repo",
                "plugin  system a 0 $repo",
                "package   pkg_a 0 $repo",
                "library   lib_a 0 $repo",
                "file   file_a 0 $repo",
                "cbplugin user plug_a a 0 $repo",
            ],
            self::identities($result)
        );
        self::assertSame(
            [false, false, false, false, false, false, false, false, true],
            \array_map(static fn (RegistryEntry $e): bool => $e->isCommunityBuilder(), $result->entries)
        );
    }

    public function testTheCommittedRegistryLoadsIntoItsEighteenEntries(): void
    {
        $result = self::loader()->load(self::repositoryRoot() . '/data/registry.xml');

        self::assertSame([], $result->violations);

        $own = 'https://github.com/alexyarmoshko/';

        self::assertSame(
            [
                "package   pkg_ecskc_customisations 0 {$own}joomla_pkg_ecskc_customisations",
                "component   com_ecskccustom 1 {$own}joomla_pkg_ecskc_customisations",
                "plugin  system ecskccustom 0 {$own}joomla_pkg_ecskc_customisations",
                "plugin  installer ecskccustom 0 {$own}joomla_pkg_ecskc_customisations",
                "package   pkg_cbuseronlinestatus 0 {$own}joomla_pkg_cbuseronlinestatus",
                "plugin  system cbuseronlinestatus 0 {$own}joomla_pkg_cbuseronlinestatus",
                "module   mod_cbuseronlinestatus 0 {$own}joomla_pkg_cbuseronlinestatus",
                "component   com_yscbcalendar 1 {$own}joomla_com_yscbcalendar",
                "component   com_yscbsubs_saleslist 1 {$own}joomla_com_yscbsubs_saleslist",
                "component   com_ysinventory 1 {$own}joomla_com_ysinventory",
                "module   mod_yscbsubs_expiredlist 0 {$own}joomla_mod_yscbsubs_expiredlist",
                "module   mod_ystides 0 {$own}joomla_mod_ystides",
                "module   mod_ystipoftheday 0 {$own}joomla_mod_ystipoftheday",
                "plugin  system cbgjguestaccess 0 {$own}joomla_plg_cbgjguestaccess",
                "plugin  system cbgjvisibility 0 {$own}joomla_plg_cbgjvisibility",
                "plugin  system kunenastickycollapse 0 {$own}joomla_plg_kunena_stickycollapse",
                "cbplugin user plug_yakshavercbbeforeregistrationemailverify cbbeforeregverify 0 {$own}joomla_plug_cbbeforeregverify",
                "cbplugin user plug_yakshavercbgjeventmanager cbgjeventmanager 0 {$own}joomla_plug_cbgjeventmanager",
            ],
            self::identities($result)
        );
    }
}
