<?php

declare(strict_types=1);

namespace Joomla\CMS\Uri {
    final class Uri
    {
        /** The site's base path, as Uri::root(true) returns it for a site in a subfolder. */
        public const BASE = '/club';

        public static function root($pathonly = false, $path = null): string
        {
            if ($pathonly !== true) {
                throw new \LogicException('The plugin must ask for the path only.');
            }

            return self::BASE;
        }
    }
}

namespace Joomla\CMS\Event\Application {
    /**
     * The two accessors the plugin uses. It counts document reads, so a test can
     * show the administrator guard returns before the document is touched.
     */
    final class BeforeCompileHeadEvent
    {
        public int $documentReads = 0;

        public function __construct(private readonly object $application, private readonly object $document)
        {
        }

        public function getApplication(): object
        {
            return $this->application;
        }

        public function getDocument(): object
        {
            $this->documentReads++;

            return $this->document;
        }
    }
}

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Plugin {

    use Joomla\CMS\Event\Application\BeforeCompileHeadEvent;
    use Joomla\CMS\Uri\Uri;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;
    use YakShaver\Plugin\System\ECSKCCustomisation\Extension\ECSKCCustomisationPlugin;

    /**
     * The system plugin registers the applied `override.css` on a site HTML page
     * and does nothing anywhere else (plan decisions 19 and 20).
     */
    #[CoversClass(ECSKCCustomisationPlugin::class)]
    final class SystemPluginTest extends TestCase
    {
        private ?TempTree $site = null;

        protected function setUp(): void
        {
            StatRecorder::$stats = [];
        }

        protected function tearDown(): void
        {
            $this->site?->remove();
            $this->site = null;

            if (\in_array(StatRecorder::SCHEME, \stream_get_wrappers(), true)) {
                \stream_wrapper_unregister(StatRecorder::SCHEME);
            }
        }

        private function siteWithStylesheet(): string
        {
            $this->site = TempTree::create('ecskc-plugin-site-');
            $this->site->write(ECSKCCustomisationPlugin::STYLESHEET, "body {\r\n}");

            return $this->site->root();
        }

        private static function plugin(string $siteRoot): ECSKCCustomisationPlugin
        {
            return new ECSKCCustomisationPlugin(['name' => 'ecskccustom', 'type' => 'system'], $siteRoot);
        }

        private static function recordingRoot(): string
        {
            \stream_wrapper_register(StatRecorder::SCHEME, StatRecorder::class);

            return StatRecorder::SCHEME . '://site';
        }

        public function testASitePageGetsTheStylesheetOnceWithAMediaVersion(): void
        {
            $document = new DocumentDouble('html');
            $event    = new BeforeCompileHeadEvent(new ApplicationDouble('site'), $document);

            self::plugin($this->siteWithStylesheet())->onBeforeCompileHead($event);

            self::assertSame(
                [[Uri::BASE . '/templates/et_tennis/css/override.css', ['version' => 'auto'], []]],
                $document->styleSheets
            );
        }

        public function testTheAdministratorGetsNothingAndNoFileIsLookedAt(): void
        {
            $document = new DocumentDouble('html');
            $event    = new BeforeCompileHeadEvent(new ApplicationDouble('administrator'), $document);

            self::plugin(self::recordingRoot())->onBeforeCompileHead($event);

            self::assertSame([], $document->styleSheets);
            self::assertSame([], StatRecorder::$stats, 'The administrator guard must return before the file check.');
            self::assertSame(0, $event->documentReads, 'The administrator guard must return before the document is read.');
        }

        /**
         * The positive control for the test above: the recorder does see the file
         * check when the guard lets a request through.
         */
        public function testTheRecorderSeesTheFileCheckOnASitePage(): void
        {
            $document = new DocumentDouble('html');
            $event    = new BeforeCompileHeadEvent(new ApplicationDouble('site'), $document);

            self::plugin(self::recordingRoot())->onBeforeCompileHead($event);

            self::assertSame([StatRecorder::SCHEME . '://site/' . ECSKCCustomisationPlugin::STYLESHEET], StatRecorder::$stats);
            self::assertSame([], $document->styleSheets);
        }

        /**
         * Before the first apply, and after a revert of the create, the target is
         * absent. PHPUnit fails on a warning or notice, so a quiet pass here also
         * means nothing was raised.
         */
        public function testAnAbsentStylesheetRegistersNothingAndRaisesNothing(): void
        {
            $this->site = TempTree::create('ecskc-plugin-site-');
            $document   = new DocumentDouble('html');
            $event      = new BeforeCompileHeadEvent(new ApplicationDouble('site'), $document);

            self::plugin($this->site->root())->onBeforeCompileHead($event);

            self::assertSame([], $document->styleSheets);
        }

        /**
         * A directory at the target path is not a stylesheet either.
         */
        public function testADirectoryAtTheTargetPathRegistersNothing(): void
        {
            $this->site = TempTree::create('ecskc-plugin-site-');
            \mkdir($this->site->path(ECSKCCustomisationPlugin::STYLESHEET), 0755, true);
            $document = new DocumentDouble('html');
            $event    = new BeforeCompileHeadEvent(new ApplicationDouble('site'), $document);

            self::plugin($this->site->root())->onBeforeCompileHead($event);

            self::assertSame([], $document->styleSheets);
        }

        /**
         * @return array<string, array{string}>
         */
        public static function nonHtmlDocuments(): array
        {
            return [
                'raw'   => ['raw'],
                'json'  => ['json'],
                'feed'  => ['feed'],
                'error' => ['error'],
            ];
        }

        #[DataProvider('nonHtmlDocuments')]
        public function testANonHtmlDocumentGetsNothing(string $type): void
        {
            $document = new DocumentDouble($type);
            $event    = new BeforeCompileHeadEvent(new ApplicationDouble('site'), $document);

            self::plugin($this->siteWithStylesheet())->onBeforeCompileHead($event);

            self::assertSame([], $document->styleSheets);
        }

        public function testItSubscribesOnlyToOnBeforeCompileHead(): void
        {
            $events = ECSKCCustomisationPlugin::getSubscribedEvents();

            self::assertSame(['onBeforeCompileHead' => 'onBeforeCompileHead'], $events);
            self::assertTrue(\method_exists(ECSKCCustomisationPlugin::class, $events['onBeforeCompileHead']));
        }

        public function testThePluginRowReachesTheParentConstructor(): void
        {
            $config = ['name' => 'ecskccustom', 'type' => 'system', 'params' => '{}'];

            self::assertSame($config, (new ECSKCCustomisationPlugin($config, '/unused'))->config);
        }
    }

    final class ApplicationDouble
    {
        public function __construct(private readonly string $client)
        {
        }

        public function isClient($identifier): bool
        {
            return $identifier === $this->client;
        }
    }

    final class DocumentDouble
    {
        /** @var list<array{string, array<string, mixed>, array<string, mixed>}> */
        public array $styleSheets = [];

        public function __construct(private readonly string $type)
        {
        }

        public function getType(): string
        {
            return $this->type;
        }

        public function addStyleSheet($url, $options = [], $attribs = []): self
        {
            $this->styleSheets[] = [$url, $options, $attribs];

            return $this;
        }
    }

    /**
     * A stream wrapper that records every stat and reports each path as absent.
     */
    final class StatRecorder
    {
        public const SCHEME = 'ecskcstat';

        /** @var list<string> */
        public static array $stats = [];

        /** @var resource|null */
        public $context;

        public function url_stat(string $path, int $flags): array|false
        {
            self::$stats[] = $path;

            return false;
        }
    }
}
