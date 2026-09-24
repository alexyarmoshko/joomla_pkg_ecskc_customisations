<?php

declare(strict_types=1);

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
}

namespace Joomla\CMS\MVC\Controller {
    /**
     * The parts of Joomla's BaseController the write tasks use.
     *
     * checkToken() follows Session::checkToken() on Joomla 5.4: the token is the
     * name of a request field whose value is truthy, and the X-CSRF-Token header is
     * accepted before the request method is looked at.
     */
    class BaseController
    {
        public const TOKEN = 'a0b1c2d3e4f5a0b1c2d3e4f5a0b1c2d3';

        public ?string $redirect = null;

        public ?string $message = null;

        public ?string $messageType = null;

        public function __construct(protected $app, protected $input)
        {
        }

        public function checkToken($method = 'post', $redirect = true)
        {
            if (($this->input->server['HTTP_X_CSRF_TOKEN'] ?? '') === self::TOKEN) {
                return true;
            }

            return (bool) $this->input->$method->get(self::TOKEN, '', 'alnum');
        }

        public function setRedirect($url, $msg = null, $type = null)
        {
            $this->redirect    = $url;
            $this->message     = $msg;
            $this->messageType = $type;

            return $this;
        }
    }
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Controller {

    use Joomla\CMS\MVC\Controller\BaseController;
    use Joomla\CMS\User\User;
    use PHPUnit\Framework\AssertionFailedError;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use ReflectionMethod;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Access\WritePermission;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Controller\TargetsController;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalChecks;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\ReportedValue;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Core\WriteRunnerInterface;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Model\RunRequest;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Model\WriteResult;

    /**
     * One request source, read the way Joomla\Input\Input::get() reads it under `raw`.
     */
    final class Source
    {
        /**
         * @param array<string, mixed> $values
         */
        public function __construct(private readonly array $values)
        {
        }

        public function get($name, $default = null, $filter = 'cmd')
        {
            return $this->values[$name] ?? $default;
        }
    }

    final class Input
    {
        public Source $post;

        /**
         * @param array<string, mixed>  $query
         * @param array<string, mixed>  $post
         * @param array<string, string> $server
         */
        public function __construct(
            private readonly string $method,
            private readonly array $query,
            array $post,
            public readonly array $server = [],
        ) {
            $this->post = new Source($post);
        }

        public function getMethod(): string
        {
            return $this->method;
        }

        /**
         * The request as a whole, as Input reads $_REQUEST: POST over the query.
         */
        public function get($name, $default = null, $filter = 'cmd')
        {
            return $this->post->get($name, $this->query[$name] ?? $default, $filter);
        }
    }

    final class Application
    {
        /** @var array<string, mixed> */
        public array $userState = [];

        public function __construct(private readonly ?User $user)
        {
        }

        public function getIdentity(): ?User
        {
            return $this->user;
        }

        public function setUserState($key, $value)
        {
            $this->userState[$key] = $value;
        }
    }

    /**
     * Records each write, and fails the test at the first one when told to.
     */
    final class RunnerDouble implements WriteRunnerInterface
    {
        /** @var list<array{string, string|null}> */
        public array $calls = [];

        public function __construct(private readonly bool $forbidden, private readonly RunReport $report)
        {
        }

        public function apply(?string $targetPath = null): RunReport
        {
            return $this->record('apply', $targetPath);
        }

        public function revert(string $targetPath): RunReport
        {
            return $this->record('revert', $targetPath);
        }

        public function redo(string $targetPath): RunReport
        {
            return $this->record('redo', $targetPath);
        }

        public function freeze(string $targetPath): RunReport
        {
            return $this->record('freeze', $targetPath);
        }

        public function unfreeze(string $targetPath): RunReport
        {
            return $this->record('unfreeze', $targetPath);
        }

        private function record(string $method, ?string $targetPath): RunReport
        {
            if ($this->forbidden) {
                throw new AssertionFailedError(\sprintf('The runner was called: %s(%s).', $method, \var_export($targetPath, true)));
            }

            $this->calls[] = [$method, $targetPath];

            return $this->report;
        }
    }

    /**
     * The real controller, with the runner it would take from the booted component
     * replaced by the double.
     */
    final class Controller extends TargetsController
    {
        public function __construct($app, $input, private readonly RunnerDouble $double)
        {
            parent::__construct($app, $input);
        }

        protected function runner(): WriteRunnerInterface
        {
            return $this->double;
        }
    }

    #[CoversClass(TargetsController::class)]
    #[CoversClass(WritePermission::class)]
    final class TargetsControllerTest extends TestCase
    {
        private const PATH   = 'templates/et_tennis/css/vendor.css';
        private const SCRIPT = 'templates/et_tennis/css/vendor.css<script>';
        private const VIEW   = 'index.php?option=com_ecskccustom&view=targets';

        private const WRITER = ['core.manage', 'ecskccustom.write'];
        private const READER = ['core.manage'];

        private const TASKS = ['applyAll', 'apply', 'revert', 'redo', 'freeze', 'unfreeze'];

        private Application $app;

        private RunnerDouble $runner;

        /**
         * @param array<string, mixed>  $query
         * @param array<string, mixed>  $post
         * @param array<string, string> $server
         * @param list<string>          $granted
         */
        private function controller(
            string $method = 'POST',
            array $query = [],
            array $post = [],
            array $granted = self::WRITER,
            bool $forbidden = false,
            array $server = [],
        ): Controller {
            $this->app    = new Application(new User($granted));
            $this->runner = new RunnerDouble(
                $forbidden,
                RunReport::of([], OperationalChecks::none(), ReportedValue::none('no earlier write run was interrupted'), ['a fault'])
            );

            return new Controller($this->app, new Input($method, $query, $post, $server), $this->runner);
        }

        /**
         * A POST body that passes every check for the task, with the token.
         *
         * @return array<string, mixed>
         */
        private static function validPost(string $task, mixed $path = self::PATH): array
        {
            $post = [BaseController::TOKEN => '1'];

            if ($task === 'applyAll') {
                $post[WriteResult::CONFIRM_FIELD] = '1';
            } else {
                $post['path'] = $path;
            }

            return $post;
        }

        /**
         * @return iterable<string, array{string}>
         */
        public static function tasks(): iterable
        {
            foreach (self::TASKS as $task) {
                yield $task => [$task];
            }
        }

        private function assertRefused(Controller $controller, string $message, string $url = self::VIEW): void
        {
            self::assertSame($url, $controller->redirect);
            self::assertSame($message, $controller->message);
            self::assertSame([], $this->app->userState, 'A refused write stores no result.');
        }

        public function testTheTasksAreTheSixWritesAndNothingElse(): void
        {
            $declared = [];

            foreach ((new ReflectionClass(TargetsController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === TargetsController::class) {
                    $declared[] = $method->getName();
                }
            }

            self::assertEqualsCanonicalizing(self::TASKS, $declared);
            self::assertEqualsCanonicalizing(
                WriteResult::OPERATIONS,
                \array_map('strtolower', self::TASKS),
                'Joomla lowercases a task, so each result operation is its task name.'
            );
        }

        #[DataProvider('tasks')]
        public function testAMissingTokenMakesNoRunnerCall(string $task): void
        {
            $post = self::validPost($task);
            unset($post[BaseController::TOKEN]);

            $controller = $this->controller(post: $post, forbidden: true);
            $controller->$task();

            $this->assertRefused($controller, 'COM_ECSKCCUSTOM_WRITE_REFUSED_TOKEN');
        }

        #[DataProvider('tasks')]
        public function testABadTokenMakesNoRunnerCall(string $task): void
        {
            $post = self::validPost($task);
            unset($post[BaseController::TOKEN]);
            $post['ffffffffffffffffffffffffffffffff'] = '1';

            $controller = $this->controller(post: $post, forbidden: true);
            $controller->$task();

            $this->assertRefused($controller, 'COM_ECSKCCUSTOM_WRITE_REFUSED_TOKEN');
        }

        #[DataProvider('tasks')]
        public function testAValidTokenWithoutTheWritePermissionMakesNoRunnerCall(string $task): void
        {
            $controller = $this->controller(post: self::validPost($task), granted: self::READER, forbidden: true);
            $controller->$task();

            $this->assertRefused($controller, 'COM_ECSKCCUSTOM_WRITE_REFUSED_PERMISSION');
        }

        #[DataProvider('tasks')]
        public function testTheTokenIsCheckedBeforeThePermissionIsAsked(string $task): void
        {
            $user       = new User(self::READER);
            $this->app  = new Application($user);
            $controller = new Controller(
                $this->app,
                new Input('POST', [], ['path' => self::PATH]),
                new RunnerDouble(true, RunReport::faulted([], OperationalChecks::none(), ReportedValue::none('x')))
            );

            $controller->$task();

            self::assertSame([], $user->asked);
            self::assertSame('COM_ECSKCCUSTOM_WRITE_REFUSED_TOKEN', $controller->message);
        }

        /**
         * A GET with every field in the query string, and one that also carries the
         * token in the header Session::checkToken() accepts on any method.
         */
        #[DataProvider('tasks')]
        public function testAGetMakesNoRunnerCall(string $task): void
        {
            $get = $this->controller('GET', self::validPost($task), [], forbidden: true);
            $get->$task();
            $this->assertRefused($get, 'COM_ECSKCCUSTOM_WRITE_REFUSED_TOKEN');

            $header = $this->controller(
                'GET',
                self::validPost($task),
                [],
                forbidden: true,
                server: ['HTTP_X_CSRF_TOKEN' => BaseController::TOKEN]
            );
            $header->$task();
            $this->assertRefused($header, 'COM_ECSKCCUSTOM_WRITE_REFUSED_TOKEN');
        }

        /**
         * @return iterable<string, array{string, string, string|null}>
         */
        public static function expectedCalls(): iterable
        {
            yield 'applyAll' => ['applyAll', 'apply', null];
            yield 'apply' => ['apply', 'apply', self::SCRIPT];
            yield 'revert' => ['revert', 'revert', self::SCRIPT];
            yield 'redo' => ['redo', 'redo', self::SCRIPT];
            yield 'freeze' => ['freeze', 'freeze', self::SCRIPT];
            yield 'unfreeze' => ['unfreeze', 'unfreeze', self::SCRIPT];
        }

        /**
         * The path is one the raw filter keeps and every other filter rewrites to
         * the declared `vendor.css` (docs/phase_3_tickets_review.v1.md finding 1).
         */
        #[DataProvider('expectedCalls')]
        public function testAnAllowedTaskMakesExactlyItsRunnerCallWithThePathAsSent(string $task, string $method, ?string $path): void
        {
            $controller = $this->controller(post: self::validPost($task, self::SCRIPT));
            $controller->$task();

            self::assertSame([[$method, $path]], $this->runner->calls);
            self::assertSame(self::VIEW, $controller->redirect);
            self::assertNull($controller->message);

            $stored = WriteResult::fromArray($this->app->userState[WriteResult::STATE_KEY] ?? null);
            self::assertNotNull($stored);
            self::assertSame(\strtolower($task), $stored->operation);
            self::assertSame($path, $stored->path);
            self::assertSame(['a fault'], $stored->faults);
        }

        /**
         * @return iterable<string, array{string, mixed}>
         */
        public static function singlePathsWithoutAPath(): iterable
        {
            foreach (['apply', 'revert', 'redo', 'freeze', 'unfreeze'] as $task) {
                yield $task . ', missing' => [$task, null];
                yield $task . ', array' => [$task, [self::PATH]];
            }
        }

        #[DataProvider('singlePathsWithoutAPath')]
        public function testASinglePathTaskWithoutAStringPathMakesNoRunnerCall(string $task, mixed $path): void
        {
            $post = self::validPost($task, $path);

            if ($path === null) {
                unset($post['path']);
            }

            // Confirmed as a whole-set apply would be, so a fall-through would run.
            $post[WriteResult::CONFIRM_FIELD] = '1';

            $controller = $this->controller(post: $post, forbidden: true);
            $controller->$task();

            $this->assertRefused($controller, 'COM_ECSKCCUSTOM_WRITE_REFUSED_PATH');
        }

        public function testAWholeSetApplyWithoutTheConfirmationGoesBackToTheDryRun(): void
        {
            $dryRun = RunRequest::displayUrl(RunRequest::DRY_RUN);

            foreach (
                [
                    'no field'        => [[], []],
                    'another value'   => [[], [WriteResult::CONFIRM_FIELD => 'yes']],
                    'in the URL only' => [[WriteResult::CONFIRM_FIELD => '1'], []],
                ] as $case => [$query, $extra]
            ) {
                $controller = $this->controller(query: $query, post: [BaseController::TOKEN => '1'] + $extra, forbidden: true);
                $controller->applyAll();

                $this->assertRefused($controller, 'COM_ECSKCCUSTOM_WRITE_REFUSED_UNCONFIRMED', $dryRun);
                self::assertSame('warning', $controller->messageType, $case);
            }
        }

        public function testAWholeSetApplyIgnoresAPath(): void
        {
            $post = self::validPost('applyAll') + ['path' => self::PATH];

            $this->controller(post: $post)->applyAll();

            self::assertSame([['apply', null]], $this->runner->calls);
        }
    }
}
