<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Controller;

use Closure;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\User\User;
use UnexpectedValueException;
use YakShaver\Component\ECSKCCustomisation\Administrator\Access\WritePermission;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\WriteRunnerInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Extension\ECSKCCustomisationComponent;
use YakShaver\Component\ECSKCCustomisation\Administrator\Model\RunRequest;
use YakShaver\Component\ECSKCCustomisation\Administrator\Model\WriteResult;

\defined('_JEXEC') or die;

/**
 * The six write tasks. Each one is a POST with a valid token from a user holding
 * `ecskccustom.write`, checked in that order before the path or the runner is read,
 * and each makes exactly one runner call.
 *
 * The result goes into the session and the browser is redirected to the targets
 * view, which shows it once. A reload then runs a fresh check rather than a second
 * revert (post, redirect, get).
 */
class TargetsController extends BaseController
{
    private const VIEW_URL = 'index.php?option=com_ecskccustom&view=targets';

    /**
     * Apply the whole set, from the dry run's "Apply this plan" button (plan decision 49).
     */
    public function applyAll(): void
    {
        if (!$this->mayWrite()) {
            return;
        }

        if ($this->input->post->get(WriteResult::CONFIRM_FIELD, null, 'raw') !== '1') {
            $this->setRedirect(
                Route::_(RunRequest::displayUrl(RunRequest::DRY_RUN), false),
                Text::_('COM_ECSKCCUSTOM_WRITE_REFUSED_UNCONFIRMED'),
                'warning'
            );

            return;
        }

        $this->finish(WriteResult::APPLY_ALL, null, $this->runner()->apply(null));
    }

    public function apply(): void
    {
        $this->writePath(
            WriteResult::APPLY,
            static fn (WriteRunnerInterface $runner, string $path): RunReport => $runner->apply($path)
        );
    }

    public function revert(): void
    {
        $this->writePath(
            WriteResult::REVERT,
            static fn (WriteRunnerInterface $runner, string $path): RunReport => $runner->revert($path)
        );
    }

    public function redo(): void
    {
        $this->writePath(
            WriteResult::REDO,
            static fn (WriteRunnerInterface $runner, string $path): RunReport => $runner->redo($path)
        );
    }

    public function freeze(): void
    {
        $this->writePath(
            WriteResult::FREEZE,
            static fn (WriteRunnerInterface $runner, string $path): RunReport => $runner->freeze($path)
        );
    }

    public function unfreeze(): void
    {
        $this->writePath(
            WriteResult::UNFREEZE,
            static fn (WriteRunnerInterface $runner, string $path): RunReport => $runner->unfreeze($path)
        );
    }

    /**
     * The runner, from the booted component, as the model reaches it.
     */
    protected function runner(): WriteRunnerInterface
    {
        $component = $this->app->bootComponent('com_ecskccustom');

        if (!$component instanceof ECSKCCustomisationComponent) {
            throw new UnexpectedValueException('com_ecskccustom did not boot as ECSKCCustomisationComponent.');
        }

        return $component->runner();
    }

    /**
     * One path's write. A missing or non-string path is refused here: it never
     * becomes the whole-set apply.
     *
     * @param Closure(WriteRunnerInterface, string): RunReport $call
     */
    private function writePath(string $operation, Closure $call): void
    {
        if (!$this->mayWrite()) {
            return;
        }

        // `raw`, as for the read-only runs: every other filter rewrites the value,
        // and a rewritten undeclared path can come out as a declared one.
        $path = $this->input->get('path', null, 'raw');

        if (!\is_string($path)) {
            $this->setRedirect(
                Route::_(self::VIEW_URL, false),
                Text::_('COM_ECSKCCUSTOM_WRITE_REFUSED_PATH'),
                'error'
            );

            return;
        }

        $this->finish($operation, $path, $call($this->runner(), $path));
    }

    /**
     * POST, then the token, then `ecskccustom.write`. A refusal redirects back to
     * the view with its message, and the caller makes no runner call.
     */
    private function mayWrite(): bool
    {
        // A GET is refused like a missing token. checkToken() alone would accept a
        // GET that carries the token in the X-CSRF-Token header.
        if ($this->input->getMethod() !== 'POST' || !$this->checkToken('post', false)) {
            $this->setRedirect(Route::_(self::VIEW_URL, false), Text::_('COM_ECSKCCUSTOM_WRITE_REFUSED_TOKEN'), 'error');

            return false;
        }

        $user = $this->app->getIdentity();

        if (!$user instanceof User || !WritePermission::allows($user)) {
            $this->setRedirect(Route::_(self::VIEW_URL, false), Text::_('COM_ECSKCCUSTOM_WRITE_REFUSED_PERMISSION'), 'error');

            return false;
        }

        return true;
    }

    private function finish(string $operation, ?string $path, RunReport $report): void
    {
        $this->app->setUserState(WriteResult::STATE_KEY, WriteResult::fromReport($operation, $path, $report)->toArray());
        $this->setRedirect(Route::_(self::VIEW_URL, false));
    }
}
