<?php

declare(strict_types=1);

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\View {

    use DOMDocument;
    use DOMXPath;
    use Joomla\CMS\Language\Text;
    use PHPUnit\Framework\Attributes\CoversNothing;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalCheck;

    /**
     * The dashboard's system plugin card, rendered with the real English language file.
     */
    #[CoversNothing]
    final class DashboardLayoutTest extends TestCase
    {
        protected function setUp(): void
        {
            $strings = \parse_ini_file(
                \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/language/en-GB/com_ecskccustom.ini',
                false,
                INI_SCANNER_RAW
            );
            self::assertIsArray($strings);

            Text::$strings = \str_replace('\"', '"', $strings);
        }

        protected function tearDown(): void
        {
            Text::$strings = [];
        }

        /**
         * @return iterable<string, array{OperationalCheck, string, string, bool}>
         */
        public static function outcomes(): iterable
        {
            yield 'enabled' => [
                new OperationalCheck('system_plugin_enabled', 'enabled', false, 'the plugin is enabled'),
                'Enabled',
                'bg-success',
                false,
            ];
            yield 'disabled' => [
                new OperationalCheck('system_plugin_enabled', 'disabled', true, 'the plugin is disabled'),
                'Disabled',
                'bg-danger',
                false,
            ];
            yield 'unknown' => [
                new OperationalCheck('system_plugin_enabled', 'unknown', true, 'no extension record <b>exists</b>'),
                'Unknown',
                'bg-danger',
                true,
            ];
        }

        #[DataProvider('outcomes')]
        public function testTheCardIsGreenOnlyWhenThePluginIsEnabled(
            OperationalCheck $check,
            string $label,
            string $class,
            bool $showsReason,
        ): void {
            $xpath = self::dom(self::render($check));
            $badge = $xpath->query('//*[@id="ecskc-system-plugin"]//span[contains(@class, "badge")]');

            self::assertSame(['System Plugin'], self::texts($xpath, '//*[@id="ecskc-system-plugin"]//h3'));
            self::assertSame(1, $badge->length);
            self::assertSame($label, \trim($badge->item(0)->textContent));
            self::assertSame('badge ' . $class, $badge->item(0)->getAttribute('class'));
            self::assertSame(
                $showsReason ? [$check->reason] : [],
                self::texts($xpath, '//*[@id="ecskc-system-plugin"]//p[contains(@class, "small")]')
            );
            self::assertSame([], self::texts($xpath, '//*[@id="ecskc-system-plugin"]//b'));
        }

        public function testTheCardSitsBelowQuickInfo(): void
        {
            $xpath = self::dom(self::render(
                new OperationalCheck('system_plugin_enabled', 'enabled', false, 'the plugin is enabled')
            ));

            self::assertSame(
                ['Quick Info', 'System Plugin'],
                self::texts($xpath, '//div[contains(@class, "col-lg-4")]/div[contains(@class, "card")]//h3')
            );
        }

        public function testTheFeatureListNamesThePluginsScreen(): void
        {
            $xpath = self::dom(self::render(
                new OperationalCheck('system_plugin_enabled', 'enabled', false, 'the plugin is enabled')
            ));
            $features = self::texts($xpath, '//div[contains(@class, "col-lg-8")]//ul/li');

            self::assertCount(6, $features);
            self::assertSame(
                'Lists the ECSKC extensions: whether each is installed and enabled, its version, any update Joomla has recorded, and its source repository',
                $features[5]
            );

            foreach ($features as $feature) {
                self::assertStringNotContainsString('COM_ECSKCCUSTOM_', $feature);
            }
        }

        private static function render(OperationalCheck $check): string
        {
            $view = new class ($check) {
                public string $version = '1.0.0';

                public string $author = 'Yak Shaver';

                public function __construct(public ?OperationalCheck $systemPlugin)
                {
                }

                public function escape($var): string
                {
                    return $var === null ? '' : \htmlspecialchars((string) $var, ENT_QUOTES, 'UTF-8');
                }
            };

            $file = \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/tmpl/dashboard/default.php';

            return (function (string $file): string {
                \ob_start();

                try {
                    include $file;
                } catch (\Throwable $e) {
                    \ob_end_clean();

                    throw $e;
                }

                return (string) \ob_get_clean();
            })->bindTo($view, null)($file);
        }

        private static function dom(string $html): DOMXPath
        {
            $document = new DOMDocument();
            $previous = \libxml_use_internal_errors(true);

            try {
                $document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
            } finally {
                \libxml_clear_errors();
                \libxml_use_internal_errors($previous);
            }

            return new DOMXPath($document);
        }

        /**
         * @return list<string>
         */
        private static function texts(DOMXPath $xpath, string $query): array
        {
            $texts = [];

            foreach ($xpath->query($query) as $node) {
                $texts[] = \trim(\preg_replace('/\s+/', ' ', $node->textContent));
            }

            return $texts;
        }
    }
}
