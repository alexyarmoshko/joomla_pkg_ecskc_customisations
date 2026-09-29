<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Registry\ExtensionLanguageLoader;

#[CoversClass(ExtensionLanguageLoader::class)]
final class ExtensionLanguageLoaderTest extends TestCase
{
    /**
     * The file and paths `InstallerModel::translate` tries per type, Joomla 5.
     *
     * @return iterable<string, array{string, string, string, int, list<array{string, string}>}>
     */
    public static function types(): iterable
    {
        yield 'component' => ['component', 'com_ysinventory', '', 1, [
            ['com_ysinventory.sys', '/admin'],
            ['com_ysinventory.sys', '/admin/components/com_ysinventory'],
        ]];
        yield 'file' => ['file', 'ecskc', '', 0, [['files_ecskc.sys', '/site']]];
        yield 'library' => ['library', 'cblib', '', 0, [
            ['lib_cblib.sys', '/site'],
            ['lib_cblib.sys', '/site/libraries/cblib'],
        ]];
        yield 'library with a vendor' => ['library', 'yakshaver/tools', '', 0, [
            ['lib_yakshaver_tools.sys', '/site'],
            ['lib_yakshaver_tools.sys', '/site/libraries/yakshaver/tools'],
        ]];
        yield 'site module' => ['module', 'mod_ystides', '', 0, [
            ['mod_ystides.sys', '/site'],
            ['mod_ystides.sys', '/site/modules/mod_ystides'],
        ]];
        yield 'administrator module' => ['module', 'mod_quickicon', '', 1, [
            ['mod_quickicon.sys', '/admin'],
            ['mod_quickicon.sys', '/admin/modules/mod_quickicon'],
        ]];
        yield 'plugin' => ['plugin', 'ecskccustom', 'system', 0, [
            ['plg_system_ecskccustom.sys', '/admin'],
            ['plg_system_ecskccustom.sys', '/plugins/system/ecskccustom'],
        ]];
        yield 'site template' => ['template', 'cassiopeia', '', 0, [
            ['tpl_cassiopeia.sys', '/site'],
            ['tpl_cassiopeia.sys', '/site/templates/cassiopeia'],
        ]];
        yield 'api client reads as administrator' => ['template', 'atum', '', 3, [
            ['tpl_atum.sys', '/admin'],
            ['tpl_atum.sys', '/admin/templates/atum'],
        ]];
        yield 'package' => ['package', 'pkg_ecskc_customisations', '', 0, [['pkg_ecskc_customisations.sys', '/site']]];
        yield 'language' => ['language', 'en-GB', '', 1, [['en-GB.sys', '/site']]];
    }

    /**
     * @param list<array{string, string}> $expected
     */
    #[DataProvider('types')]
    public function testTheFileAndPathsFollowTheInstallerModel(
        string $type,
        string $element,
        string $folder,
        int $clientId,
        array $expected,
    ): void {
        $loader = new ExtensionLanguageLoader('/site', '/admin', '/plugins', static fn (): bool => false);

        self::assertSame($expected, $loader->attempts($type, $element, $folder, $clientId));
    }

    public function testTheSecondPathIsTriedOnlyWhenTheFirstLoadsNothing(): void
    {
        $tried  = [];
        $loader = new ExtensionLanguageLoader('/site', '/admin', '/plugins', static function (string $file, string $path) use (&$tried): bool {
            $tried[] = [$file, $path];

            return true;
        });

        self::assertTrue($loader->load('plugin', 'ecskccustom', 'system', 0));
        self::assertSame([['plg_system_ecskccustom.sys', '/admin']], $tried);
    }

    public function testEveryPathIsTriedWhenNoneLoads(): void
    {
        $tried  = [];
        $loader = new ExtensionLanguageLoader('/site', '/admin', '/plugins', static function (string $file, string $path) use (&$tried): bool {
            $tried[] = $path;

            return false;
        });

        self::assertFalse($loader->load('module', 'mod_ystides', '', 0));
        self::assertSame(['/site', '/site/modules/mod_ystides'], $tried);
    }
}
