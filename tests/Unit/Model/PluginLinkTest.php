<?php

declare(strict_types=1);

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Model {

    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\EntryStatus;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryEntry;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryRow;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\UpdateServerStatus;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\UpdateStatus;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Model\PluginLink;

    /**
     * Each Name link form of work item 10. Ticket 92 opened each one on DDEV.
     */
    #[CoversClass(PluginLink::class)]
    final class PluginLinkTest extends TestCase
    {
        /**
         * @return iterable<string, array{RegistryEntry, EntryStatus, string}>
         */
        public static function links(): iterable
        {
            yield 'plugin: its edit form' => [
                self::entry('plugin', 'kunenastickycollapse', 'system'),
                EntryStatus::Enabled,
                'index.php?option=com_plugins&task=plugin.edit&extension_id=42',
            ];
            yield 'plugin with no manifest: still its edit form' => [
                self::entry('plugin', 'kunenastickycollapse', 'system'),
                EntryStatus::ManifestMissing,
                'index.php?option=com_plugins&task=plugin.edit&extension_id=42',
            ];
            yield 'site module: the site module list filtered to it' => [
                self::entry('module', 'mod_ystides', '', 0),
                EntryStatus::Disabled,
                'index.php?option=com_modules&view=modules&client_id=0&filter%5Bmodule%5D=mod_ystides',
            ];
            yield 'administrator module: the administrator list' => [
                self::entry('module', 'mod_custom_admin', '', 1),
                EntryStatus::Enabled,
                'index.php?option=com_modules&view=modules&client_id=1&filter%5Bmodule%5D=mod_custom_admin',
            ];
            yield 'enabled template: its styles' => [
                self::entry('template', 'cassiopeia', '', 0),
                EntryStatus::Enabled,
                'index.php?option=com_templates&view=styles&client_id=0&filter%5Btemplate%5D=cassiopeia',
            ];
            yield 'disabled template: Extensions: Manage' => [
                self::entry('template', 'cassiopeia', '', 0),
                EntryStatus::Disabled,
                'index.php?option=com_installer&view=manage&filter%5Bsearch%5D=id%3A42',
            ];
            yield 'template with no manifest: Extensions: Manage' => [
                self::entry('template', 'cassiopeia', '', 1),
                EntryStatus::ManifestMissing,
                'index.php?option=com_installer&view=manage&filter%5Bsearch%5D=id%3A42',
            ];

            foreach (['component', 'package', 'language', 'library', 'file'] as $kind) {
                yield $kind . ': Extensions: Manage' => [
                    self::entry($kind, 'x_' . $kind, '', $kind === 'component' ? 1 : 0),
                    EntryStatus::Enabled,
                    'index.php?option=com_installer&view=manage&filter%5Bsearch%5D=id%3A42',
                ];
            }

            yield 'CB plugin: its CB editor' => [
                new RegistryEntry(RegistryEntry::KIND_CBPLUGIN, 'cbgjeventmanager', 'plug_yakshavercbgjeventmanager', 0, 'user', 'https://github.com/o/r'),
                EntryStatus::Disabled,
                'index.php?option=com_comprofiler&view=editrow&table=pluginsbrowser&action=editrow&cid=42',
            ];
        }

        #[DataProvider('links')]
        public function testAMatchedRowLinksToItsOwnScreen(RegistryEntry $entry, EntryStatus $status, string $url): void
        {
            self::assertSame($url, PluginLink::url(self::row($entry, 42, $status)));
        }

        public function testANotInstalledRowHasNoLink(): void
        {
            foreach (self::links() as [$entry]) {
                self::assertNull(PluginLink::url(self::row($entry, null, EntryStatus::NotInstalled)));
            }
        }

        /**
         * The element is the registry's text; the query string carries it encoded.
         */
        public function testTheElementIsEncodedInTheQuery(): void
        {
            $url = (string) PluginLink::url(self::row(self::entry('module', 'mod a&b=c', '', 0), 7, EntryStatus::Enabled));

            \parse_str((string) \parse_url($url, \PHP_URL_QUERY), $query);

            self::assertSame(['module' => 'mod a&b=c'], $query['filter']);
            self::assertSame('com_modules', $query['option']);
        }

        private static function entry(string $kind, string $element, string $folder, int $clientId = 0): RegistryEntry
        {
            return new RegistryEntry($kind, $element, $folder, $clientId, '', 'https://github.com/o/r');
        }

        private static function row(RegistryEntry $entry, ?int $matchedId, EntryStatus $status): RegistryRow
        {
            return new RegistryRow(
                $entry,
                $matchedId,
                'Name',
                'COM_ECSKCCUSTOM_PLUGINS_TYPE_' . \strtoupper($entry->kind),
                '',
                $status,
                UpdateStatus::NoneRecorded,
                null,
                UpdateServerStatus::None,
                '',
                ''
            );
        }
    }
}
