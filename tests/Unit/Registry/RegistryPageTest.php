<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\CommunityBuilderReaderInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\EntryStatus;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\ExtensionReaderInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryEntry;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryListState;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryPage;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryResolver;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryRow;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\UpdateServerStatus;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\UpdateStatus;

/**
 * Sort, search and pages, work item 11. The rows are built directly: none of this
 * reads anything.
 */
#[CoversClass(RegistryResolver::class)]
#[CoversClass(RegistryListState::class)]
#[CoversClass(RegistryPage::class)]
final class RegistryPageTest extends TestCase
{
    private const REPO = 'https://github.com/alexyarmoshko/joomla_mod_ystides';

    // ---------------------------------------------------------------- fixtures

    private static function resolver(): RegistryResolver
    {
        $extensions = new class () implements ExtensionReaderInterface {
            public function extensions(array $elementsByType): array
            {
                throw new \LogicException('Paging reads nothing.');
            }

            public function extensionsById(array $extensionIds): array
            {
                throw new \LogicException('Paging reads nothing.');
            }

            public function updates(array $extensionIds): array
            {
                throw new \LogicException('Paging reads nothing.');
            }

            public function updateSites(array $extensionIds): array
            {
                throw new \LogicException('Paging reads nothing.');
            }
        };

        $communityBuilder = new class () implements CommunityBuilderReaderInterface {
            public function plugins(array $entries): array
            {
                throw new \LogicException('Paging reads nothing.');
            }
        };

        return new RegistryResolver($extensions, $communityBuilder);
    }

    private static function row(
        string $name,
        string $kind = 'package',
        string $version = '',
        EntryStatus $status = EntryStatus::Enabled,
        UpdateStatus $update = UpdateStatus::NoneRecorded,
        ?string $updateVersion = null,
    ): RegistryRow {
        return new RegistryRow(
            new RegistryEntry($kind, \strtolower($name), '', 0, '', self::REPO),
            $status === EntryStatus::NotInstalled ? null : 1,
            $name,
            RegistryResolver::TYPE_KEY_PREFIX . \strtoupper($kind),
            $version,
            $status,
            $update,
            $updateVersion,
            UpdateServerStatus::None,
            '',
            ''
        );
    }

    /**
     * @param list<RegistryRow> $rows
     */
    private static function page(
        array $rows,
        string $ordering = 'name',
        string $direction = 'ASC',
        string $search = '',
        int $start = 0,
        int $limit = 0,
    ): RegistryPage {
        return self::resolver()->page(
            $rows,
            new RegistryListState($ordering, $direction, $search, $start, $limit),
            static fn (string $key): string => $key
        );
    }

    /**
     * @return list<string>
     */
    private static function names(RegistryPage $page): array
    {
        return \array_map(static fn (RegistryRow $row): string => $row->name, $page->rows);
    }

    /**
     * @return list<RegistryRow>
     */
    private static function versions(): array
    {
        return [
            self::row('None', version: ''),
            self::row('Ten', version: '0.10.0'),
            self::row('Two', version: '0.2.0'),
        ];
    }

    // ---------------------------------------------------------------- sorting

    public function testTheDefaultOrderIsNameAscendingWithoutCase(): void
    {
        $rows = [self::row('beta'), self::row('Gamma'), self::row('alpha')];
        $page = self::resolver()->page($rows, new RegistryListState(), static fn (string $key): string => $key);

        self::assertSame(['alpha', 'beta', 'Gamma'], self::names($page));
        self::assertSame('name', $page->ordering);
        self::assertSame('ASC', $page->direction);
    }

    public function testNameDescendingIsTheReverse(): void
    {
        $page = self::page([self::row('beta'), self::row('Gamma'), self::row('alpha')], direction: 'desc');

        self::assertSame(['Gamma', 'beta', 'alpha'], self::names($page));
        self::assertSame('DESC', $page->direction);
    }

    public function testVersionSortsWithVersionCompareAndAMissingVersionLast(): void
    {
        self::assertSame(['Two', 'Ten', 'None'], self::names(self::page(self::versions(), 'version')));
        self::assertSame(['None', 'Ten', 'Two'], self::names(self::page(self::versions(), 'version', 'DESC')));
    }

    public function testAMissingVersionIsNeverPassedToVersionCompare(): void
    {
        // version_compare puts '' before any version, and 'dev' before '1.0'; a row
        // with no version must still come after both.
        $rows = [self::row('Empty'), self::row('Dev', version: 'dev'), self::row('One', version: '1.0')];

        self::assertSame(['Dev', 'One', 'Empty'], self::names(self::page($rows, 'version')));
    }

    public function testTypeSortsOnTheTranslatedLabelNotTheKey(): void
    {
        $rows   = [self::row('A', 'component'), self::row('B', 'module'), self::row('C', 'plugin')];
        $labels = [
            RegistryResolver::TYPE_KEY_PREFIX . 'COMPONENT' => 'Zeta',
            RegistryResolver::TYPE_KEY_PREFIX . 'MODULE'    => 'alpha',
            RegistryResolver::TYPE_KEY_PREFIX . 'PLUGIN'    => 'Mu',
        ];

        $page = self::resolver()->page(
            $rows,
            new RegistryListState('type', 'ASC', '', 0, 0),
            static fn (string $key): string => $labels[$key]
        );

        self::assertSame(['B', 'C', 'A'], self::names($page));
    }

    public function testStatusSortsEnabledDisabledManifestMissingNotInstalled(): void
    {
        $rows = [
            self::row('Absent', status: EntryStatus::NotInstalled),
            self::row('Missing', status: EntryStatus::ManifestMissing),
            self::row('Off', status: EntryStatus::Disabled),
            self::row('On', status: EntryStatus::Enabled),
        ];

        self::assertSame(['On', 'Off', 'Missing', 'Absent'], self::names(self::page($rows, 'status')));
        self::assertSame(['Absent', 'Missing', 'Off', 'On'], self::names(self::page($rows, 'status', 'DESC')));
    }

    public function testUpdateSortsByVersionThenNoneRecordedNotCheckedAndDash(): void
    {
        $rows = [
            self::row('Dash', update: UpdateStatus::NotApplicable),
            self::row('Unchecked', update: UpdateStatus::NotChecked),
            self::row('None', update: UpdateStatus::NoneRecorded),
            self::row('Recorded', update: UpdateStatus::Recorded, updateVersion: '0.10.0'),
            self::row('Available', update: UpdateStatus::Available, updateVersion: '0.2.0'),
        ];

        self::assertSame(
            ['Available', 'Recorded', 'None', 'Unchecked', 'Dash'],
            self::names(self::page($rows, 'update'))
        );
        self::assertSame(
            ['Dash', 'Unchecked', 'None', 'Recorded', 'Available'],
            self::names(self::page($rows, 'update', 'DESC'))
        );
    }

    public function testEqualKeysFallBackToNameSoDescendingIsTheExactReverse(): void
    {
        $rows = [self::row('b', version: '1.0'), self::row('C', version: '1.0'), self::row('a', version: '1.0')];

        $ascending  = self::names(self::page($rows, 'version'));
        $descending = self::names(self::page($rows, 'version', 'DESC'));

        self::assertSame(['a', 'b', 'C'], $ascending);
        self::assertSame(\array_reverse($ascending), $descending);
    }

    public function testRowsEqualOnEveryKeyKeepTheirInputOrderAscending(): void
    {
        $first  = self::row('Same');
        $second = self::row('Same');

        $ascending  = self::page([$first, $second])->rows;
        $descending = self::page([$first, $second], direction: 'DESC')->rows;

        self::assertSame([$first, $second], $ascending);
        self::assertSame([$second, $first], $descending);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsortableColumns(): array
    {
        return [
            'update server' => ['update_server'],
            'package'       => ['package'],
            'description'   => ['description'],
            'repository'    => ['repository'],
            'unknown'       => ['nonsense'],
            'empty'         => [''],
            'wrong case'    => ['Version'],
        ];
    }

    #[DataProvider('unsortableColumns')]
    public function testAnUnsortableOrUnknownColumnFallsBackToName(string $ordering): void
    {
        $page = self::page(self::versions(), $ordering);

        self::assertSame(['None', 'Ten', 'Two'], self::names($page));
        self::assertSame('name', $page->ordering);
    }

    public function testAnUnknownDirectionFallsBackToAscending(): void
    {
        $page = self::page(self::versions(), 'version', 'sideways');

        self::assertSame(['Two', 'Ten', 'None'], self::names($page));
        self::assertSame('ASC', $page->direction);
    }

    public function testTheDirectionStandsWhenOnlyTheColumnFallsBack(): void
    {
        // As ListModel::populateState checks the two separately.
        $page = self::page(self::versions(), 'description', 'DESC');

        self::assertSame(['Two', 'Ten', 'None'], self::names($page));
        self::assertSame(['name', 'DESC'], [$page->ordering, $page->direction]);
    }

    // ---------------------------------------------------------------- search

    public function testTheNameSearchIsACaseInsensitiveContainsMatch(): void
    {
        $rows = [self::row('Tides Module'), self::row('Tip of the Day'), self::row('Kunena Sticky Collapse')];
        $page = self::page($rows, search: 'ti');

        self::assertSame(['Kunena Sticky Collapse', 'Tides Module', 'Tip of the Day'], self::names($page));
        self::assertSame(['Tides Module'], self::names(self::page($rows, search: 'TIDES')));
    }

    public function testTheSearchIgnoresCaseOutsideAscii(): void
    {
        self::assertSame(['Übersicht'], self::names(self::page([self::row('Übersicht'), self::row('Other')], search: 'ÜBER')));
    }

    public function testTheSearchIsTrimmedAndABlankSearchMatchesEverything(): void
    {
        $rows = [self::row('Alpha'), self::row('Beta')];

        self::assertSame(['Alpha'], self::names(self::page($rows, search: '  alp ')));
        self::assertSame(['Alpha', 'Beta'], self::names(self::page($rows, search: '   ')));
    }

    public function testTheSearchIsLiteralText(): void
    {
        $rows = [self::row('a.b'), self::row('axb'), self::row('100%')];

        self::assertSame(['a.b'], self::names(self::page($rows, search: '.')));
        self::assertSame(['100%'], self::names(self::page($rows, search: '%')));
    }

    public function testASearchThatMatchesNothingGivesAnEmptyPageAndZeroTotal(): void
    {
        $page = self::page([self::row('Alpha')], search: 'zzz');

        self::assertSame([], $page->rows);
        self::assertSame(0, $page->total);
        self::assertSame(0, $page->start);
    }

    public function testTheSearchFindsANotInstalledRowByItsIdentity(): void
    {
        // A Not installed row's Name is its identity, work item 7.
        $rows = [
            self::row('mod_ystides (site)', 'module', status: EntryStatus::NotInstalled),
            self::row('Tip of the Day', 'module'),
        ];

        self::assertSame(['mod_ystides (site)'], self::names(self::page($rows, search: 'YSTIDES')));
    }

    // ---------------------------------------------------------------- pages

    /**
     * @return list<RegistryRow>
     */
    private static function seven(): array
    {
        return \array_map(static fn (string $name): RegistryRow => self::row($name), ['g', 'c', 'a', 'e', 'b', 'f', 'd']);
    }

    public function testAPageHoldsTheSliceOfTheSortedListForStartAndLimit(): void
    {
        $page = self::page(self::seven(), start: 3, limit: 3);

        self::assertSame(['d', 'e', 'f'], self::names($page));
        self::assertSame(7, $page->total);
        self::assertSame(3, $page->start);
    }

    public function testTheLastPageMayBeShort(): void
    {
        self::assertSame(['g'], self::names(self::page(self::seven(), start: 6, limit: 3)));
    }

    public function testALimitOfZeroReturnsTheWholeFilteredList(): void
    {
        $page = self::page(self::seven(), start: 3, limit: 0);

        self::assertSame(['a', 'b', 'c', 'd', 'e', 'f', 'g'], self::names($page));
        self::assertSame(0, $page->start);
    }

    public function testTheTotalIsTheUnslicedFilteredCount(): void
    {
        $rows   = self::seven();
        $rows[] = self::row('xa');
        $rows[] = self::row('xb');
        $rows[] = self::row('xc');

        $page = self::page($rows, search: 'x', start: 0, limit: 2);

        self::assertSame(['xa', 'xb'], self::names($page));
        self::assertSame(3, $page->total);
    }

    /**
     * @return array<string, array{int, int, int, list<string>}>
     */
    public static function startsPastTheEnd(): array
    {
        return [
            'just past the end' => [7, 3, 6, ['g']],
            'far past the end'  => [99, 3, 6, ['g']],
            'on an exact end'   => [6, 2, 6, ['g']],
            'past a full page'  => [9, 7, 0, ['a', 'b', 'c', 'd', 'e', 'f', 'g']],
        ];
    }

    /**
     * @param list<string> $names
     */
    #[DataProvider('startsPastTheEnd')]
    public function testAStartPastTheEndReturnsTheLastPage(int $start, int $limit, int $expectedStart, array $names): void
    {
        $page = self::page(self::seven(), start: $start, limit: $limit);

        self::assertSame($names, self::names($page));
        self::assertSame($expectedStart, $page->start);
    }

    public function testAStartInsideTheLastPageMovesToThatPagesFirstRow(): void
    {
        // ListModel::getStart: a start above total - limit becomes the last page's.
        $page = self::page(self::seven(), start: 5, limit: 3);

        self::assertSame(['g'], self::names($page));
        self::assertSame(6, $page->start);
    }

    public function testASearchPastTheOldPageReturnsTheLastPageOfTheNewList(): void
    {
        $page = self::page(self::seven(), search: 'a', start: 6, limit: 3);

        self::assertSame(['a'], self::names($page));
        self::assertSame(0, $page->start);
        self::assertSame(1, $page->total);
    }

    public function testANegativeStartOrLimitCountsAsZero(): void
    {
        $page = self::page(self::seven(), start: -3, limit: -1);

        self::assertSame(7, \count($page->rows));
        self::assertSame(0, $page->start);
    }

    public function testNoRowsGiveAnEmptyPage(): void
    {
        $page = self::page([], start: 5, limit: 3);

        self::assertSame([], $page->rows);
        self::assertSame(0, $page->total);
        self::assertSame(0, $page->start);
    }
}
