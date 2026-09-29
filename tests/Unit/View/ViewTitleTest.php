<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\View;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every screen is titled "ECSKC Customisations: " and the label of its submenu entry, as the
 * Dashboard is. Read from the sources, because a view's toolbar needs the Joomla application.
 */
#[CoversNothing]
final class ViewTitleTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function views(): array
    {
        return [
            'dashboard' => ['Dashboard', 'COM_ECSKCCUSTOM_DASHBOARD_TITLE', 'COM_ECSKCCUSTOM_SUBMENU_DASHBOARD'],
            'targets'   => ['Targets', 'COM_ECSKCCUSTOM_TARGETS_TITLE', 'COM_ECSKCCUSTOM_SUBMENU_TARGETS'],
            'plugins'   => ['Plugins', 'COM_ECSKCCUSTOM_PLUGINS_TITLE', 'COM_ECSKCCUSTOM_SUBMENU_PLUGINS'],
        ];
    }

    #[DataProvider('views')]
    public function testTheToolbarTitleNamesTheScreen(string $view, string $titleKey, string $submenuKey): void
    {
        $source = (string) \file_get_contents(self::admin() . '/src/View/' . $view . '/HtmlView.php');

        \preg_match_all("/ToolbarHelper::title\\(Text::_\\('([A-Z0-9_]+)'\\)/", $source, $matches);

        self::assertSame([$titleKey], $matches[1]);

        $component = self::strings('com_ecskccustom.ini');
        $system    = self::strings('com_ecskccustom.sys.ini');

        self::assertSame('ECSKC Customisations: ' . $system[$submenuKey], $component[$titleKey] ?? null);
    }

    private static function admin(): string
    {
        return \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin';
    }

    /**
     * @return array<string, string>
     */
    private static function strings(string $file): array
    {
        $strings = \parse_ini_file(self::admin() . '/language/en-GB/' . $file, false, INI_SCANNER_RAW);
        self::assertIsArray($strings);

        return $strings;
    }
}
