<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Who reads the registry, and that the Plugins screen writes nothing, work item 9.
 *
 * The Plugins display is the registry's only runtime reader and tools/validate.php
 * its only build-time reader. The screen has no controller of its own, so no task can
 * enable, disable or change an extension through it.
 */
#[CoversNothing]
final class RegistryReadersTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * Every PHP file under extensions/ and tools/ whose code matches the pattern.
     *
     * @return list<string> Repository-relative, sorted.
     */
    private static function filesMatching(string $pattern): array
    {
        $found = [];

        foreach (['extensions', 'tools'] as $top) {
            $tree = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::root() . '/' . $top, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($tree as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                if (\preg_match($pattern, (string) \file_get_contents($file->getPathname())) === 1) {
                    $found[] = \substr($file->getPathname(), \strlen(self::root()) + 1);
                }
            }
        }

        \sort($found);

        return $found;
    }

    public function testOnlyThePluginsModelAsksTheComponentForTheRegistry(): void
    {
        self::assertSame(
            ['extensions/com_ecskccustom/admin/src/Model/PluginsModel.php'],
            self::filesMatching('/->registryResolver\(\)/')
        );
        // The provider asks PackagePaths for the file's path to hand the loader.
        self::assertSame(
            [
                'extensions/com_ecskccustom/admin/services/provider.php',
                'extensions/com_ecskccustom/admin/src/Model/PluginsModel.php',
            ],
            self::filesMatching('/->registry\(\)/')
        );
    }

    /**
     * The service provider points the loader at the installed file and schema;
     * tools/validate.php checks the authored file against the same schema.
     */
    public function testTheRegistryFileIsNamedOnlyWhereItIsLoadedOrValidated(): void
    {
        self::assertSame(
            [
                'extensions/com_ecskccustom/admin/services/provider.php',
                'extensions/com_ecskccustom/admin/src/Core/Package/PackagePaths.php',
                'extensions/com_ecskccustom/admin/src/Core/Registry/RegistryLoader.php',
                'extensions/com_ecskccustom/admin/src/Model/PluginsModel.php',
                'tools/validate.php',
            ],
            self::filesMatching('/RegistryLoader|registry\.xml|registry\.xsd|->registry\(\)/')
        );
        self::assertSame(
            ['extensions/com_ecskccustom/admin/services/provider.php'],
            self::filesMatching('/RegistryLoader::loadWithSchema\(/')
        );
        self::assertSame(
            [
                'extensions/com_ecskccustom/admin/src/Core/Package/PackagePaths.php',
                'extensions/com_ecskccustom/admin/src/Core/Registry/RegistryLoader.php',
                'tools/validate.php',
            ],
            self::filesMatching('/registry\.xsd/')
        );
    }

    public function testThePluginsScreenHasNoControllerAndTheDisplayControllerNoTask(): void
    {
        $controllers = \glob(self::root() . '/extensions/com_ecskccustom/admin/src/Controller/*.php') ?: [];

        self::assertSame(
            ['DisplayController.php', 'TargetsController.php'],
            \array_map('basename', $controllers)
        );

        $source = (string) \file_get_contents(self::root() . '/extensions/com_ecskccustom/admin/src/Controller/DisplayController.php');

        self::assertSame(0, \preg_match('/function\s/', $source), 'DisplayController declares no method, so no task.');
    }

    /**
     * The model and view call nothing that changes Joomla's state: no table, no save,
     * no publish. Their only public methods are the reads the layout uses.
     */
    public function testThePluginsModelAndViewExposeOnlyReads(): void
    {
        $model = 'YakShaver\\Component\\ECSKCCustomisation\\Administrator\\Model\\PluginsModel';
        $view  = 'YakShaver\\Component\\ECSKCCustomisation\\Administrator\\View\\Plugins\\HtmlView';

        foreach ([$model, $view] as $class) {
            $file = self::root() . '/extensions/com_ecskccustom/admin/src/'
                . \str_replace('\\', '/', \substr($class, \strlen('YakShaver\\Component\\ECSKCCustomisation\\Administrator\\'))) . '.php';

            self::assertSame(
                0,
                \preg_match('/\b(getTable|save|publish|store|delete|setUserState|execute|insert|update)\s*\(/i', (string) \file_get_contents($file)),
                $class . ' must not write.'
            );
        }

        self::assertSame(
            ['getItems', 'getPage', 'getPagination', 'getResolution', 'getStart', 'getTotal'],
            self::publicMethodsDeclaredIn($model)
        );
        self::assertSame(['display', 'nameUrl'], self::publicMethodsDeclaredIn($view, ['__construct']));
    }

    /**
     * @param list<string> $ignored
     *
     * @return list<string>
     */
    private static function publicMethodsDeclaredIn(string $class, array $ignored = ['__construct']): array
    {
        $source = (string) \file_get_contents(
            self::root() . '/extensions/com_ecskccustom/admin/src/'
            . \str_replace('\\', '/', \substr($class, \strlen('YakShaver\\Component\\ECSKCCustomisation\\Administrator\\'))) . '.php'
        );

        \preg_match_all('/\n    public function (\w+)\(/', $source, $matches);

        $methods = \array_values(\array_diff($matches[1], $ignored));
        \sort($methods);

        return $methods;
    }
}
