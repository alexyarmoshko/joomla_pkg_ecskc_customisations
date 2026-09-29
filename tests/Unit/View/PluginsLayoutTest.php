<?php

declare(strict_types=1);

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
}

namespace Joomla\CMS\HTML {
    /**
     * Only this test renders a layout that calls HTMLHelper. A sortable header comes
     * back as a marked link, so the test can tell which columns sort.
     */
    final class HTMLHelper
    {
        public static function _(string $key, mixed ...$args): string
        {
            return match ($key) {
                'searchtools.sort' => \sprintf(
                    '<a class="js-stools-column-order" data-order="%s" data-direction="%s" data-current="%s">%s</a>',
                    $args[1],
                    $args[2],
                    $args[3],
                    \Joomla\CMS\Language\Text::_($args[0])
                ),
                'form.token' => '<input type="hidden" name="token" value="1">',
                default      => throw new \LogicException('Unexpected HTMLHelper call ' . $key),
            };
        }
    }
}

namespace Joomla\CMS\Layout {
    final class LayoutHelper
    {
        public static function render(string $layout, mixed $data = null): string
        {
            return '<div id="stub-' . \str_replace('.', '-', $layout) . '"></div>';
        }
    }
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\View {

    use DOMDocument;
    use DOMXPath;
    use Joomla\CMS\Language\Text;
    use Joomla\CMS\Router\Route;
    use PHPUnit\Framework\Attributes\CoversNothing;
    use PHPUnit\Framework\TestCase;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\EntryStatus;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryEntry;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryRow;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\UpdateServerStatus;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\UpdateStatus;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Model\PluginLink;

    /**
     * The Plugins screen's layouts, rendered over hand-built rows with the real English
     * language file. Ticket 93 proves the page on DDEV; this proves what each cell says.
     */
    #[CoversNothing]
    final class PluginsLayoutTest extends TestCase
    {
        private const HEADINGS = [
            'Name', 'Type', 'Version', 'Status', 'Update', 'Update server', 'Package', 'Description', 'Repository',
        ];

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

        public function testTheTableHasTheNineColumnsAndSixSort(): void
        {
            $xpath = self::dom(self::render([self::plugin()], [], 'version', 'DESC'));

            self::assertSame(self::HEADINGS, self::texts($xpath, '//table[@id="ecskc-plugins"]/thead//th'));

            $sorted = [];

            foreach ($xpath->query('//thead//a[contains(@class, "js-stools-column-order")]') as $link) {
                $sorted[] = $link->getAttribute('data-order');
                self::assertSame('DESC', $link->getAttribute('data-direction'), 'The headers follow the page direction.');
                self::assertSame('version', $link->getAttribute('data-current'), 'The headers follow the page ordering.');
            }

            self::assertSame(['name', 'type', 'version', 'status', 'update', 'package'], $sorted);
            self::assertSame(1, $xpath->query('//*[@id="stub-joomla-searchtools-default"]')->length);
            self::assertSame(['FOOTER'], self::texts($xpath, '//*[@id="stub-footer"]'));
        }

        public function testEachCellShowsWhatTheResolverDecided(): void
        {
            $row = new RegistryRow(
                new RegistryEntry('plugin', 'cbuseronlinestatus', 'system', 0, '', 'https://github.com/alexyarmoshko/joomla_pkg_cbuseronlinestatus'),
                386,
                'CB User Online Status',
                'COM_ECSKCCUSTOM_PLUGINS_TYPE_PLUGIN',
                '1.2.0',
                EntryStatus::Enabled,
                UpdateStatus::Available,
                '1.3.0',
                UpdateServerStatus::Registered,
                'CB User Online Status Package',
                'Shows who is online.'
            );

            $xpath = self::dom(self::render([$row]));

            self::assertSame([
                'CB User Online Status',
                'Plugin',
                '1.2.0',
                'Enabled',
                '1.3.0 available',
                'Registered',
                'CB User Online Status Package',
                'Shows who is online.',
                'alexyarmoshko/joomla_pkg_cbuseronlinestatus',
            ], self::texts($xpath, '//tbody/tr[1]/*'));
        }

        public function testTheRepositoryLinksToTheFullUrlAndShowsItWithoutTheGithubPrefix(): void
        {
            $xpath = self::dom(self::render([self::plugin()]));
            $link  = $xpath->query('//tbody/tr[1]/td[last()]/a')->item(0);

            self::assertNotNull($link);
            self::assertSame('https://github.com/alexyarmoshko/joomla_plg_kunena_stickycollapse', $link->getAttribute('href'));
            self::assertSame('alexyarmoshko/joomla_plg_kunena_stickycollapse', \trim($link->textContent));
            self::assertSame('noopener noreferrer', $link->getAttribute('rel'));
        }

        /**
         * The layout links each Name to the URL PluginLink gives, and a Not installed
         * row to nothing.
         */
        public function testEachNameLinksToItsOwnScreenAndNotInstalledHasNoLink(): void
        {
            $rows = [
                self::plugin(),
                self::row(new RegistryEntry('module', 'mod_ystides', '', 0, '', 'https://github.com/o/m'), 348, EntryStatus::Enabled),
                self::row(new RegistryEntry('template', 'et_tennis', '', 0, '', 'https://github.com/o/t'), 250, EntryStatus::Enabled),
                self::row(new RegistryEntry('template', 'et_tennis', '', 1, '', 'https://github.com/o/t'), 251, EntryStatus::Disabled),
                self::row(new RegistryEntry('component', 'com_ysinventory', '', 1, '', 'https://github.com/o/c'), 389, EntryStatus::ManifestMissing),
                self::row(new RegistryEntry(RegistryEntry::KIND_CBPLUGIN, 'cbbeforeregverify', 'plug_x', 0, 'user', 'https://github.com/o/cb'), 12, EntryStatus::Enabled),
                self::row(new RegistryEntry('module', 'mod_gone', '', 0, '', 'https://github.com/o/g'), null, EntryStatus::NotInstalled),
            ];

            $xpath = self::dom(self::render($rows));
            $hrefs = [];

            foreach ($xpath->query('//tbody/tr/th') as $cell) {
                $link    = $xpath->query('a', $cell)->item(0);
                $hrefs[] = $link?->getAttribute('href');
            }

            self::assertSame([
                'index.php?option=com_plugins&task=plugin.edit&extension_id=402',
                'index.php?option=com_modules&view=modules&client_id=0&filter%5Bmodule%5D=mod_ystides',
                'index.php?option=com_templates&view=styles&client_id=0&filter%5Btemplate%5D=et_tennis',
                'index.php?option=com_installer&view=manage&filter%5Bsearch%5D=id%3A251',
                'index.php?option=com_installer&view=manage&filter%5Bsearch%5D=id%3A389',
                'index.php?option=com_comprofiler&view=editrow&table=pluginsbrowser&action=editrow&cid=12',
                null,
            ], $hrefs);
            self::assertSame('mod_gone (site)', \trim($xpath->query('//tbody/tr[7]/th')->item(0)->textContent));
            self::assertSame(
                ['Enabled', 'Enabled', 'Enabled', 'Disabled', 'Manifest missing', 'Enabled', 'Not installed'],
                self::texts($xpath, '//tbody/tr/td[3]/span[contains(@class, "badge")]')
            );
            self::assertSame('—', self::texts($xpath, '//tbody/tr[7]/td[4]')[0]);
        }

        public function testEveryValueIsEscaped(): void
        {
            $row = new RegistryRow(
                new RegistryEntry('plugin', 'x', 'system', 0, '', 'https://github.com/o/r"><b>repo</b>'),
                1,
                '<b>name</b>',
                'COM_ECSKCCUSTOM_PLUGINS_TYPE_PLUGIN',
                '<b>1</b>',
                EntryStatus::Enabled,
                UpdateStatus::Recorded,
                '<b>2</b>',
                UpdateServerStatus::None,
                '<b>package</b>',
                '<p>description</p>'
            );

            $html  = self::render([$row]);
            $xpath = self::dom($html);

            self::assertSame(0, $xpath->query('//tbody//b | //tbody//p')->length, $html);
            self::assertSame('<b>name</b>', self::texts($xpath, '//tbody/tr/th')[0]);
            self::assertSame('<b>2</b> recorded', self::texts($xpath, '//tbody/tr/td[4]')[0]);
            self::assertSame('<p>description</p>', self::texts($xpath, '//tbody/tr/td[7]')[0]);
        }

        /**
         * A language override is text too: markup in a translated label is shown, not run.
         */
        public function testTranslatedLabelsAreEscaped(): void
        {
            Text::$strings['COM_ECSKCCUSTOM_PLUGINS_HEADING_UPDATE_SERVER'] = '<i>Server</i>';
            Text::$strings['COM_ECSKCCUSTOM_PLUGINS_FAULT_HEADING']          = '<i>Fault</i>';
            Text::$strings['COM_ECSKCCUSTOM_PLUGINS_NO_MATCH']               = '<i>None</i>';

            $list  = self::dom(self::render([self::plugin()]));
            $fault = self::dom(self::render([], ['x']));
            $empty = self::dom(self::render([], [], 'name', 'ASC', 5));

            foreach ([$list, $fault, $empty] as $xpath) {
                self::assertSame(0, $xpath->query('//i')->length);
            }

            self::assertContains('<i>Server</i>', self::texts($list, '//thead//th'));
            self::assertSame(['<i>Fault</i>'], self::texts($fault, '//h2'));
            self::assertSame(['<i>None</i>'], self::texts($empty, '//*[@id="ecskc-no-plugins"]'));
        }

        public function testAPageLevelFaultShowsTheFaultAndNoTable(): void
        {
            $html  = self::render([], ['/site/registry.xml: the registry file does not exist', 'a <b>read</b> failed']);
            $xpath = self::dom($html);

            self::assertSame(0, $xpath->query('//table')->length);
            self::assertSame(0, $xpath->query('//*[@id="stub-joomla-searchtools-default"]')->length);
            self::assertSame(0, $xpath->query('//*[@id="stub-footer"]')->length);
            self::assertSame(['The extension list cannot be shown'], self::texts($xpath, '//*[@id="ecskc-plugins-fault"]//h2'));
            self::assertSame(
                ['/site/registry.xml: the registry file does not exist', 'a <b>read</b> failed'],
                self::texts($xpath, '//*[@id="ecskc-plugins-fault"]//li')
            );
        }

        public function testASearchThatMatchesNothingSaysSo(): void
        {
            $xpath = self::dom(self::render([], [], 'name', 'ASC', 18));

            self::assertSame(0, $xpath->query('//table')->length);
            self::assertSame(['No extension name contains the search text.'], self::texts($xpath, '//*[@id="ecskc-no-plugins"]'));
            self::assertSame(1, $xpath->query('//*[@id="stub-joomla-searchtools-default"]')->length);
        }

        public function testAnEmptyRegistrySaysSo(): void
        {
            $xpath = self::dom(self::render([], [], 'name', 'ASC', 0));

            self::assertSame(['The registry lists no extensions.'], self::texts($xpath, '//*[@id="ecskc-no-plugins"]'));
        }

        public function testTheFormPostsBackToThePluginsViewWithNoTask(): void
        {
            $xpath = self::dom(self::render([self::plugin()]));
            $form  = $xpath->query('//form[@id="adminForm"]')->item(0);

            self::assertNotNull($form);
            self::assertSame('index.php?option=com_ecskccustom&view=plugins', $form->getAttribute('action'));
            self::assertSame('', $xpath->query('//input[@name="task"]')->item(0)?->getAttribute('value'));
        }

        private static function plugin(): RegistryRow
        {
            return self::row(
                new RegistryEntry('plugin', 'kunenastickycollapse', 'system', 0, '', 'https://github.com/alexyarmoshko/joomla_plg_kunena_stickycollapse'),
                402,
                EntryStatus::Enabled
            );
        }

        private static function row(RegistryEntry $entry, ?int $matchedId, EntryStatus $status): RegistryRow
        {
            $installed = $matchedId !== null;
            $identity  = $entry->folder !== '' ? $entry->folder : ($entry->clientId === 1 ? 'administrator' : 'site');

            return new RegistryRow(
                $entry,
                $matchedId,
                $installed ? 'Name of ' . $entry->element : $entry->element . ' (' . $identity . ')',
                'COM_ECSKCCUSTOM_PLUGINS_TYPE_' . \strtoupper($entry->kind),
                $installed ? '1.0.0' : '',
                $status,
                $installed ? UpdateStatus::NoneRecorded : UpdateStatus::NotApplicable,
                null,
                $installed ? UpdateServerStatus::None : UpdateServerStatus::NotApplicable,
                '',
                ''
            );
        }

        /**
         * @param list<RegistryRow> $items
         * @param list<string>      $faults
         */
        private static function render(
            array $items,
            array $faults = [],
            string $listOrder = 'name',
            string $listDirn = 'ASC',
            ?int $registered = null,
        ): string {
            $view = new class ($items, $faults, $listOrder, $listDirn, $registered ?? \count($items)) {
                public ?object $pagination;

                /**
                 * @param list<RegistryRow> $items
                 * @param list<string>      $faults
                 */
                public function __construct(
                    public array $items,
                    public array $faults,
                    public string $listOrder,
                    public string $listDirn,
                    public int $registered,
                ) {
                    $this->pagination = $faults === [] ? new class () {
                        public function getListFooter(): string
                        {
                            return '<div id="stub-footer">FOOTER</div>';
                        }
                    } : null;
                }

                public function escape($var): string
                {
                    return $var === null ? '' : \htmlspecialchars((string) $var, ENT_QUOTES, 'UTF-8');
                }

                public function nameUrl(RegistryRow $row): ?string
                {
                    $url = PluginLink::url($row);

                    return $url === null ? null : Route::_($url);
                }

                public function loadTemplate(string $name): string
                {
                    return PluginsLayoutTest::include('default_' . $name . '.php', $this);
                }
            };

            return self::include('default.php', $view);
        }

        /**
         * Runs one layout file with `$this` bound to the view, as Joomla's loadTemplate does.
         */
        public static function include(string $name, object $view): string
        {
            $file = \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/tmpl/plugins/' . $name;

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
