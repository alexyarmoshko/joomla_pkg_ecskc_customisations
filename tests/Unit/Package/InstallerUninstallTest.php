<?php

declare(strict_types=1);

namespace Joomla\CMS\Log {
    final class Log
    {
        public const ALL = 30719;
        public const ERROR = 8;

        /** @var list<string> */
        public static array $messages = [];

        public static function addLogger(array $options, int $level, array $categories): void
        {
        }

        public static function add(string $entry, int $level, string $category): void
        {
            self::$messages[] = $entry;
        }
    }
}

namespace Joomla\CMS\Installer {
    class InstallerAdapter
    {
    }
}

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
    require_once \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/script.php';
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Package {

    use Com_EcskccustomInstallerScript;
    use Joomla\CMS\Factory;
    use Joomla\CMS\Installer\InstallerAdapter;
    use Joomla\CMS\Language\Text;
    use Joomla\CMS\Log\Log;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\StateArchive;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\StateArchiveFailure;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunLock;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Model\RunRequest;
    use YakShaver\ECSKCCustomisation\Tests\Support\RunLockProbe;
    use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

    /**
     * The uninstall hook must not throw, recreate folders after Joomla has deleted
     * them, or leave an empty archive directory when there was nothing to save.
     *
     * Install and update send one message, and only after the state folders are
     * protected.
     */
    #[CoversClass(Com_EcskccustomInstallerScript::class)]
    final class InstallerUninstallTest extends TestCase
    {
        private static TempTree $site;

        public static function setUpBeforeClass(): void
        {
            self::$site = TempTree::create('ecskc-installer-site-');

            if (!\defined('JPATH_ROOT')) {
                \define('JPATH_ROOT', self::$site->root());
            }

            if (!\defined('JPATH_ADMINISTRATOR')) {
                \define('JPATH_ADMINISTRATOR', self::$site->path('administrator'));
            }

            if (!\is_dir(JPATH_ADMINISTRATOR)) {
                \mkdir(JPATH_ADMINISTRATOR, 0755, true);
            }
        }

        public static function tearDownAfterClass(): void
        {
            self::$site->remove();
        }

        protected function setUp(): void
        {
            Text::$fail            = false;
            Text::$strings         = [];
            Log::$messages         = [];
            Factory::$application  = null;

            \class_exists(PackagePaths::class);
            \class_exists(StateArchive::class);
            \class_exists(StateArchiveFailure::class);
            \class_exists(RunLock::class);
            \class_exists(RunRequest::class);

            $this->forgetSiteWrites();
        }

        protected function tearDown(): void
        {
            $this->forgetSiteWrites();
            Factory::$application = null;
            Text::$strings        = [];
        }

        /**
         * @return array<string, array{string}>
         */
        public static function installRoutes(): array
        {
            return ['install' => ['install'], 'update' => ['update']];
        }

        /**
         * One message, linking to the read-only whole-set check and to no write task.
         */
        #[DataProvider('installRoutes')]
        public function testPostflightSendsOneMessageLinkingToTheWholeSetCheck(string $type): void
        {
            $this->shippedFolders();
            Text::$strings['COM_ECSKCCUSTOM_INSTALL_REVIEW_AND_APPLY'] = 'link:%s';

            $app    = $this->application(self::$site->path('tmp'));
            $script = new Com_EcskccustomInstallerScript();

            self::assertTrue($script->postflight($type, new InstallerAdapter()));
            self::assertSame(
                [[
                    'message' => 'link:index.php?option=com_ecskccustom&amp;view=targets&amp;run=check',
                    'type'    => 'notice',
                ]],
                $app->messages
            );
        }

        public function testPostflightOnUninstallSendsNoMessage(): void
        {
            $app    = $this->application(self::$site->path('tmp'));
            $script = new Com_EcskccustomInstallerScript();

            self::assertTrue($script->postflight('uninstall', new InstallerAdapter()));
            self::assertSame([], $app->messages);
        }

        /**
         * A folder that cannot be protected fails the install, and the operator is
         * not sent to review a component with no guarded state folders.
         */
        public function testAFolderFailureThrowsAndSendsNoMessage(): void
        {
            // payload/ ships with the package; its absence is the build fault
            // postflight refuses to paper over.
            \mkdir(JPATH_ADMINISTRATOR . '/components/com_ecskccustom/rules', 0755, true);

            $app    = $this->application(self::$site->path('tmp'));
            $script = new Com_EcskccustomInstallerScript();

            try {
                $script->postflight('install', new InstallerAdapter());
                self::fail('A missing payload/ folder must fail the install.');
            } catch (\RuntimeException $failure) {
                self::assertSame('COM_ECSKCCUSTOM_ERROR_STATE_PROTECTION', $failure->getMessage());
            }

            self::assertSame([], $app->messages);
        }

        /**
         * An update leaves every state file byte for byte as it found it, guards
         * included, and still sends its one message.
         */
        public function testAnUpdateKeepsTheStateFoldersByteForByte(): void
        {
            $this->shippedFolders();
            $script = new Com_EcskccustomInstallerScript();
            $this->application(self::$site->path('tmp'));
            self::assertTrue($script->postflight('install', new InstallerAdapter()));

            $state = 'administrator/components/com_ecskccustom/';
            self::$site->write($state . 'backup/templates/et_tennis/css/vendor.css/1.bak', "old\r\nbytes");
            self::$site->write($state . 'state/.frozen/marker', 'frozen');
            self::$site->write($state . 'state/events.log', "{\"event\":\"committed\"}\n");

            $before = $this->hashesUnder(JPATH_ADMINISTRATOR . '/components/com_ecskccustom');
            $app    = $this->application(self::$site->path('tmp'));

            self::assertTrue($script->postflight('update', new InstallerAdapter()));
            self::assertSame($before, $this->hashesUnder(JPATH_ADMINISTRATOR . '/components/com_ecskccustom'));
            self::assertCount(1, $app->messages);

            foreach (PackagePaths::ALL_FOLDERS as $folder) {
                self::assertFileExists(
                    JPATH_ADMINISTRATOR . '/components/com_ecskccustom/' . $folder . '/' . PackagePaths::HTACCESS
                );
            }
        }

        /**
         * The shipped string asks for an apply and carries the link. A key present
         * but without its %s would drop the link silently.
         */
        public function testTheInstallMessageAsksForAnApplyAndCarriesTheLink(): void
        {
            $file = \dirname(__DIR__, 3)
                . '/extensions/com_ecskccustom/admin/language/en-GB/com_ecskccustom.sys.ini';
            $contents = (string) \file_get_contents($file);

            self::assertSame(
                1,
                \preg_match('/^COM_ECSKCCUSTOM_INSTALL_REVIEW_AND_APPLY="(.*)"$/m', $contents, $match)
            );
            self::assertStringContainsString('apply', $match[1]);
            self::assertSame(1, \substr_count($match[1], '<a href=\\"%s\\">'));
        }

        /**
         * The folders the package ships, which postflight protects but does not create.
         */
        private function shippedFolders(): void
        {
            foreach (\array_diff(PackagePaths::ALL_FOLDERS, PackagePaths::CREATED_FOLDERS) as $folder) {
                \mkdir(JPATH_ADMINISTRATOR . '/components/com_ecskccustom/' . $folder, 0755, true);
            }
        }

        /**
         * @return array<string, string> sha256 of every file under $root, by relative path.
         */
        private function hashesUnder(string $root): array
        {
            $hashes = [];
            $files  = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                $hashes[\substr($file->getPathname(), \strlen($root) + 1)] = \hash_file('sha256', $file->getPathname());
            }

            \ksort($hashes);

            return $hashes;
        }

        public function testABrokenTranslationDoesNotEscapeTheUninstallMessageHelper(): void
        {
            Text::$fail = true;

            $method = new ReflectionMethod(Com_EcskccustomInstallerScript::class, 'enqueue');
            $script = new Com_EcskccustomInstallerScript();

            $method->invoke(
                $script,
                'COM_ECSKCCUSTOM_UNINSTALL_ARCHIVE_UNWRITABLE',
                'error',
                '/tmp/com_ecskccustom-state'
            );

            self::assertNotEmpty(
                Log::$messages,
                'A translation failure must be logged rather than thrown.'
            );
        }

        /**
         * Joomla runs postflight after uninstall has deleted the tree. Recreating
         * the folders then threw on the missing payload/ and told the operator
         * the extension was not removed.
         */
        public function testPostflightOnUninstallCreatesNoDirectory(): void
        {
            $component = JPATH_ADMINISTRATOR . '/components/com_ecskccustom';
            self::assertDirectoryDoesNotExist($component);

            $script = new Com_EcskccustomInstallerScript();
            $result = $script->postflight('uninstall', new InstallerAdapter());

            self::assertTrue($result);
            self::assertDirectoryDoesNotExist($component);
        }

        /**
         * A component that never wrote history must not leave a guarded empty
         * folder in the site's temporary path.
         */
        public function testUninstallWithNoHistoryCreatesNoArchiveFolder(): void
        {
            $this->freshInstall();

            $tmp = self::$site->path('tmp');
            \mkdir($tmp, 0755, true);

            $app    = $this->application($tmp);
            $script = new Com_EcskccustomInstallerScript();
            $result = $script->uninstall(new InstallerAdapter());

            self::assertTrue($result);
            self::assertSame([], $app->messages);
            self::assertDirectoryDoesNotExist($tmp . '/com_ecskccustom-state');
            self::assertTrue(
                $this->lockIsFree(),
                'The archive releases the flock before it returns.'
            );
        }

        /**
         * An implementation that resolved the destination first would raise here,
         * and the operator of a component that never wrote anything would be told
         * its history could not be saved.
         */
        public function testUninstallWithNoHistoryNeverReachesAnUnavailableTmpPath(): void
        {
            $this->freshInstall();

            Factory::$application = null;

            $script = new Com_EcskccustomInstallerScript();

            self::assertTrue($script->uninstall(new InstallerAdapter()));
            self::assertSame([], Log::$messages, 'No failure is reported, so none is logged.');
            self::assertDirectoryDoesNotExist(self::$site->root() . '/tmp');
        }

        /**
         * A hook that called `contents()` first would see only folder guards while a
         * write run held the lock without having published its marker yet, return
         * without trying the lock, and let Joomla delete the tree under that writer.
         * So this tree is guard-only on purpose: an unlocked walk would report
         * nothing to do, and the run-locked message is what proves the lock was
         * tried first.
         */
        public function testUninstallReportsAContendedRunLockRatherThanWalkingUnlocked(): void
        {
            $paths = $this->freshInstall();
            $held  = new RunLock($paths);
            self::assertTrue($held->acquire());

            $tmp = self::$site->path('tmp');
            \mkdir($tmp, 0755, true);

            $app    = $this->application($tmp);
            $script = new Com_EcskccustomInstallerScript();

            try {
                $result = $script->uninstall(new InstallerAdapter());
            } finally {
                $held->release();
            }

            self::assertTrue($result, 'The uninstall is never obstructed, decision 26.');
            self::assertSame(
                [['message' => 'COM_ECSKCCUSTOM_UNINSTALL_ARCHIVE_RUN_LOCKED', 'type' => 'error']],
                $app->messages
            );
            self::assertDirectoryDoesNotExist($tmp . '/com_ecskccustom-state');
        }

        public function testUninstallReportsARunLockHeldByAReadAsAReadNotAWrite(): void
        {
            $paths = $this->freshInstall();
            $made  = new RunLock($paths);
            self::assertTrue($made->acquire());
            $made->release();

            $reading = new RunLock($paths);
            self::assertTrue($reading->observe());

            $tmp = self::$site->path('tmp');
            \mkdir($tmp, 0755, true);

            $app    = $this->application($tmp);
            $script = new Com_EcskccustomInstallerScript();

            try {
                $result = $script->uninstall(new InstallerAdapter());
            } finally {
                $reading->release();
            }

            self::assertTrue($result, 'The uninstall is never obstructed, decision 26.');
            self::assertSame(
                [['message' => 'COM_ECSKCCUSTOM_UNINSTALL_ARCHIVE_REPORT_READING', 'type' => 'error']],
                $app->messages
            );
            self::assertDirectoryDoesNotExist($tmp . '/com_ecskccustom-state');
        }

        public function testUninstallReportsARunLockThatCannotBeOpenedAsUnavailable(): void
        {
            $paths = $this->freshInstall();
            \mkdir($paths->lock());

            $tmp = self::$site->path('tmp');
            \mkdir($tmp, 0755, true);

            $app    = $this->application($tmp);
            $script = new Com_EcskccustomInstallerScript();

            self::assertTrue($script->uninstall(new InstallerAdapter()));
            self::assertSame(
                [['message' => 'COM_ECSKCCUSTOM_UNINSTALL_ARCHIVE_LOCK_UNAVAILABLE', 'type' => 'error']],
                $app->messages
            );
            self::assertDirectoryDoesNotExist($tmp . '/com_ecskccustom-state');
        }

        /**
         * The tree an install leaves behind, and the paths that address it.
         */
        private function freshInstall(): PackagePaths
        {
            $component = JPATH_ADMINISTRATOR . '/components/com_ecskccustom';

            foreach (PackagePaths::CREATED_FOLDERS as $folder) {
                $directory = $component . '/' . $folder;
                \mkdir($directory, 0755, true);
                \file_put_contents($directory . '/' . PackagePaths::HTACCESS, 'Deny from all');
                \file_put_contents($directory . '/' . PackagePaths::FOLDER_INDEX, '<html />');
            }

            return new PackagePaths($component);
        }

        /**
         * Can a fresh handle take the lock exclusively? Proof the archive released it.
         */
        private function lockIsFree(): bool
        {
            return RunLockProbe::isFree(
                new PackagePaths(JPATH_ADMINISTRATOR . '/components/com_ecskccustom')
            );
        }

        /**
         * archiveFolder() refuses a destination inside the component before it
         * creates or protects anything there. The hook still returns true.
         */
        public function testUninstallRefusesAnArchiveFolderInsideTheComponentTree(): void
        {
            self::$site->write(
                'administrator/components/com_ecskccustom/state/events.log',
                'kept'
            );

            $component = JPATH_ADMINISTRATOR . '/components/com_ecskccustom';
            $inside    = $component . '/tmp';
            $app       = $this->application($inside);
            $script    = new Com_EcskccustomInstallerScript();
            $result    = $script->uninstall(new InstallerAdapter());

            self::assertTrue($result);
            self::assertSame(
                [['message' => 'COM_ECSKCCUSTOM_UNINSTALL_ARCHIVE_UNWRITABLE', 'type' => 'error']],
                $app->messages
            );
            self::assertDirectoryDoesNotExist($inside);
        }

        /**
         * A link with the archive folder's name is not that folder. protect()
         * must not write a deny-all guard over whatever the link points at.
         */
        public function testUninstallRefusesAnArchiveFolderThatIsASymbolicLink(): void
        {
            self::$site->write(
                'administrator/components/com_ecskccustom/state/events.log',
                'kept'
            );
            self::$site->write('elsewhere/.htaccess', "someone else's rules");

            $tmp = self::$site->path('tmp');
            \mkdir($tmp, 0755, true);
            self::$site->symlink('tmp/com_ecskccustom-state', self::$site->path('elsewhere'));

            $app    = $this->application($tmp);
            $script = new Com_EcskccustomInstallerScript();
            $result = $script->uninstall(new InstallerAdapter());

            self::assertTrue($result);
            self::assertSame(
                [['message' => 'COM_ECSKCCUSTOM_UNINSTALL_ARCHIVE_UNWRITABLE', 'type' => 'error']],
                $app->messages
            );
            self::assertSame("someone else's rules", self::$site->read('elsewhere/.htaccess'));
            self::assertSame(
                [self::$site->path('elsewhere') . '/.htaccess'],
                self::everythingIn(self::$site->path('elsewhere'))
            );
        }

        /**
         * @return object{messages: list<array{message: string, type: string}>, tmpPath: string}
         */
        private function application(string $tmpPath): object
        {
            $app = new class ($tmpPath) {
                /** @var list<array{message: string, type: string}> */
                public array $messages = [];

                public function __construct(public string $tmpPath)
                {
                }

                public function get(string $name, mixed $default = null): mixed
                {
                    return $name === 'tmp_path' ? $this->tmpPath : $default;
                }

                public function enqueueMessage(string $message, string $type = 'message'): void
                {
                    $this->messages[] = ['message' => $message, 'type' => $type];
                }
            };

            Factory::$application = $app;

            return $app;
        }

        /**
         * @return list<string>
         */
        private static function everythingIn(string $directory): array
        {
            $names = \array_values(\array_diff(\scandir($directory) ?: [], ['.', '..']));

            return \array_map(static fn (string $name): string => $directory . '/' . $name, $names);
        }

        private function forgetSiteWrites(): void
        {
            foreach (['administrator/components', 'tmp', 'elsewhere'] as $relative) {
                $path = self::$site->root() . '/' . $relative;

                if (\is_link($path) || \is_file($path)) {
                    \unlink($path);
                    continue;
                }

                if (\is_dir($path)) {
                    self::removeTree($path);
                }
            }
        }

        private static function removeTree(string $path): void
        {
            if (\is_link($path) || \is_file($path)) {
                \unlink($path);

                return;
            }

            if (!\is_dir($path)) {
                return;
            }

            foreach (\scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                self::removeTree($path . '/' . $entry);
            }

            \rmdir($path);
        }
    }
}
