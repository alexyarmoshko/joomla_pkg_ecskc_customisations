<?php

declare(strict_types=1);

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\View {

    use DOMDocument;
    use DOMXPath;
    use Joomla\CMS\Language\Text;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\HashResult;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalCheck;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalChecks;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\PlannedOperation;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\ReportedValue;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleAction;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleObject;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ObjectOutcome;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ProposedOperation;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetState;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Model\RunRequest;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Model\TargetFilter;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Model\WriteResult;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\Reverter;

    /**
     * The target report's two sublayouts, rendered over hand-built reports with the
     * real English language file. Ticket 42 proves the page on DDEV; this proves what
     * the rows and the summary say.
     */
    #[CoversClass(TargetFilter::class)]
    #[CoversClass(RunRequest::class)]
    #[CoversClass(WriteResult::class)]
    final class TargetReportLayoutTest extends TestCase
    {
        private const EXPECTED = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        private const ACTUAL   = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        private const UNREADABLE = 'the target could not be read: permission denied';
        private const NO_WRITE   = 'the event log records no package write at this path';
        private const SCRIPT     = 'the target matches no object <script>alert(1)</script>';

        protected function setUp(): void
        {
            $strings = \parse_ini_file(
                self::component() . '/admin/language/en-GB/com_ecskccustom.ini',
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

        private static function component(): string
        {
            return \dirname(__DIR__, 3) . '/extensions/com_ecskccustom';
        }

        /**
         * @param list<TargetReport>|null $items The rows the filters let through; all of them if null.
         * @param string                  $sublayout A `default_` sublayout, or `result` for the result layout.
         */
        private static function render(
            string $sublayout,
            RunReport $report,
            ?array $items = null,
            ?RunRequest $run = null,
            bool $canWrite = false,
            ?WriteResult $result = null,
        ): string {
            $view = new class ($report, $items ?? $report->targets(), $run ?? RunRequest::wholeSetCheck(), $canWrite, $result) {
                /** @var array<string, mixed> */
                public array $counts = [];

                /** @var list<array<string, mixed>> */
                public array $rows = [];

                public bool $rowsOpen = false;

                public ?\Closure $rowControls = null;

                /**
                 * @param list<TargetReport> $items
                 */
                public function __construct(
                    public RunReport $report,
                    public array $items,
                    public RunRequest $run,
                    public bool $canWrite,
                    public ?WriteResult $result,
                ) {
                }

                public function escape($var): string
                {
                    return $var === null ? '' : \htmlspecialchars((string) $var, ENT_QUOTES, 'UTF-8');
                }

                // Route::_() on a non-SEF admin URL, as HtmlView::runUrl() calls it.
                public function runUrl(string $run, ?string $path = null): string
                {
                    return \htmlspecialchars(RunRequest::displayUrl($run, $path), ENT_COMPAT, 'UTF-8');
                }

                public function taskUrl(string $operation): string
                {
                    return \htmlspecialchars('index.php?option=com_ecskccustom&task=targets.' . $operation, ENT_COMPAT, 'UTF-8');
                }

                // Loads default_<tpl>.php, which HtmlView::loadTemplate() falls back to
                // from any layout. The write form needs HTMLHelper for its token, so the
                // layouts are tested without it.
                public function loadTemplate(string $tpl): string
                {
                    if ($tpl === 'writeform') {
                        return '';
                    }

                    \ob_start();

                    try {
                        include \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/tmpl/targets/default_' . $tpl . '.php';
                    } catch (\Throwable $e) {
                        \ob_end_clean();

                        throw $e;
                    }

                    return (string) \ob_get_clean();
                }
            };

            $file = self::component() . '/admin/tmpl/targets/'
                . ($sublayout === 'result' ? 'result' : 'default_' . $sublayout) . '.php';

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
                // The encoding prefix stops libxml reading UTF-8 as Latin-1.
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

        private static function object(string $targetPath, int $version): RuleObject
        {
            return new RuleObject(
                '10-test.xml',
                'sha256',
                'JPATH_ROOT',
                $targetPath,
                $targetPath,
                false,
                $version,
                false,
                true,
                true,
                self::EXPECTED,
                [RuleAction::copy('test.css.v' . $version, self::ACTUAL)]
            );
        }

        private static function state(string $path, string $status, string $reason, ?HashResult $actual = null): TargetState
        {
            $object = self::object($path, 1);

            return TargetState::of(
                $path,
                $status,
                $reason,
                HashResult::digest(self::EXPECTED),
                $actual ?? HashResult::digest(self::ACTUAL),
                [ObjectOutcome::for($object, ObjectOutcome::NOT_MATCHED, 'the target matches neither hash')]
            );
        }

        private static function line(
            TargetState $state,
            array $plan = [],
            ?ReportedValue $outcome = null,
            ?string $warning = null,
        ): TargetReport {
            return new TargetReport(
                $state,
                ReportedValue::none(self::NO_WRITE),
                ReportedValue::none('a check performs no operation'),
                $plan,
                $outcome,
                $warning
            );
        }

        /**
         * One line per status, the fields the rows must show, and one reason carrying markup.
         */
        private static function everyStatus(OperationalChecks $checks): RunReport
        {
            $actionable = self::state('templates/et_tennis/css/actionable.css', TargetStatus::ACTIONABLE, 'version 1 would apply');
            $object     = self::object('templates/et_tennis/css/actionable.css', 1);
            $planned    = new PlannedOperation(
                ProposedOperation::fromAction($object, $object->actions[0], 'version 1 would apply'),
                ReportedValue::known(self::ACTUAL),
                ReportedValue::known('backup/' . self::ACTUAL),
                [],
                ReportedValue::none(PlannedOperation::NOT_EXECUTED)
            );

            return RunReport::of(
                [
                    self::line(TargetState::of(
                        'components/com_kunena/ok.php',
                        TargetStatus::OK,
                        'the head of the chain is disabled',
                        HashResult::digest(self::EXPECTED),
                        HashResult::digest(self::EXPECTED),
                        [],
                        [],
                        TargetState::DISABLED
                    )),
                    self::line($actionable, [$planned], ReportedValue::none('a dry run performed nothing')),
                    self::line(self::state('templates/et_tennis/css/incomplete.css', TargetStatus::INCOMPLETE, 'version 2 of 3 is in place')),
                    self::line(self::state('templates/et_tennis/css/blocked.css', TargetStatus::BLOCKED, self::SCRIPT)),
                    self::line(self::state('templates/et_tennis/css/na.css', TargetStatus::NOT_APPLICABLE, 'the object refuses the write')),
                    self::line(TargetState::frozen(self::state('templates/et_tennis/css/frozen.css', TargetStatus::ACTIONABLE, 'version 1 would apply'))),
                    self::line(self::state(
                        'templates/et_tennis/css/faulted.css',
                        TargetStatus::FAULTED,
                        'a condition could not be judged',
                        HashResult::unavailable(self::UNREADABLE)
                    )),
                ],
                $checks,
                ReportedValue::none('no earlier write run was interrupted')
            );
        }

        public function testEveryNonOkCardShowsItsPathAndStateAboveTheThreeFieldsInOrder(): void
        {
            $report = self::everyStatus(OperationalChecks::none());
            $xpath  = self::dom(self::render('targets', $report));

            self::assertSame(TargetStatus::ALL, self::texts($xpath, '//article/@data-status'));

            foreach ($report->targets() as $target) {
                $row = '//article[.//h3/code = "' . $target->targetPath() . '"]';

                self::assertSame([$target->describe()], self::texts($xpath, $row . '//span[contains(@class, "ecskc-state")]'));
                self::assertSame([$target->reason()], self::texts($xpath, $row . '//p[@class="ecskc-reason"]'));

                if ($target->status() === TargetStatus::OK) {
                    self::assertSame([], self::texts($xpath, $row . '//dl'), 'An ok row shows no required fields.');
                    continue;
                }

                // The header shows the path, so the fields do not repeat it.
                self::assertSame(
                    ['Expected hash', 'Actual hash', 'Last package write'],
                    self::texts($xpath, $row . '//dl/dt')
                );
                self::assertSame(
                    \array_slice(\array_values($target->requiredFields()), 1),
                    self::texts($xpath, $row . '//dl/dd')
                );
            }
        }

        /**
         * Atum makes a card header a flex row, so the header holds one block, and the
         * path line, the links and the buttons stack inside it in that order.
         */
        public function testTheCardHeaderStacksThePathTheLinksAndTheButtons(): void
        {
            $report = self::everyStatus(OperationalChecks::none());
            $xpath  = self::dom(self::render('targets', $report, null, null, true));

            foreach ($xpath->query('//article/div[contains(@class, "card-header")]') as $header) {
                $children = \iterator_to_array($xpath->query('./*', $header));

                self::assertCount(1, $children);
                self::assertStringContainsString('ecskc-card-head', $children[0]->getAttribute('class'));
                self::assertSame(
                    ['d-flex align-items-start gap-2', 'ecskc-row-runs small mt-2 mb-0', 'ecskc-row-writes'],
                    \array_map(
                        static fn (\DOMElement $child): string => $child->getAttribute('class'),
                        \iterator_to_array($xpath->query('./*', $children[0]))
                    )
                );
            }
        }

        public function testEachStateGetsItsBadgeColour(): void
        {
            $xpath   = self::dom(self::render('targets', self::everyStatus(OperationalChecks::none())));
            $colours = [];

            foreach ($xpath->query('//article') as $card) {
                $badge = $xpath->query('.//span[contains(@class, "ecskc-state")]', $card)->item(0);
                self::assertInstanceOf(\DOMElement::class, $badge);

                $colours[$card->getAttribute('data-status')] = $badge->getAttribute('class');
            }

            self::assertSame(
                [
                    TargetStatus::OK             => 'badge text-bg-success ecskc-state',
                    TargetStatus::ACTIONABLE     => 'badge text-bg-primary ecskc-state',
                    TargetStatus::INCOMPLETE     => 'badge text-bg-warning ecskc-state',
                    TargetStatus::BLOCKED        => 'badge text-bg-danger ecskc-state',
                    TargetStatus::NOT_APPLICABLE => 'badge text-bg-secondary ecskc-state',
                    TargetStatus::FROZEN         => 'badge text-bg-warning ecskc-state',
                    TargetStatus::FAULTED        => 'badge text-bg-danger ecskc-state',
                ],
                $colours
            );
        }

        public function testThePathBreaksOnlyAfterASlashAndStaysEscaped(): void
        {
            $path   = 'templates/et_tennis/css/a<b>.css';
            $report = RunReport::of(
                [self::line(self::state($path, TargetStatus::ACTIONABLE, 'version 1 would apply'))],
                OperationalChecks::none(),
                ReportedValue::none('no earlier write run was interrupted')
            );
            $html   = self::render('targets', $report);

            self::assertStringContainsString(
                '<code>templates/<wbr>et_tennis/<wbr>css/<wbr>a&lt;b&gt;.css</code>',
                $html
            );
            self::assertSame([$path], self::texts(self::dom($html), '//article//h3/code'));
        }

        public function testQualifiersAndReasonsInsteadOfHashesRender(): void
        {
            $xpath = self::dom(self::render('targets', self::everyStatus(OperationalChecks::none())));

            self::assertSame(['ok (disabled)'], self::texts($xpath, '//article[@data-status="ok"]//span[contains(@class, "ecskc-state")]'));
            self::assertSame(['frozen (would be actionable)'], self::texts($xpath, '//article[@data-status="frozen"]//span[contains(@class, "ecskc-state")]'));
            self::assertSame(
                [self::EXPECTED, self::UNREADABLE, self::NO_WRITE],
                self::texts($xpath, '//article[@data-status="faulted"]//dl/dd')
            );
        }

        public function testTheRowDetailShowsObjectsPlanAndOutcomeWhenTheReportCarriesThem(): void
        {
            $xpath = self::dom(self::render('targets', self::everyStatus(OperationalChecks::none())));
            $row   = '//article[@data-status="actionable"]//details';

            self::assertSame(
                ['version 1, does not match: the target matches neither hash'],
                self::texts($xpath, $row . '//ul[@class="ecskc-objects"]/li')
            );

            $plan = self::texts($xpath, $row . '//ul[@class="ecskc-plan"]/li');
            self::assertCount(1, $plan);
            self::assertStringContainsString('Backup: backup/' . self::ACTUAL, $plan[0]);
            self::assertSame(['a dry run performed nothing'], self::texts($xpath, $row . '//p[@class="ecskc-outcome"]'));

            self::assertSame([], self::texts($xpath, '//article[@data-status="blocked"]//ul[@class="ecskc-plan"]'));
            self::assertSame([], self::texts($xpath, '//article[@data-status="blocked"]//p[@class="ecskc-outcome"]'));
        }

        public function testAReasonCarryingMarkupRendersEscaped(): void
        {
            $html = self::render('targets', self::everyStatus(OperationalChecks::none()));

            self::assertStringNotContainsString('<script>', $html);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        }

        public function testTheStateFilterNarrowsTheRowsAndLeavesTheCounts(): void
        {
            $report   = self::everyStatus(OperationalChecks::none());
            $filtered = (new TargetFilter(TargetStatus::FROZEN, ''))->apply($report->targets());

            self::assertSame(
                [TargetStatus::FROZEN],
                self::texts(self::dom(self::render('targets', $report, $filtered)), '//article/@data-status')
            );
            self::assertSame(
                self::render('summary', $report),
                self::render('summary', $report, $filtered)
            );
            self::assertSame(['1', '1', '1', '1', '1', '1', '1', '7'], self::texts(self::dom(self::render('summary', $report, $filtered)), '//table[@id="ecskc-counts"]//td'));
        }

        public function testThePathFilterMatchesASubstringWithoutRegardToCase(): void
        {
            $report   = self::everyStatus(OperationalChecks::none());
            $filtered = (new TargetFilter('', 'KUNENA'))->apply($report->targets());

            self::assertSame(
                ['components/com_kunena/ok.php'],
                self::texts(self::dom(self::render('targets', $report, $filtered)), '//article//h3/code')
            );
            self::assertCount(6, (new TargetFilter('', 'css'))->apply($report->targets()));
            self::assertCount(1, (new TargetFilter(TargetStatus::ACTIONABLE, 'css'))->apply($report->targets()));
        }

        public function testAnUnknownStatusOrAnEmptyFilterNarrowsNothing(): void
        {
            $targets = self::everyStatus(OperationalChecks::none())->targets();

            self::assertSame($targets, (new TargetFilter('', ''))->apply($targets));
            self::assertSame($targets, (new TargetFilter('broken', '  '))->apply($targets));
        }

        public function testAFilterThatMatchesNothingSaysSo(): void
        {
            $report = self::everyStatus(OperationalChecks::none());
            $xpath  = self::dom(self::render('targets', $report, []));

            self::assertSame(['No managed path matches the filters.'], self::texts($xpath, '//*[@id="ecskc-no-targets"]'));
            self::assertSame([], self::texts($xpath, '//table'));
        }

        /**
         * The dashboard shows the system plugin now. The report still carries its
         * checks, for the installer plugin, but the summary lists none of them.
         */
        public function testTheSummaryListsNoOperationalCheck(): void
        {
            $checks = OperationalChecks::of(
                new OperationalCheck('system_plugin_enabled', 'disabled', true, 'the plugin is disabled')
            );
            $xpath  = self::dom(self::render('summary', self::everyStatus($checks)));

            self::assertSame([], self::texts($xpath, '//*[@id="ecskc-checks"]'));
            self::assertStringNotContainsString('system_plugin_enabled', (string) $xpath->document->saveHTML());
            self::assertSame(
                self::texts(self::dom(self::render('summary', self::everyStatus(OperationalChecks::none()))), '//table[@id="ecskc-counts"]//td'),
                self::texts($xpath, '//table[@id="ecskc-counts"]//td')
            );
        }

        public function testTheCountsSitInASummarySectionWithTheirDescriptionAbove(): void
        {
            $xpath = self::dom(self::render('summary', self::everyStatus(OperationalChecks::none())));

            self::assertSame(
                ['h2', 'p', 'table'],
                \array_map(
                    static fn (\DOMElement $child): string => $child->tagName,
                    \iterator_to_array($xpath->query('//section[@id="ecskc-summary"]/*'))
                )
            );
            self::assertSame(['Summary'], self::texts($xpath, '//section[@id="ecskc-summary"]/h2'));
            self::assertSame(
                ['Managed paths by state, across the whole report. The filters do not change these counts.'],
                self::texts($xpath, '//section[@id="ecskc-summary"]/p')
            );

            $table = $xpath->query('//table[@id="ecskc-counts"]')->item(0);
            self::assertInstanceOf(\DOMElement::class, $table);
            self::assertSame('table table-sm', $table->getAttribute('class'));
            self::assertSame(0, $xpath->query('//table[@id="ecskc-counts"]/caption')->length);
            self::assertSame(['Summary'], self::texts($xpath, '//*[@id="' . $table->getAttribute('aria-labelledby') . '"]'));
            self::assertSame(
                self::texts($xpath, '//section[@id="ecskc-summary"]/p'),
                self::texts($xpath, '//*[@id="' . $table->getAttribute('aria-describedby') . '"]')
            );
        }

        public function testTheCountsNameEveryStatusInTheRunnersWords(): void
        {
            $xpath = self::dom(self::render('summary', self::everyStatus(OperationalChecks::none())));

            self::assertSame(
                [...TargetStatus::ALL, 'Managed paths'],
                self::texts($xpath, '//table[@id="ecskc-counts"]//th')
            );
        }

        public function testAFaultedReportRendersItsFaultsAndAnEmptyList(): void
        {
            $report = RunReport::faulted(
                ['10-test.xml does not parse: <object> is not closed', 'the event log line 3 is damaged'],
                OperationalChecks::none(),
                ReportedValue::known('a write run stopped at templates/et_tennis/css/x.css')
            );

            $summary = self::dom(self::render('summary', $report));
            self::assertSame(
                ['10-test.xml does not parse: <object> is not closed', 'the event log line 3 is damaged'],
                self::texts($summary, '//*[@id="ecskc-faults"]//li')
            );
            self::assertSame(
                ['a write run stopped at templates/et_tennis/css/x.css'],
                self::texts($summary, '//*[@id="ecskc-interruption"]/p')
            );
            self::assertSame(['0', '0', '0', '0', '0', '0', '0', '0'], self::texts($summary, '//table[@id="ecskc-counts"]//td'));

            $targets = self::dom(self::render('targets', $report));
            self::assertSame(['This report has no managed paths to list.'], self::texts($targets, '//*[@id="ecskc-no-targets"]'));
            self::assertSame([], self::texts($targets, '//table'));
        }

        public function testACleanReportShowsNoFaultOrInterruption(): void
        {
            $xpath = self::dom(self::render('summary', self::everyStatus(OperationalChecks::none())));

            self::assertSame([], self::texts($xpath, '//*[@id="ecskc-faults"]'));
            self::assertSame([], self::texts($xpath, '//*[@id="ecskc-interruption"]'));
        }

        public function testAnEmptyFieldIsNeverLeftBlank(): void
        {
            $report = RunReport::of(
                [new TargetReport(
                    self::state('templates/et_tennis/css/blank.css', TargetStatus::BLOCKED, 'blocked'),
                    ReportedValue::none(''),
                    ReportedValue::none('')
                )],
                OperationalChecks::none(),
                ReportedValue::none('')
            );

            self::assertSame(
                'The report gave neither a value nor a reason for this field.',
                self::texts(self::dom(self::render('targets', $report)), '//dl/dd')[2]
            );
        }

        /**
         * @return iterable<string, array{RunRequest, string, string}>
         */
        public static function runs(): iterable
        {
            yield 'whole-set check' => [
                RunRequest::fromInput('check', null),
                'Check of every managed path.',
                'This check wrote nothing to the site.',
            ];
            yield 'single-path check' => [
                RunRequest::fromInput('check', 'templates/et_tennis/css/blocked.css'),
                'Check of one path: templates/et_tennis/css/blocked.css',
                'This check wrote nothing to the site.',
            ];
            yield 'whole-set dry run' => [
                RunRequest::fromInput('dryrun', null),
                'Dry run of every managed path.',
                'This dry run wrote nothing to the site. No file, backup, or record was changed.',
            ];
            yield 'single-path dry run' => [
                RunRequest::fromInput('dryrun', 'templates/et_tennis/css/blocked.css'),
                'Dry run of one path: templates/et_tennis/css/blocked.css.',
                'This dry run wrote nothing to the site. No file, backup, or record was changed.',
            ];
        }

        #[DataProvider('runs')]
        public function testTheRunLineNamesTheRunAndSaysNothingWasWritten(RunRequest $run, string $scope, string $written): void
        {
            $xpath = self::dom(self::render('summary', self::everyStatus(OperationalChecks::none()), null, $run));
            $lines = self::texts($xpath, '//*[@id="ecskc-run"]/p');

            self::assertStringStartsWith($scope, $lines[0]);
            self::assertSame($written, \end($lines));
            self::assertSame([], self::texts($xpath, '//*[@id="ecskc-run-notice"]'));

            $all = self::texts($xpath, '//*[@id="ecskc-run"]//a/@href');

            if ($run->path === null) {
                self::assertSame([], $all);
            } else {
                self::assertSame([RunRequest::displayUrl($run->run)], $all);
            }
        }

        public function testAPathInTheRunLineRendersEscaped(): void
        {
            $path = 'templates/et_tennis/css/vendor.css<script>alert(1)</script>';
            $html = self::render('summary', self::everyStatus(OperationalChecks::none()), null, RunRequest::fromInput('check', $path));

            self::assertStringNotContainsString('<script>', $html);
            self::assertSame([$path], self::texts(self::dom($html), '//*[@id="ecskc-run"]/p/code'));
        }

        public function testANoticeSaysWhyTheRequestWasNotRunAsSent(): void
        {
            $report = self::everyStatus(OperationalChecks::none());

            foreach (
                [
                    [RunRequest::fromInput('apply', null), 'The address asked for a run this page does not know.'],
                    [RunRequest::fromInput('dryrun', ['x']), 'The address gave the path as something other than a single value.'],
                ] as [$run, $notice]
            ) {
                $xpath = self::dom(self::render('summary', $report, null, $run));

                self::assertStringStartsWith($notice, self::texts($xpath, '//*[@id="ecskc-run-notice"]')[0]);
                self::assertSame('Check of every managed path.', self::texts($xpath, '//*[@id="ecskc-run"]/p')[0]);
            }
        }

        public function testEveryRowLinksToItsOwnCheckAndDryRun(): void
        {
            $report = self::everyStatus(OperationalChecks::none());
            $xpath  = self::dom(self::render('targets', $report));

            foreach ($report->targets() as $target) {
                $row = '//article[.//h3/code = "' . $target->targetPath() . '"]';

                self::assertSame(
                    [
                        RunRequest::displayUrl(RunRequest::CHECK, $target->targetPath()),
                        RunRequest::displayUrl(RunRequest::DRY_RUN, $target->targetPath()),
                    ],
                    self::texts($xpath, $row . '//p[contains(@class, "ecskc-row-runs")]/a/@href')
                );
            }
        }

        public function testARowLinkCarriesThePathEncoded(): void
        {
            self::assertSame(
                'index.php?option=com_ecskccustom&view=targets&run=dryrun&path=a%20b%2F..%2Fc%26amp%3B%3Cscript%3E',
                RunRequest::displayUrl(RunRequest::DRY_RUN, 'a b/../c&amp;<script>')
            );
        }

        public function testADryRunOpensEveryPlanAndACheckLeavesThemClosed(): void
        {
            $report = self::everyStatus(OperationalChecks::none());

            $dryRun = self::dom(self::render('targets', $report, null, RunRequest::fromInput('dryrun', null)));
            $check  = self::dom(self::render('targets', $report, null, RunRequest::fromInput('check', null)));

            self::assertNotSame([], self::texts($dryRun, '//details'));
            self::assertSame([], self::texts($dryRun, '//details[not(@open)]'));
            self::assertSame([], self::texts($check, '//details[@open]'));
            self::assertStringContainsString(
                'Backup: backup/' . self::ACTUAL,
                self::texts($dryRun, '//ul[@class="ecskc-plan"]/li')[0]
            );
        }

        /**
         * @return iterable<string, array{RunRequest, bool, bool}>
         */
        public static function applyPlanPages(): iterable
        {
            foreach ([true, false] as $canWrite) {
                $who = $canWrite ? 'writer' : 'reader';

                yield 'whole-set dry run, ' . $who => [RunRequest::fromInput('dryrun', null), $canWrite, $canWrite];
                yield 'single-path dry run, ' . $who => [RunRequest::fromInput('dryrun', self::PATH), $canWrite, false];
                yield 'whole-set check, ' . $who => [RunRequest::fromInput('check', null), $canWrite, false];
            }
        }

        private const PATH = 'templates/et_tennis/css/actionable.css';

        #[DataProvider('applyPlanPages')]
        public function testOnlyAWriterGetsTheApplyThisPlanFormAndOnlyOnTheWholeSetDryRun(RunRequest $run, bool $canWrite, bool $shown): void
        {
            $xpath  = self::dom(self::render('summary', self::everyStatus(OperationalChecks::none()), null, $run, $canWrite));
            $button = '//button[@id="ecskc-apply-plan"]';

            if (!$shown) {
                self::assertSame([], self::texts($xpath, $button));

                return;
            }

            self::assertSame(['Apply this plan'], self::texts($xpath, $button));
            self::assertSame(['ecskc-write'], self::texts($xpath, $button . '/@form'));
            self::assertSame(['index.php?option=com_ecskccustom&task=targets.applyall'], self::texts($xpath, $button . '/@formaction'));
            self::assertSame([WriteResult::CONFIRM_FIELD], self::texts($xpath, $button . '/@name'));
            self::assertSame(['1'], self::texts($xpath, $button . '/@value'));
        }

        public function testAReaderGetsNoWriteButtons(): void
        {
            $html = self::render('targets', self::everyStatus(OperationalChecks::none()));

            self::assertSame([], self::texts(self::dom($html), '//button'));
            self::assertStringNotContainsString('task=', $html);
        }

        public function testEachRowGivesAWriterItsWritesWithAPromptNamingTheOperationAndThePath(): void
        {
            $report = self::everyStatus(OperationalChecks::none());
            $xpath  = self::dom(self::render('targets', $report, null, null, true));

            foreach ($report->targets() as $target) {
                $path    = $target->targetPath();
                $buttons = '//article[.//h3/code = "' . $path . '"]//div[@class="ecskc-row-writes"]/button';
                $toggle  = $target->status() === TargetStatus::FROZEN ? 'unfreeze' : 'freeze';

                self::assertSame(['Apply', 'Revert one step', 'Apply one step', \ucfirst($toggle)], self::texts($xpath, $buttons));
                self::assertSame(
                    \array_map(
                        static fn (string $task): string => 'index.php?option=com_ecskccustom&task=targets.' . $task,
                        ['apply', 'revert', 'redo', $toggle]
                    ),
                    self::texts($xpath, $buttons . '/@formaction')
                );
                self::assertSame(\array_fill(0, 4, $path), self::texts($xpath, $buttons . '/@value'));
                self::assertSame(\array_fill(0, 4, 'path'), self::texts($xpath, $buttons . '/@name'));
                self::assertSame(\array_fill(0, 4, 'ecskc-write'), self::texts($xpath, $buttons . '/@form'));

                $prompts = self::texts($xpath, $buttons . '/@data-ecskc-confirm');

                self::assertCount(4, $prompts);

                foreach ($prompts as $index => $prompt) {
                    self::assertStringContainsString($path, $prompt);
                    self::assertStringStartsWith(['Apply', 'Revert one step', 'Apply one step', \ucfirst($toggle)][$index] . ' ', $prompt);
                }

                self::assertStringContainsString('The next Apply changes it back unless the file is frozen.', $prompts[1]);
            }
        }

        /**
         * Each write button names the hint that follows it, in Atum's tooltip pattern,
         * and no two hints on the page share an id.
         */
        public function testEachWriteButtonNamesAHintThatFollowsIt(): void
        {
            $report = self::everyStatus(OperationalChecks::none());
            $xpath  = self::dom(self::render('targets', $report, null, null, true));
            $hints  = [
                'Apply'           => 'Update this file to the latest version. What it overwrites is backed up first.',
                'Revert one step' => 'Put back the version before the current one. The next Apply changes it again unless the file is frozen.',
                'Apply one step'  => 'Update this file by one version only. Apply does every step at once.',
                'Freeze'          => 'Keep this file as it is. Apply and Revert leave it alone until it is unfrozen.',
                'Unfreeze'        => 'Let Apply and Revert change this file again.',
            ];
            $buttons = \iterator_to_array($xpath->query('//div[@class="ecskc-row-writes"]/button'));
            $ids     = [];

            self::assertCount(4 * \count($report->targets()), $buttons);

            foreach ($buttons as $button) {
                $id  = $button->getAttribute('aria-describedby');
                $tip = $button->nextSibling;

                while ($tip !== null && !$tip instanceof \DOMElement) {
                    $tip = $tip->nextSibling;
                }

                self::assertNotSame('', $id);
                self::assertInstanceOf(\DOMElement::class, $tip);
                self::assertSame('tooltip', $tip->getAttribute('role'));
                self::assertSame($id, $tip->getAttribute('id'));
                self::assertSame($hints[\trim($button->textContent)], \trim($tip->textContent));

                $ids[] = $id;
            }

            self::assertSame($ids, \array_values(\array_unique($ids)));
        }

        public function testAPathInAWriteButtonRendersEscaped(): void
        {
            $path   = 'templates/et_tennis/css/vendor.css"><script>alert(1)</script>';
            $report = RunReport::of(
                [self::line(self::state($path, TargetStatus::ACTIONABLE, 'version 1 would apply'))],
                OperationalChecks::none(),
                ReportedValue::none('no earlier write run was interrupted')
            );

            $html  = self::render('targets', $report, null, null, true);
            $xpath = self::dom($html);

            self::assertStringNotContainsString('<script>', $html);
            self::assertSame(\array_fill(0, 4, $path), self::texts($xpath, '//div[@class="ecskc-row-writes"]/button/@value'));
        }

        /**
         * @param bool $moved Whether the revert moved the file, and so carries the warning.
         */
        private static function revertResult(bool $moved = true): WriteResult
        {
            $path   = 'templates/et_tennis/css/chain.css';
            $report = RunReport::of(
                [self::line(
                    self::state($path, TargetStatus::ACTIONABLE, 'version 2 would apply'),
                    [],
                    ReportedValue::known('the target was stepped back to version 1'),
                    $moved ? \sprintf(Reverter::REAPPLY_WARNING, $path) : null
                )],
                OperationalChecks::none(),
                ReportedValue::known('the run begun at 10:00 was interrupted <b>after</b> its intent'),
                ['another write run is in progress', 'the write intent published for x is still outstanding']
            );

            return WriteResult::fromReport(WriteResult::REVERT, $path, $report);
        }

        public function testAfterARevertTheWarningIsProminentWithAFreezeButtonForThatPath(): void
        {
            $result = self::revertResult();
            $empty  = self::everyStatus(OperationalChecks::none());
            $xpath  = self::dom(self::render('result', $empty, null, null, true, $result));
            $block  = '//*[@id="ecskc-revert-warning"]';

            self::assertSame(['The next apply undoes this revert'], self::texts($xpath, $block . '/h2'));
            self::assertSame([(string) $result->warning], self::texts($xpath, $block . '/p'));

            $freeze = $block . '/button[@id="ecskc-revert-freeze"]';

            self::assertSame(['Freeze'], self::texts($xpath, $freeze));
            self::assertSame(['index.php?option=com_ecskccustom&task=targets.freeze'], self::texts($xpath, $freeze . '/@formaction'));
            self::assertSame([$result->path], self::texts($xpath, $freeze . '/@value'));
            self::assertStringStartsWith('Freeze ' . $result->path . '?', self::texts($xpath, $freeze . '/@data-ecskc-confirm')[0]);

            $reader = self::dom(self::render('result', $empty, null, null, false, $result));

            self::assertSame([(string) $result->warning], self::texts($reader, $block . '/p'));
            self::assertSame([], self::texts($reader, '//button'));
        }

        public function testARevertWithNoWarningShowsNeitherTheWarningNorItsFreezeButton(): void
        {
            $result = self::revertResult(false);
            $xpath  = self::dom(self::render('result', self::everyStatus(OperationalChecks::none()), null, null, true, $result));

            self::assertNull($result->warning);
            self::assertSame([], self::texts($xpath, '//*[@id="ecskc-revert-warning"]'));
            self::assertSame([], self::texts($xpath, '//*[@id="ecskc-revert-freeze"]'));
        }

        public function testTheResultShowsWhyTheRunStoppedVerbatimAndNeverSaysNothingWasWritten(): void
        {
            $result = self::revertResult();
            $html   = self::render('result', self::everyStatus(OperationalChecks::none()), null, null, false, $result);
            $xpath  = self::dom($html);

            self::assertStringStartsWith('Result of reverting one step: ' . $result->path, self::texts($xpath, '//*[@id="ecskc-run"]/p')[0]);
            self::assertSame($result->faults, self::texts($xpath, '//*[@id="ecskc-faults"]//li'));
            self::assertSame([(string) $result->interruption], self::texts($xpath, '//*[@id="ecskc-interruption"]/p'));
            self::assertStringNotContainsString('<b>', $html);
            self::assertStringNotContainsString('wrote nothing', $html);
            self::assertSame(['index.php?option=com_ecskccustom&view=targets&run=check'], self::texts($xpath, '//*[@id="ecskc-run"]//a/@href'));
        }

        public function testTheResultRowsShowTheirFieldsBackupAndOutcome(): void
        {
            $result = self::revertResult();
            $xpath  = self::dom(self::render('result', self::everyStatus(OperationalChecks::none()), null, null, false, $result));
            $row    = $result->targets[0];

            self::assertSame([$row['status']], self::texts($xpath, '//*[@id="ecskc-targets"]/article/@data-status'));
            self::assertSame([$row['state']], self::texts($xpath, '//*[@id="ecskc-targets"]/article//span[contains(@class, "ecskc-state")]'));
            self::assertSame(\array_slice(\array_values($row['required']), 1), self::texts($xpath, '//*[@id="ecskc-targets"]/article//dl/dd'));
            self::assertSame([$row['backup']], self::texts($xpath, '//p[@class="ecskc-backup"]'));
            self::assertSame([(string) $row['outcome']], self::texts($xpath, '//p[@class="ecskc-outcome"]'));
            self::assertSame([], self::texts($xpath, '//details[not(@open)]'));
        }
    }
}
