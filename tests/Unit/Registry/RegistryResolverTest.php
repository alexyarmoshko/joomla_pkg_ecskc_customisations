<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\CommunityBuilderPluginRow;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\CommunityBuilderReaderInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\CommunityBuilderXmlManifest;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\EntryStatus;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\ExtensionReaderInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\ExtensionRow;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryEntry;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryLoader;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryLoadResult;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryReadFailure;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryResolution;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryResolver;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryRow;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\UpdateRow;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\UpdateServerStatus;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\UpdateSiteLink;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\UpdateStatus;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\SchemaValidator;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

#[CoversClass(RegistryResolver::class)]
#[CoversClass(RegistryResolution::class)]
#[CoversClass(RegistryRow::class)]
#[CoversClass(EntryStatus::class)]
#[CoversClass(UpdateStatus::class)]
#[CoversClass(UpdateServerStatus::class)]
final class RegistryResolverTest extends TestCase
{
    private const REPO = 'https://github.com/alexyarmoshko/joomla_mod_ystides';

    /** The Community Builder component row, installed. */
    private const CB_ID = 900;

    /** @var list<string> Reader methods called, in order. */
    private array $calls = [];

    // ---------------------------------------------------------------- fixtures

    private static function entry(
        string $kind,
        string $element,
        string $folder = '',
        int $clientId = 0,
        string $cbType = '',
    ): RegistryEntry {
        return new RegistryEntry($kind, $element, $folder, $clientId, $cbType, self::REPO);
    }

    private static function manifest(string $version, string $name = 'Manifest name'): string
    {
        return (string) \json_encode(['name' => $name, 'version' => $version]);
    }

    private static function row(
        int $id,
        string $type,
        string $element,
        string $folder = '',
        int $clientId = 0,
        int $state = 0,
        int $enabled = 1,
        int $packageId = 0,
        string $manifestCache = '',
        string $translatedName = '',
        ?string $manifestName = null,
        ?string $manifestDescription = null,
    ): ExtensionRow {
        return new ExtensionRow(
            $id,
            $type,
            $element,
            $folder,
            $clientId,
            $state,
            $enabled,
            $packageId,
            $manifestCache,
            $translatedName,
            $manifestName,
            $manifestDescription
        );
    }

    private static function communityBuilder(int $state = 0): ExtensionRow
    {
        return self::row(self::CB_ID, 'component', 'com_comprofiler', clientId: 1, state: $state, manifestCache: self::manifest('2.11.0'));
    }

    /**
     * @param list<ExtensionRow>              $extensions
     * @param list<ExtensionRow>              $parents
     * @param list<UpdateRow>                 $updates
     * @param list<UpdateSiteLink>            $sites
     * @param list<CommunityBuilderPluginRow> $plugins
     */
    private function resolver(
        array $extensions = [],
        array $parents = [],
        array $updates = [],
        array $sites = [],
        array $plugins = [],
        string $failing = '',
    ): RegistryResolver {
        $calls = &$this->calls;

        $extensionReader = new class ($extensions, $parents, $updates, $sites, $failing, $calls) implements ExtensionReaderInterface {
            /**
             * @param list<ExtensionRow>   $extensions
             * @param list<ExtensionRow>   $parents
             * @param list<UpdateRow>      $updates
             * @param list<UpdateSiteLink> $sites
             * @param list<string>         $calls
             */
            public function __construct(
                private array $extensions,
                private array $parents,
                private array $updates,
                private array $sites,
                private string $failing,
                private array &$calls,
            ) {
            }

            private function call(string $method): void
            {
                $this->calls[] = $method;

                if ($this->failing === $method) {
                    throw new RegistryReadFailure($method . ': the query failed');
                }
            }

            public function extensions(array $elementsByType): array
            {
                $this->call('extensions');

                return $this->extensions;
            }

            public function extensionsById(array $extensionIds): array
            {
                $this->call('extensionsById');

                return $this->parents;
            }

            public function updates(array $extensionIds): array
            {
                $this->call('updates');

                return $this->updates;
            }

            public function updateSites(array $extensionIds): array
            {
                $this->call('updateSites');

                return $this->sites;
            }
        };

        $communityBuilderReader = new class ($plugins, $failing, $calls) implements CommunityBuilderReaderInterface {
            /**
             * @param list<CommunityBuilderPluginRow> $plugins
             * @param list<string>                    $calls
             */
            public function __construct(private array $plugins, private string $failing, private array &$calls)
            {
            }

            public function plugins(array $entries): array
            {
                $this->calls[] = 'plugins';

                if ($this->failing === 'plugins') {
                    throw new RegistryReadFailure('plugins: the query failed');
                }

                return $this->plugins;
            }
        };

        return new RegistryResolver($extensionReader, $communityBuilderReader);
    }

    /**
     * @param list<RegistryEntry> $entries
     *
     * @return list<RegistryRow>
     */
    private static function rows(RegistryResolver $resolver, array $entries): array
    {
        $resolution = $resolver->resolve(self::loaded($entries));

        self::assertSame([], $resolution->faults);

        return $resolution->rows;
    }

    /**
     * @param list<RegistryEntry> $entries
     */
    private static function loaded(array $entries): RegistryLoadResult
    {
        $tree = TempTree::create('ecskc-registry-');

        try {
            $xml = '';

            foreach ($entries as $entry) {
                $attributes = ['element' => $entry->element, 'repo' => $entry->repo];

                if ($entry->isCommunityBuilder()) {
                    $attributes = ['type' => $entry->cbType, 'folder' => $entry->folder] + $attributes;
                } elseif ($entry->kind === 'plugin') {
                    $attributes = ['folder' => $entry->folder] + $attributes;
                } elseif (\in_array($entry->kind, ['component', 'module', 'template', 'language'], true)) {
                    $attributes['client'] = [0 => 'site', 1 => 'administrator', 3 => 'api'][$entry->clientId];
                }

                $xml .= '<' . $entry->kind;

                foreach ($attributes as $name => $value) {
                    $xml .= ' ' . $name . '="' . \htmlspecialchars($value, \ENT_XML1) . '"';
                }

                $xml .= '/>';
            }

            $path   = $tree->write('registry.xml', '<?xml version="1.0" encoding="UTF-8"?><registry>' . $xml . '</registry>');
            $result = (new RegistryLoader(new SchemaValidator(
                \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/schema/registry.xsd'
            )))->load($path);
        } finally {
            $tree->remove();
        }

        self::assertSame([], $result->violations, 'The fixture registry must itself be valid.');

        return $result;
    }

    /**
     * @return array{UpdateStatus, ?string, UpdateServerStatus}
     */
    private static function updateCells(RegistryRow $row): array
    {
        return [$row->update, $row->updateVersion, $row->updateServer];
    }

    // ---------------------------------------------------------------- statuses and names

    public function testEachJoomlaStatusAndTheNameFallbackChain(): void
    {
        $entries = [
            self::entry('module', 'mod_enabled', clientId: 0),
            self::entry('module', 'mod_disabled', clientId: 0),
            self::entry('plugin', 'nomanifest', 'system'),
            self::entry('plugin', 'bare', 'system'),
            self::entry('module', 'mod_absent', clientId: 1),
            self::entry('package', 'pkg_absent'),
        ];

        $rows = self::rows($this->resolver(extensions: [
            self::row(1, 'module', 'mod_enabled', manifestCache: self::manifest('1.0.0'), translatedName: 'MOD_ENABLED', manifestName: 'Enabled module', manifestDescription: 'Does things'),
            self::row(2, 'module', 'mod_disabled', enabled: 0, manifestCache: self::manifest('1.1.0'), translatedName: 'Column name', manifestName: 'Disabled module'),
            self::row(3, 'plugin', 'nomanifest', 'system', manifestCache: '', translatedName: 'System - No Manifest', manifestName: 'ignored without a manifest', manifestDescription: 'ignored too'),
            self::row(4, 'plugin', 'bare', 'system', manifestCache: 'not json', translatedName: ''),
        ]), $entries);

        self::assertSame(
            [EntryStatus::Enabled, EntryStatus::Disabled, EntryStatus::ManifestMissing, EntryStatus::ManifestMissing, EntryStatus::NotInstalled, EntryStatus::NotInstalled],
            \array_map(static fn (RegistryRow $r): EntryStatus => $r->status, $rows)
        );
        self::assertSame(
            ['Enabled module', 'Disabled module', 'System - No Manifest', 'bare (system)', 'mod_absent (administrator)', 'pkg_absent'],
            \array_map(static fn (RegistryRow $r): string => $r->name, $rows)
        );
        self::assertSame(
            ['1.0.0', '1.1.0', '', '', '', ''],
            \array_map(static fn (RegistryRow $r): string => $r->version, $rows)
        );
        self::assertSame(
            ['Does things', '', '', '', '', ''],
            \array_map(static fn (RegistryRow $r): string => $r->description, $rows)
        );
        self::assertSame([1, 2, 3, 4, null, null], \array_map(static fn (RegistryRow $r): ?int => $r->matchedId, $rows));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unreadableManifests(): array
    {
        return [
            'empty'        => [''],
            'invalid JSON' => ['{"version": '],
            'empty object' => ['{}'],
            'a list'       => ['["1.0.0"]'],
            'a scalar'     => ['"1.0.0"'],
        ];
    }

    #[DataProvider('unreadableManifests')]
    public function testAnUnreadableManifestCacheIsManifestMissingWhateverTheEnabledFlag(string $cache): void
    {
        [$row] = self::rows($this->resolver(extensions: [
            self::row(1, 'package', 'pkg_a', enabled: 0, manifestCache: $cache, translatedName: 'Package A'),
        ]), [self::entry('package', 'pkg_a')]);

        self::assertSame(EntryStatus::ManifestMissing, $row->status);
        self::assertSame('Package A', $row->name);
        self::assertSame('', $row->version);
    }

    public function testANameOfWhitespaceFallsThroughToTheNextInTheChain(): void
    {
        [$row] = self::rows($this->resolver(extensions: [
            self::row(1, 'package', 'pkg_a', manifestCache: self::manifest('1.0.0'), translatedName: '  ', manifestName: ' '),
        ]), [self::entry('package', 'pkg_a')]);

        self::assertSame('pkg_a', $row->name);
    }

    // ---------------------------------------------------------------- matching

    public function testAnEntryWithoutFolderMatchesARowWhoseFolderIsEmpty(): void
    {
        [$row] = self::rows($this->resolver(extensions: [
            self::row(7, 'component', 'com_a', clientId: 1, manifestCache: self::manifest('1.0.0')),
        ]), [self::entry('component', 'com_a', clientId: 1)]);

        self::assertSame(7, $row->matchedId);
        self::assertSame(EntryStatus::Enabled, $row->status);
    }

    public function testOnlyTheRowWithTheWholeIdentityAndStateZeroMatches(): void
    {
        $entries = [
            self::entry('module', 'mod_a', clientId: 0),
            self::entry('plugin', 'a', 'system'),
            self::entry('package', 'pkg_discovered'),
        ];

        $rows = self::rows($this->resolver(extensions: [
            self::row(1, 'module', 'mod_a', clientId: 1, manifestCache: self::manifest('1.0.0')),
            self::row(2, 'plugin', 'a', 'content', manifestCache: self::manifest('1.0.0')),
            self::row(3, 'module', 'mod_a', clientId: 0, manifestCache: self::manifest('2.0.0')),
            self::row(4, 'plugin', 'A', 'System', manifestCache: self::manifest('3.0.0')),
            self::row(5, 'package', 'pkg_discovered', state: -1, manifestCache: self::manifest('1.0.0')),
            self::row(6, 'template', 'a', manifestCache: self::manifest('1.0.0')),
            self::row(8, 'plugin', 'a', 'system', manifestCache: self::manifest('3.0.0')),
        ]), $entries);

        self::assertSame([3, 8, null], \array_map(static fn (RegistryRow $r): ?int => $r->matchedId, $rows));
        self::assertSame(EntryStatus::NotInstalled, $rows[2]->status, 'A discovered row is not an install.');
    }

    /**
     * The schema's uniqueness is case-sensitive, so matching is too: two entries that
     * differ only in case never both claim one row.
     */
    public function testEntriesDifferingOnlyInCaseDoNotBothClaimOneRow(): void
    {
        $rows = self::rows($this->resolver(extensions: [
            self::row(1, 'plugin', 'foo', 'system', manifestCache: self::manifest('1.0.0')),
            self::row(2, 'library', 'CBLib', manifestCache: self::manifest('2.11.0')),
        ]), [
            self::entry('plugin', 'Foo', 'system'),
            self::entry('plugin', 'foo', 'System'),
            self::entry('plugin', 'foo', 'system'),
            self::entry('library', 'CBLib'),
        ]);

        self::assertSame([null, null, 1, 2], \array_map(static fn (RegistryRow $r): ?int => $r->matchedId, $rows));
    }

    public function testTheReaderIsAskedForEveryEntryTypeAndElementAndForCommunityBuilder(): void
    {
        $asked    = null;
        $resolver = new RegistryResolver(
            new class ($asked) implements ExtensionReaderInterface {
                /** @param ?array<string, list<string>> $asked */
                public function __construct(private ?array &$asked)
                {
                }

                public function extensions(array $elementsByType): array
                {
                    $this->asked = $elementsByType;

                    return [];
                }

                public function extensionsById(array $extensionIds): array
                {
                    return [];
                }

                public function updates(array $extensionIds): array
                {
                    return [];
                }

                public function updateSites(array $extensionIds): array
                {
                    return [];
                }
            },
            new class () implements CommunityBuilderReaderInterface {
                public function plugins(array $entries): array
                {
                    return [];
                }
            }
        );

        $resolver->resolve(self::loaded([
            self::entry('plugin', 'a', 'system'),
            self::entry('plugin', 'a', 'installer'),
            self::entry('component', 'com_a', clientId: 1),
            self::entry('cbplugin', 'b', 'plug_b', cbType: 'user'),
        ]));

        self::assertSame(['component' => ['com_comprofiler', 'com_a'], 'plugin' => ['a']], $asked);
    }

    // ---------------------------------------------------------------- update and update server

    public function testAChildReportsItsPackagesUpdateAndServerAndThePackageRowShowsTheSame(): void
    {
        $entries = [
            self::entry('package', 'pkg_a'),
            self::entry('plugin', 'a', 'system'),
            self::entry('module', 'mod_a', clientId: 0),
        ];

        $rows = self::rows($this->resolver(
            extensions: [
                self::row(10, 'package', 'pkg_a', manifestCache: self::manifest('2.0.0'), manifestName: 'Package A'),
                // The child's own version is higher than the update, so a comparison with
                // its own version would call the update old.
                self::row(11, 'plugin', 'a', 'system', packageId: 10, manifestCache: self::manifest('5.0.0')),
                self::row(12, 'module', 'mod_a', packageId: 10, manifestCache: self::manifest('1.0.0')),
            ],
            parents: [
                self::row(10, 'package', 'pkg_a', manifestCache: self::manifest('2.0.0'), manifestName: 'Package A'),
            ],
            updates: [new UpdateRow(10, '2.1.0'), new UpdateRow(10, '2.0.5'), new UpdateRow(11, '9.0.0')],
            sites: [new UpdateSiteLink(10, 1, 'https://example.org/pkg_a.xml', 1)],
        ), $entries);

        foreach ($rows as $row) {
            self::assertSame([UpdateStatus::Available, '2.1.0', UpdateServerStatus::Registered], self::updateCells($row));
        }

        self::assertSame(['', 'Package A', 'Package A'], \array_map(static fn (RegistryRow $r): string => $r->package, $rows));
        self::assertSame('5.0.0', $rows[1]->version, 'The Version cell is still the child\'s own.');
    }

    public function testARegisteredChildOfAnUnregisteredPackageShowsThatPackage(): void
    {
        [$row] = self::rows($this->resolver(
            extensions: [self::row(21, 'plugin', 'a', 'system', packageId: 20, manifestCache: self::manifest('3.5.0'))],
            parents: [self::row(20, 'package', 'pkg_other', manifestCache: self::manifest('3.0.0'), translatedName: 'PKG_OTHER', manifestName: 'Other Package')],
            updates: [new UpdateRow(20, '3.1.0'), new UpdateRow(20, '2.9.0')],
        ), [self::entry('plugin', 'a', 'system')]);

        self::assertSame('Other Package', $row->package);
        self::assertSame([UpdateStatus::Available, '3.1.0', UpdateServerStatus::None], self::updateCells($row));
    }

    public function testAPackageWithoutAManifestNameShowsItsTranslatedNameColumn(): void
    {
        [$row] = self::rows($this->resolver(
            extensions: [self::row(21, 'plugin', 'a', 'system', packageId: 20, manifestCache: self::manifest('1.0.0'))],
            parents: [self::row(20, 'package', 'pkg_other', manifestCache: '', translatedName: 'Other (column)', manifestName: 'not used')],
        ), [self::entry('plugin', 'a', 'system')]);

        self::assertSame('Other (column)', $row->package);
    }

    public function testAnUpdateForAnExtensionWithoutAManifestIsShownAsRecorded(): void
    {
        $rows = self::rows($this->resolver(
            extensions: [
                self::row(30, 'module', 'mod_a', manifestCache: ''),
                self::row(31, 'plugin', 'a', 'system', packageId: 32, manifestCache: self::manifest('1.0.0')),
            ],
            parents: [self::row(32, 'package', 'pkg_b', manifestCache: 'broken')],
            updates: [new UpdateRow(30, '3.9.0'), new UpdateRow(30, '4.0.0'), new UpdateRow(30, '3.10.0'), new UpdateRow(32, '1.2.0')],
        ), [self::entry('module', 'mod_a', clientId: 0), self::entry('plugin', 'a', 'system')]);

        self::assertSame([UpdateStatus::Recorded, '4.0.0', UpdateServerStatus::None], self::updateCells($rows[0]));
        self::assertSame(
            [UpdateStatus::Recorded, '1.2.0', UpdateServerStatus::None],
            self::updateCells($rows[1]),
            'The package\'s manifest is the one whose version is unknown.'
        );
    }

    public function testAStoredVersionNotAboveTheInstalledOneIsNoneRecorded(): void
    {
        [$row] = self::rows($this->resolver(
            extensions: [self::row(1, 'module', 'mod_a', manifestCache: self::manifest('2.0.0'))],
            updates: [new UpdateRow(1, '2.0.0'), new UpdateRow(1, '1.9.9')],
        ), [self::entry('module', 'mod_a', clientId: 0)]);

        self::assertSame([UpdateStatus::NoneRecorded, null, UpdateServerStatus::None], self::updateCells($row));
    }

    public function testVersionsCompareWithVersionCompareNotAsText(): void
    {
        [$row] = self::rows($this->resolver(
            extensions: [self::row(1, 'module', 'mod_a', manifestCache: self::manifest('0.9.0'))],
            updates: [new UpdateRow(1, '0.10.0'), new UpdateRow(1, '0.2.0')],
        ), [self::entry('module', 'mod_a', clientId: 0)]);

        self::assertSame([UpdateStatus::Available, '0.10.0', UpdateServerStatus::None], self::updateCells($row));
    }

    public function testTwoExtensionsOnOneUpdateSiteWithAnUpdateRecordedForOnlyOne(): void
    {
        $rows = self::rows($this->resolver(
            extensions: [
                self::row(40, 'module', 'mod_ystides', manifestCache: self::manifest('1.0.0')),
                self::row(41, 'module', 'mod_ystipoftheday', manifestCache: self::manifest('1.0.0')),
            ],
            updates: [new UpdateRow(40, '1.1.0')],
            sites: [new UpdateSiteLink(40, 5, 'https://example.org/tides.xml', 1), new UpdateSiteLink(41, 5, 'https://example.org/tides.xml', 1)],
        ), [self::entry('module', 'mod_ystides', clientId: 0), self::entry('module', 'mod_ystipoftheday', clientId: 0)]);

        self::assertSame([UpdateStatus::Available, '1.1.0', UpdateServerStatus::Registered], self::updateCells($rows[0]));
        self::assertSame([UpdateStatus::NoneRecorded, null, UpdateServerStatus::Registered], self::updateCells($rows[1]));
    }

    public function testAnUpdateRowForExtensionIdZeroMatchesNothing(): void
    {
        $rows = self::rows($this->resolver(
            extensions: [self::row(1, 'module', 'mod_a', manifestCache: '')],
            updates: [new UpdateRow(0, '9.9.9'), new UpdateRow(1, '')],
        ), [self::entry('module', 'mod_a', clientId: 0)]);

        self::assertSame([UpdateStatus::NoneRecorded, null, UpdateServerStatus::None], self::updateCells($rows[0]));
    }

    public function testAnUpdateSiteWithoutALocationCountsAsNone(): void
    {
        $rows = self::rows($this->resolver(
            extensions: [
                self::row(1, 'module', 'mod_a', manifestCache: self::manifest('1.0.0')),
                self::row(2, 'module', 'mod_b', manifestCache: self::manifest('1.0.0')),
                self::row(3, 'module', 'mod_c', manifestCache: self::manifest('1.0.0')),
            ],
            sites: [
                new UpdateSiteLink(1, 7, '', 1),
                new UpdateSiteLink(1, 8, '  ', 1),
                new UpdateSiteLink(2, 9, 'https://example.org/b.xml', 0),
                new UpdateSiteLink(2, 10, 'https://example.org/b2.xml', 0),
                new UpdateSiteLink(3, 11, 'https://example.org/c.xml', 0),
                new UpdateSiteLink(3, 12, '', 1),
            ],
        ), [
            self::entry('module', 'mod_a', clientId: 0),
            self::entry('module', 'mod_b', clientId: 0),
            self::entry('module', 'mod_c', clientId: 0),
        ]);

        self::assertSame(
            [UpdateServerStatus::None, UpdateServerStatus::Disabled, UpdateServerStatus::Disabled],
            \array_map(static fn (RegistryRow $r): UpdateServerStatus => $r->updateServer, $rows)
        );
    }

    public function testANotInstalledRowShowsADashInBothUpdateCells(): void
    {
        [$row] = self::rows($this->resolver(), [self::entry('module', 'mod_a', clientId: 0)]);

        self::assertSame([UpdateStatus::NotApplicable, null, UpdateServerStatus::NotApplicable], self::updateCells($row));
        self::assertSame('', $row->package);
    }

    // ---------------------------------------------------------------- Community Builder

    public function testWithoutCommunityBuilderEveryCbEntryIsNotInstalledAndNoCbTableIsRead(): void
    {
        $entry = self::entry('cbplugin', 'cbbeforeregverify', 'plug_verify', cbType: 'user');

        foreach ([[], [self::communityBuilder(-1)]] as $extensions) {
            $this->calls = [];
            [$row]       = self::rows($this->resolver(extensions: $extensions), [$entry]);

            self::assertSame(EntryStatus::NotInstalled, $row->status);
            self::assertSame('cbbeforeregverify (user, plug_verify)', $row->name);
            self::assertSame([UpdateStatus::NotApplicable, null, UpdateServerStatus::NotApplicable], self::updateCells($row));
            self::assertNotContains('plugins', $this->calls);
        }
    }

    public function testACommunityBuilderRowWithAUsableManifestCache(): void
    {
        [$row] = self::rows($this->resolver(
            extensions: [self::communityBuilder()],
            plugins: [new CommunityBuilderPluginRow(
                55,
                'user',
                'plug_a',
                'a',
                1,
                (string) \json_encode(['name' => 'CB A', 'release' => '1.4.0', 'version' => '2.11.0', 'description' => 'From the cache']),
                'Column name',
                new CommunityBuilderXmlManifest('XML name', '0.0.1', 'From the XML')
            )],
        ), [self::entry('cbplugin', 'a', 'plug_a', cbType: 'user')]);

        self::assertSame(55, $row->matchedId);
        self::assertSame(EntryStatus::Enabled, $row->status);
        self::assertSame(['CB A', '1.4.0', 'From the cache'], [$row->name, $row->version, $row->description]);
        self::assertSame([UpdateStatus::NotChecked, null, UpdateServerStatus::NotChecked], self::updateCells($row));
        self::assertSame('', $row->package);
    }

    public function testACommunityBuilderRowFallsBackToTheXmlReleaseWhenTheCacheHasNone(): void
    {
        $xml = new CommunityBuilderXmlManifest('Before Registration Verify', '0.1.0', 'Checks the address');

        $rows = self::rows($this->resolver(
            extensions: [self::communityBuilder()],
            plugins: [
                new CommunityBuilderPluginRow(56, 'user', 'plug_a', 'a', 0, '', 'Column', $xml),
                new CommunityBuilderPluginRow(57, 'user', 'plug_b', 'b', 1, (string) \json_encode(['name' => 'Cache', 'version' => '2.11.0']), 'Column', $xml),
            ],
        ), [self::entry('cbplugin', 'a', 'plug_a', cbType: 'user'), self::entry('cbplugin', 'b', 'plug_b', cbType: 'user')]);

        self::assertSame(EntryStatus::Disabled, $rows[0]->status);
        self::assertSame(EntryStatus::Enabled, $rows[1]->status);

        foreach ($rows as $row) {
            self::assertSame(
                ['Before Registration Verify', '0.1.0', 'Checks the address'],
                [$row->name, $row->version, $row->description],
                'All three come from the XML, and the version is <release>, not the cache\'s version.'
            );
        }
    }

    public function testACommunityBuilderRowWithNeitherManifestIsManifestMissing(): void
    {
        $rows = self::rows($this->resolver(
            extensions: [self::communityBuilder()],
            plugins: [
                new CommunityBuilderPluginRow(58, 'user', 'plug_a', 'a', 1, '', 'CB column name', null),
                new CommunityBuilderPluginRow(59, 'user', 'plug_b', 'b', 1, 'not json', '', null),
            ],
        ), [self::entry('cbplugin', 'a', 'plug_a', cbType: 'user'), self::entry('cbplugin', 'b', 'plug_b', cbType: 'user')]);

        self::assertSame([EntryStatus::ManifestMissing, EntryStatus::ManifestMissing], [$rows[0]->status, $rows[1]->status]);
        self::assertSame(['CB column name', 'b (user, plug_b)'], [$rows[0]->name, $rows[1]->name]);
        self::assertSame(['', ''], [$rows[0]->version, $rows[1]->description]);
    }

    public function testACommunityBuilderEntryMatchesOnTypeFolderAndElement(): void
    {
        $rows = self::rows($this->resolver(
            extensions: [self::communityBuilder()],
            plugins: [
                new CommunityBuilderPluginRow(60, 'content', 'plug_a', 'a', 1, '', 'wrong type', null),
                new CommunityBuilderPluginRow(61, 'user', 'plug_other', 'a', 1, '', 'wrong folder', null),
                new CommunityBuilderPluginRow(62, 'cbplugin', 'plug_a', 'a', 1, '', 'the kind is not the type', null),
                new CommunityBuilderPluginRow(63, 'user', 'plug_a', 'A', 1, '', 'wrong case', null),
            ],
        ), [self::entry('cbplugin', 'a', 'plug_a', cbType: 'user')]);

        self::assertSame(EntryStatus::NotInstalled, $rows[0]->status);
    }

    // ---------------------------------------------------------------- page-level faults

    /**
     * @return array<string, array{string}>
     */
    public static function readers(): array
    {
        return [
            'extensions'     => ['extensions'],
            'extensionsById' => ['extensionsById'],
            'updates'        => ['updates'],
            'updateSites'    => ['updateSites'],
            'plugins'        => ['plugins'],
        ];
    }

    #[DataProvider('readers')]
    public function testAReadFailureFromAnyReaderIsAPageFaultWithNoRows(string $failing): void
    {
        $resolution = $this->resolver(
            extensions: [self::communityBuilder(), self::row(1, 'plugin', 'a', 'system', packageId: 2, manifestCache: self::manifest('1.0.0'))],
            parents: [self::row(2, 'package', 'pkg_a', manifestCache: self::manifest('1.0.0'))],
            failing: $failing,
        )->resolve(self::loaded([
            self::entry('plugin', 'a', 'system'),
            self::entry('cbplugin', 'b', 'plug_b', cbType: 'user'),
        ]));

        self::assertContains($failing, $this->calls, 'The fixture must reach the failing reader.');
        self::assertFalse($resolution->isValid());
        self::assertSame([], $resolution->rows);
        self::assertSame([$failing . ': the query failed'], $resolution->faults);
    }

    public function testARegistryLoadFaultIsAPageFaultAndNothingIsRead(): void
    {
        $resolution = $this->resolver()->resolve(RegistryLoadResult::fault(['registry.xml: broken (line 1)']));

        self::assertFalse($resolution->isValid());
        self::assertSame([], $resolution->rows);
        self::assertSame(['registry.xml: broken (line 1)'], $resolution->faults);
        self::assertSame([], $this->calls);
    }

    // ---------------------------------------------------------------- cells as keys

    public function testTypeRepositoryAndLanguageKeysOfEveryKind(): void
    {
        $entries = [
            self::entry('component', 'com_a', clientId: 1),
            self::entry('module', 'mod_a', clientId: 0),
            self::entry('template', 'tpl_a', clientId: 0),
            self::entry('language', 'en-GB', clientId: 3),
            self::entry('plugin', 'a', 'system'),
            self::entry('package', 'pkg_a'),
            self::entry('library', 'lib_a'),
            self::entry('file', 'file_a'),
            self::entry('cbplugin', 'a', 'plug_a', cbType: 'user'),
        ];

        $rows = self::rows($this->resolver(), $entries);

        self::assertSame('en-GB (api)', $rows[3]->name);
        self::assertSame(self::REPO, $rows[0]->repositoryUrl());
        self::assertSame('alexyarmoshko/joomla_mod_ystides', $rows[0]->repositoryLabel());

        $strings = \parse_ini_file(
            \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/language/en-GB/com_ecskccustom.ini',
            false,
            \INI_SCANNER_RAW
        );
        self::assertIsArray($strings);

        $keys = \array_map(static fn (RegistryRow $r): string => $r->typeKey, $rows);

        foreach ([EntryStatus::cases(), UpdateStatus::cases(), UpdateServerStatus::cases()] as $cases) {
            foreach ($cases as $case) {
                $keys[] = $case->languageKey();
            }
        }

        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $strings, $key . ' is missing from the language file.');
        }

        self::assertSame('%s available', $strings[UpdateStatus::Available->languageKey()]);
        self::assertSame('%s recorded', $strings[UpdateStatus::Recorded->languageKey()]);
    }
}
