<?php

declare(strict_types=1);

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Registry {

    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\CommunityBuilderPluginRow;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\CommunityBuilderXmlManifest;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryEntry;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryReadFailure;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Registry\JoomlaCommunityBuilderReader;
    use YakShaver\ECSKCCustomisation\Tests\Support\RecordingDriver;
    use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

    #[CoversClass(JoomlaCommunityBuilderReader::class)]
    final class JoomlaCommunityBuilderReaderTest extends TestCase
    {
        private const FOLDER = 'plug_yakshavercbbeforeregistrationemailverify';

        private const MANIFEST = 'components/com_comprofiler/plugin/user/' . self::FOLDER . '/cbbeforeregverify.xml';

        private TempTree $site;

        protected function setUp(): void
        {
            $this->site = TempTree::create('ecskc-cb-');
        }

        protected function tearDown(): void
        {
            $this->site->remove();
        }

        private static function entry(string $element = 'cbbeforeregverify', string $folder = self::FOLDER): RegistryEntry
        {
            return new RegistryEntry('cbplugin', $element, $folder, 0, 'user', 'https://github.com/alexyarmoshko/x');
        }

        /**
         * @param array<string, mixed> $overrides
         */
        private static function row(array $overrides = []): object
        {
            return (object) \array_merge([
                'id'             => '581',
                'type'           => 'user',
                'folder'         => self::FOLDER,
                'element'        => 'cbbeforeregverify',
                'published'      => '1',
                'manifest_cache' => '{"release":"0.1.0"}',
                'name'           => 'CB Before Registration',
            ], $overrides);
        }

        /**
         * @return list<CommunityBuilderPluginRow>
         */
        private function read(object ...$rows): array
        {
            return (new JoomlaCommunityBuilderReader(new RecordingDriver([$rows]), $this->site->root()))
                ->plugins([self::entry()]);
        }

        public function testTheQueryAsksForTheEntriesElementsOnceEachThroughBoundValues(): void
        {
            $driver = new RecordingDriver([[]]);

            (new JoomlaCommunityBuilderReader($driver, $this->site->root()))->plugins([
                self::entry(),
                self::entry('cbbeforeregverify', 'another_folder'),
                self::entry('cbgjeventmanager', 'plug_yakshavercbgjeventmanager'),
            ]);

            self::assertSame(
                [
                    ['select', ['`id`', '`type`', '`folder`', '`element`', '`published`', '`manifest_cache`', '`name`']],
                    ['from', '`#__comprofiler_plugin`'],
                    ['where', '`element` IN (:preparedArray1,:preparedArray2)'],
                    ['order', '`id`'],
                ],
                $driver->queries[0]->calls
            );
            self::assertSame(
                [':preparedArray1' => ['cbbeforeregverify', 'string'], ':preparedArray2' => ['cbgjeventmanager', 'string']],
                $driver->queries[0]->bound
            );
        }

        public function testNoEntriesAsksNothing(): void
        {
            $driver = new RecordingDriver();

            self::assertSame([], (new JoomlaCommunityBuilderReader($driver, $this->site->root()))->plugins([]));
            self::assertSame([], $driver->queries);
        }

        public function testTheXmlIsReadEvenWhenTheCacheHasARelease(): void
        {
            $this->site->write(self::MANIFEST, <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <cbinstall version="1.0.0" type="plugin" group="user">
                    <name>Yak Shaver CB Before Registration Email Verify</name>
                    <description>Pre-registration email verification.</description>
                    <version>2.11.0</version>
                    <release>0.1.0</release>
                </cbinstall>
                XML);

            self::assertEquals(
                [new CommunityBuilderPluginRow(
                    581,
                    'user',
                    self::FOLDER,
                    'cbbeforeregverify',
                    1,
                    '{"release":"0.1.0"}',
                    'CB Before Registration',
                    new CommunityBuilderXmlManifest(
                        'Yak Shaver CB Before Registration Email Verify',
                        '0.1.0',
                        'Pre-registration email verification.'
                    ),
                )],
                $this->read(self::row())
            );
        }

        public function testAnAbsentElementInTheXmlIsEmpty(): void
        {
            $this->site->write(self::MANIFEST, '<cbinstall><name>Only a name</name></cbinstall>');

            [$row] = $this->read(self::row());

            self::assertEquals(new CommunityBuilderXmlManifest('Only a name', '', ''), $row->xmlManifest);
        }

        /**
         * @return iterable<string, array{?string}>
         */
        public static function unusableFiles(): iterable
        {
            yield 'absent' => [null];
            yield 'empty' => [''];
            yield 'malformed' => ['<cbinstall><name>x</cbinstall'];
            yield 'not a CB manifest' => ['<extension><name>x</name><release>1</release></extension>'];
        }

        #[DataProvider('unusableFiles')]
        public function testAnUnusableFileIsNullNotAFailure(?string $xml): void
        {
            if ($xml !== null) {
                $this->site->write(self::MANIFEST, $xml);
            }

            [$row] = $this->read(self::row());

            self::assertNull($row->xmlManifest);
            self::assertSame([], \libxml_get_errors());
        }

        public function testAPathPartThatWouldLeaveItsDirectoryReadsNoFile(): void
        {
            $this->site->write('components/com_comprofiler/plugin/cbbeforeregverify.xml', '<cbinstall><release>9</release></cbinstall>');

            [$row] = $this->read(self::row(['type' => '..', 'folder' => '.']));

            self::assertNull($row->xmlManifest);
        }

        public function testAManifestLinkedFromOutsideThePluginTreeReadsNoFile(): void
        {
            $outside = $this->site->write('elsewhere/manifest.xml', '<cbinstall><release>9</release></cbinstall>');
            $this->site->symlink(self::MANIFEST, $outside);

            [$row] = $this->read(self::row());

            self::assertNull($row->xmlManifest);
        }

        public function testAFolderLinkedFromOutsideThePluginTreeReadsNoFile(): void
        {
            $this->site->write('elsewhere/plugin/cbbeforeregverify.xml', '<cbinstall><release>9</release></cbinstall>');
            $this->site->symlink('components/com_comprofiler/plugin/user/' . self::FOLDER, $this->site->path('elsewhere/plugin'));

            [$row] = $this->read(self::row());

            self::assertNull($row->xmlManifest);
        }

        public function testALinkThatStaysInsideThePluginTreeIsRead(): void
        {
            $inside = $this->site->write('components/com_comprofiler/plugin/user/shared/manifest.xml', '<cbinstall><release>0.1.0</release></cbinstall>');
            $this->site->symlink(self::MANIFEST, $inside);

            [$row] = $this->read(self::row());

            self::assertEquals(new CommunityBuilderXmlManifest('', '0.1.0', ''), $row->xmlManifest);
        }

        public function testNullTypeFolderAndCacheReadAsEmpty(): void
        {
            [$row] = $this->read(self::row(['type' => null, 'folder' => null, 'manifest_cache' => null]));

            self::assertSame(['', '', ''], [$row->type, $row->folder, $row->manifestCache]);
            self::assertNull($row->xmlManifest);
        }

        public function testANonIntegerPublishedIsAReadFailure(): void
        {
            $this->expectException(RegistryReadFailure::class);
            $this->expectExceptionMessage("#__comprofiler_plugin.published: the database returned 'yes' where an integer is expected");

            $this->read(self::row(['published' => 'yes']));
        }

        public function testADriverExceptionIsAReadFailure(): void
        {
            $driver = new RecordingDriver([new \RuntimeException("Table 'db.jos_comprofiler_plugin' doesn't exist")]);

            $this->expectException(RegistryReadFailure::class);
            $this->expectExceptionMessage("#__comprofiler_plugin could not be read: Table 'db.jos_comprofiler_plugin' doesn't exist");

            (new JoomlaCommunityBuilderReader($driver, $this->site->root()))->plugins([self::entry()]);
        }
    }
}
