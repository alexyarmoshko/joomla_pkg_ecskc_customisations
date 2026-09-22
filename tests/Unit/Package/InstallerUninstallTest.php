<?php

declare(strict_types=1);

namespace Joomla\CMS {
    final class Factory
    {
        public static mixed $application = null;

        public static function getApplication(): object
        {
            if (self::$application === null) {
                throw new \RuntimeException('No application');
            }

            return self::$application;
        }
    }
}

namespace Joomla\CMS\Language {
    final class Text
    {
        public static bool $fail = false;

        public static function sprintf(string $string, mixed ...$args): string
        {
            if (self::$fail) {
                throw new \ValueError('Unknown format specifier');
            }

            return \sprintf($string, ...$args);
        }

        public static function _(string $string): string
        {
            if (self::$fail) {
                throw new \ValueError('Unknown format specifier');
            }

            return $string;
        }
    }
}

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
    if (!\defined('_JEXEC')) {
        \define('_JEXEC', 1);
    }

    require_once \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/script.php';
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Package {

    use Com_EcskccustomInstallerScript;
    use Joomla\CMS\Factory;
    use Joomla\CMS\Installer\InstallerAdapter;
    use Joomla\CMS\Language\Text;
    use Joomla\CMS\Log\Log;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\StateArchive;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\StateArchiveFailure;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunLock;
    use YakShaver\ECSKCCustomisation\Tests\Support\RunLockProbe;
    use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

    /**
     * The uninstall hook must not throw, recreate folders after Joomla has deleted
     * them, or leave an empty archive directory when there was nothing to save.
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
            Log::$messages         = [];
            Factory::$application  = null;

            \class_exists(PackagePaths::class);
            \class_exists(StateArchive::class);
            \class_exists(StateArchiveFailure::class);
            \class_exists(RunLock::class);

            $this->forgetSiteWrites();
        }

        protected function tearDown(): void
        {
            $this->forgetSiteWrites();
            Factory::$application = null;
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
         *
         * The tree is the one an install leaves: the three created folders with
         * their guards and nothing else. The archive takes `run.lock` inside
         * `state/` before it walks, decision 36, so a fresh install is a tree with
         * those folders, not a tree with none.
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
         * Decision 37: the destination is requested only once history is found, so a
         * `tmp_path` lookup that cannot run at all is never reached on this path.
         *
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
         * Decision 34: the lock comes before the walk.
         *
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
