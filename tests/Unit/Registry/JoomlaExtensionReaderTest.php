<?php

declare(strict_types=1);

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Registry {

    use Joomla\CMS\Language\Text;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\ExtensionRow;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryReadFailure;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\UpdateRow;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\UpdateSiteLink;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Registry\DatabaseRead;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Registry\ExtensionLanguageLoader;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Registry\JoomlaExtensionReader;
    use YakShaver\ECSKCCustomisation\Tests\Support\RecordingDriver;

    #[CoversClass(JoomlaExtensionReader::class)]
    #[CoversClass(DatabaseRead::class)]
    final class JoomlaExtensionReaderTest extends TestCase
    {
        /** @var list<array{string, string}> */
        private array $loaded = [];

        protected function tearDown(): void
        {
            Text::$strings = [];
            Text::$fail    = false;
        }

        private function reader(RecordingDriver $driver): JoomlaExtensionReader
        {
            return new JoomlaExtensionReader($driver, new ExtensionLanguageLoader(
                '/site',
                '/admin',
                '/plugins',
                function (string $file, string $path): bool {
                    $this->loaded[] = [$file, $path];

                    return true;
                }
            ));
        }

        /**
         * One `#__extensions` row as the MySQL driver returns it: every value a string.
         *
         * @param array<string, mixed> $overrides
         */
        private static function row(array $overrides = []): object
        {
            return (object) \array_merge([
                'extension_id'   => '231',
                'type'           => 'plugin',
                'element'        => 'ecskccustom',
                'folder'         => 'system',
                'client_id'      => '0',
                'state'          => '0',
                'enabled'        => '1',
                'package_id'     => '230',
                'manifest_cache' => '{"name":"PLG_SYSTEM_ECSKCCUSTOM","version":"0.1.0","description":"PLG_SYSTEM_ECSKCCUSTOM_XML_DESCRIPTION"}',
                'name'           => 'plg_system_ecskccustom',
            ], $overrides);
        }

        public function testExtensionsAsksForEachTypeWithItsOwnElementsThroughBoundValues(): void
        {
            $driver = new RecordingDriver([[]]);

            $this->reader($driver)->extensions([
                'component' => ['com_comprofiler', 'com_ysinventory'],
                'plugin'    => ['ecskccustom'],
            ]);

            $query = $driver->queries[0];

            self::assertSame(
                [
                    ['select', ['`extension_id`', '`type`', '`element`', '`folder`', '`client_id`', '`state`',
                        '`enabled`', '`package_id`', '`manifest_cache`', '`name`']],
                    ['from', '`#__extensions`'],
                    ['order', '`extension_id`'],
                    ['where', '((`type` = :preparedArray1 AND `element` IN (:preparedArray2,:preparedArray3))'
                        . ' OR (`type` = :preparedArray4 AND `element` IN (:preparedArray5)))'],
                ],
                $query->calls
            );
            self::assertSame(
                [
                    ':preparedArray1' => ['component', 'string'],
                    ':preparedArray2' => ['com_comprofiler', 'string'],
                    ':preparedArray3' => ['com_ysinventory', 'string'],
                    ':preparedArray4' => ['plugin', 'string'],
                    ':preparedArray5' => ['ecskccustom', 'string'],
                ],
                $query->bound
            );
        }

        public function testNoElementsAsksNothing(): void
        {
            $driver = new RecordingDriver();

            self::assertSame([], $this->reader($driver)->extensions(['plugin' => []]));
            self::assertSame([], $this->reader($driver)->extensionsById([]));
            self::assertSame([], $this->reader($driver)->updates([]));
            self::assertSame([], $this->reader($driver)->updateSites([]));
            self::assertSame([], $driver->queries);
        }

        public function testARowComesBackWithIntegersConvertedAndTextTranslated(): void
        {
            Text::$strings = [
                'PLG_SYSTEM_ECSKCCUSTOM'                => 'System - ECSKC Customisations',
                'PLG_SYSTEM_ECSKCCUSTOM_XML_DESCRIPTION' => 'Re-applies the customisations.',
                'plg_system_ecskccustom'                => 'System - ECSKC (column)',
            ];

            $rows = $this->reader(new RecordingDriver([[self::row()]]))->extensions(['plugin' => ['ecskccustom']]);

            self::assertEquals(
                [new ExtensionRow(
                    231,
                    'plugin',
                    'ecskccustom',
                    'system',
                    0,
                    0,
                    1,
                    230,
                    self::row()->manifest_cache,
                    'System - ECSKC (column)',
                    'System - ECSKC Customisations',
                    'Re-applies the customisations.',
                )],
                $rows
            );
            self::assertSame([['plg_system_ecskccustom.sys', '/admin']], $this->loaded);
        }

        public function testALanguagePacksDescriptionIsNotTranslated(): void
        {
            Text::$strings = ['English (en-GB)' => 'translated', 'en-GB site language' => 'translated'];

            [$row] = $this->reader(new RecordingDriver([[self::row([
                'type'           => 'language',
                'element'        => 'en-GB',
                'folder'         => '',
                'manifest_cache' => '{"name":"English (en-GB)","description":"en-GB site language"}',
            ])]]))->extensions(['language' => ['en-GB']]);

            self::assertSame('translated', $row->manifestName);
            self::assertSame('en-GB site language', $row->manifestDescription);
        }

        public function testAManifestWithoutANameOrDescriptionGivesNull(): void
        {
            [$row] = $this->reader(new RecordingDriver([[self::row(['manifest_cache' => '{"version":"1.0.0"}'])]]))
                ->extensions(['plugin' => ['ecskccustom']]);

            self::assertNull($row->manifestName);
            self::assertNull($row->manifestDescription);
        }

        public function testAnEmptyOrInvalidManifestCacheGivesNullAndIsPassedOnAsItIs(): void
        {
            $driver = new RecordingDriver([[self::row(['manifest_cache' => '']), self::row(['manifest_cache' => '{not json'])]]);

            [$empty, $invalid] = $this->reader($driver)->extensions(['plugin' => ['ecskccustom']]);

            self::assertSame('', $empty->manifestCache);
            self::assertNull($empty->manifestName);
            self::assertSame('{not json', $invalid->manifestCache);
            self::assertNull($invalid->manifestDescription);
        }

        public function testNativeIntegersFromTheDriverAreAccepted(): void
        {
            [$row] = $this->reader(new RecordingDriver([[self::row([
                'extension_id' => 231,
                'client_id'    => 0,
                'state'        => -1,
                'enabled'      => 0,
                'package_id'   => 0,
            ])]]))->extensions(['plugin' => ['ecskccustom']]);

            self::assertSame([231, 0, -1, 0, 0], [$row->extensionId, $row->clientId, $row->state, $row->enabled, $row->packageId]);
        }

        public function testANullStateIsAReadFailureNotAnInstall(): void
        {
            $this->expectException(RegistryReadFailure::class);
            $this->expectExceptionMessage('#__extensions.state: the database returned null where an integer is expected');

            $this->reader(new RecordingDriver([[self::row(['state' => null])]]))->extensions(['plugin' => ['ecskccustom']]);
        }

        public function testExtensionsByIdAsksForTheIdsAsIntegers(): void
        {
            $driver = new RecordingDriver([[self::row(['extension_id' => '230', 'type' => 'package', 'package_id' => '0'])]]);

            [$row] = $this->reader($driver)->extensionsById([230, 12]);

            self::assertSame(230, $row->extensionId);
            self::assertSame(['where', '`extension_id` IN (:preparedArray1,:preparedArray2)'], $driver->queries[0]->calls[3]);
            self::assertSame(
                [':preparedArray1' => [230, 'int'], ':preparedArray2' => [12, 'int']],
                $driver->queries[0]->bound
            );
        }

        public function testUpdatesReadsTheExtensionAndVersion(): void
        {
            $driver = new RecordingDriver([[
                (object) ['extension_id' => '230', 'version' => '0.2.0'],
                (object) ['extension_id' => '231', 'version' => null],
            ]]);

            $rows = $this->reader($driver)->updates([230, 231]);

            self::assertEquals([new UpdateRow(230, '0.2.0'), new UpdateRow(231, '')], $rows);
            self::assertSame(
                [
                    ['select', ['`extension_id`', '`version`']],
                    ['from', '`#__updates`'],
                    ['where', '`extension_id` IN (:preparedArray1,:preparedArray2)'],
                    ['order', '`update_id`'],
                ],
                $driver->queries[0]->calls
            );
        }

        public function testUpdateSitesJoinsTheLinkTableToTheSites(): void
        {
            $driver = new RecordingDriver([[
                (object) ['extension_id' => '230', 'update_site_id' => '5', 'location' => 'https://example.org/u.xml', 'enabled' => '1'],
            ]]);

            $rows = $this->reader($driver)->updateSites([230]);

            self::assertEquals([new UpdateSiteLink(230, 5, 'https://example.org/u.xml', 1)], $rows);
            self::assertSame(
                [
                    ['select', ['`use`.`extension_id`', '`us`.`update_site_id`', '`us`.`location`', '`us`.`enabled`']],
                    ['from', '`#__update_sites_extensions` AS `use`'],
                    ['join', ['INNER', '`#__update_sites` AS `us`', '`us`.`update_site_id` = `use`.`update_site_id`']],
                    ['where', '`use`.`extension_id` IN (:preparedArray1)'],
                    ['order', ['`use`.`extension_id`', '`us`.`update_site_id`']],
                ],
                $driver->queries[0]->calls
            );
        }

        public function testANullSiteEnabledIsAReadFailure(): void
        {
            $this->expectException(RegistryReadFailure::class);
            $this->expectExceptionMessage('#__update_sites.enabled: the database returned null');

            $this->reader(new RecordingDriver([[
                (object) ['extension_id' => '230', 'update_site_id' => '5', 'location' => 'x', 'enabled' => null],
            ]]))->updateSites([230]);
        }

        public function testADriverExceptionIsAReadFailureNamingTheTable(): void
        {
            // Joomla's ExecutionFailureException extends RuntimeException.
            $driver = new RecordingDriver([new \RuntimeException("Table 'db.jos_updates' doesn't exist")]);

            try {
                $this->reader($driver)->updates([230]);
                self::fail('No read failure');
            } catch (RegistryReadFailure $failure) {
                self::assertSame("#__updates could not be read: Table 'db.jos_updates' doesn't exist", $failure->getMessage());
                self::assertInstanceOf(\RuntimeException::class, $failure->getPrevious());
            }
        }

        public function testADriverExceptionWithNoMessageStillGivesOne(): void
        {
            $this->expectException(RegistryReadFailure::class);
            $this->expectExceptionMessage('#__extensions could not be read: RuntimeException');

            $this->reader(new RecordingDriver([new \RuntimeException('')]))->extensionsById([1]);
        }

        public function testAResultThatIsNotAListIsAReadFailure(): void
        {
            $this->expectException(RegistryReadFailure::class);
            $this->expectExceptionMessage('#__update_sites: the database returned no result set');

            $this->reader(new RecordingDriver([null]))->updateSites([1]);
        }

        public function testAnExceptionWhileLoadingALanguageFileIsAReadFailure(): void
        {
            $reader = new JoomlaExtensionReader(
                new RecordingDriver([[self::row()]]),
                new ExtensionLanguageLoader('/site', '/admin', '/plugins', static function (): bool {
                    throw new \RuntimeException('Language file unreadable');
                })
            );

            $this->expectException(RegistryReadFailure::class);
            $this->expectExceptionMessage('#__extensions could not be read: Language file unreadable');

            $reader->extensions(['plugin' => ['ecskccustom']]);
        }

        public function testALogicExceptionIsABugAndIsNotSwallowed(): void
        {
            $this->expectException(\LogicException::class);

            $this->reader(new RecordingDriver([new \LogicException('a bug')]))->updates([1]);
        }
    }
}
