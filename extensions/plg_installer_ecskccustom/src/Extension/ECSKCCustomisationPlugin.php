<?php

/**
 * @package     YakShaver\Plugin\Installer\ECSKCCustomisation
 * @subpackage  Installer
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Plugin\Installer\ECSKCCustomisation\Extension;

use Joomla\CMS\Event\Extension\AfterInstallEvent;
use Joomla\CMS\Event\Extension\AfterUpdateEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\Event\SubscriberInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\ReadOnlyRunnerInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;
use YakShaver\Component\ECSKCCustomisation\Administrator\Extension\ECSKCCustomisationComponent;

\defined('_JEXEC') or die;

/**
 * Checks the customisations after successful extension work, and says so once when
 * anything needs attention (plan decisions 7, 8, 22 and 51).
 *
 * It listens to the per-extension events rather than `onInstallerAfterInstaller`,
 * which only the Install view fires: an update from the Update view would go
 * unchecked. The per-extension events fire once for each child of a package and
 * once for the package itself, and a batch update fires them for every extension
 * in it, with no event after the last. So every successful event runs a fresh
 * check, and the one message this request queued is replaced by the newer one.
 * What the operator sees is the check after the last extension work.
 *
 * It takes the runner from the booted component and constructs nothing of the core,
 * decision 5. It reaches only `check()`. Nothing it does may stop the installer
 * request: every failure is logged and swallowed.
 */
final class ECSKCCustomisationPlugin extends CMSPlugin implements SubscriberInterface
{
    public const COMPONENT = 'com_ecskccustom';

    /** The component's Customisations view, decision 50. */
    public const REPORT_URL = 'index.php?option=com_ecskccustom&view=targets';

    public const MESSAGE_TYPE = 'warning';

    public const LOG_CATEGORY = 'plg_installer_ecskccustom';

    /**
     * Load the plugin's language file on construction.
     *
     * @var boolean
     */
    protected $autoloadLanguage = true;

    /**
     * The message this request queued, as the queue holds it, or null.
     *
     * @var array{message: string, type: string}|null
     */
    private ?array $queued = null;

    /** The text last shown, or null when nothing needed attention. */
    private ?string $shown = null;

    /** Whether this request registered the log file already. */
    private bool $logger = false;

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onExtensionAfterInstall' => 'onExtensionAfterInstall',
            'onExtensionAfterUpdate'  => 'onExtensionAfterUpdate',
        ];
    }

    public function onExtensionAfterInstall(AfterInstallEvent $event): void
    {
        $this->afterExtensionWork($event->getEid());
    }

    public function onExtensionAfterUpdate(AfterUpdateEvent $event): void
    {
        $this->afterExtensionWork($event->getEid());
    }

    /**
     * @param int|bool $eid The extension id, or false when the work failed.
     */
    private function afterExtensionWork(int|bool $eid): void
    {
        if ($eid === false) {
            return;
        }

        try {
            $application = $this->getApplication();

            if (!$application->isClient('administrator')) {
                return;
            }

            $runner = $this->componentRunner($application);

            if ($runner === null) {
                return;
            }

            $this->show($application, $this->message($runner->check()));
        } catch (\Throwable $e) {
            $this->log(\sprintf('The check after extension work did not run: %s', $e->getMessage()));
        }
    }

    /**
     * The component's runner, or null when the component is not there to give one.
     *
     * Joomla boots a missing component as a legacy component rather than failing, so
     * the class is what tells the two apart.
     */
    private function componentRunner(object $application): ?ReadOnlyRunnerInterface
    {
        $component = $application->bootComponent(self::COMPONENT);

        if (!$component instanceof ECSKCCustomisationComponent) {
            $this->log(\sprintf('%s is not installed, so no check ran after extension work.', self::COMPONENT));

            return null;
        }

        return $component->readOnlyRunner();
    }

    /**
     * The message for this report, or null when nothing needs attention.
     *
     * Trusted HTML: the plugin builds the `<br>` joins and the link. Its own words
     * come from its language file. Statuses, checks and faults are the runner's text,
     * shown verbatim and escaped, decision 48.
     */
    private function message(RunReport $report): ?string
    {
        if (!$report->needsAttention()) {
            return null;
        }

        $lines   = [Text::_('PLG_INSTALLER_ECSKCCUSTOM_MESSAGE_HEADING')];
        $targets = \count($report->notifiableTargets());

        if ($targets > 0) {
            $byStatus = [];

            foreach (TargetStatus::NOTIFIABLE as $status) {
                $count = $report->counts->forStatus($status);

                if ($count > 0) {
                    $byStatus[] = $count . ' ' . self::escape($status);
                }
            }

            $lines[] = Text::sprintf('PLG_INSTALLER_ECSKCCUSTOM_MESSAGE_TARGETS', $targets, \implode(', ', $byStatus));
        }

        foreach ($report->checks->notifiable() as $check) {
            $lines[] = Text::sprintf('PLG_INSTALLER_ECSKCCUSTOM_MESSAGE_CHECK', self::escape($check->reason));
        }

        foreach ($report->faults() as $fault) {
            $lines[] = Text::sprintf('PLG_INSTALLER_ECSKCCUSTOM_MESSAGE_FAULT', self::escape($fault));
        }

        $lines[] = \sprintf(
            '<a href="%s">%s</a>',
            Route::_(self::REPORT_URL),
            Text::_('PLG_INSTALLER_ECSKCCUSTOM_MESSAGE_LINK')
        );

        return \implode('<br>', $lines);
    }

    /**
     * Keep exactly one message from this plugin in the queue, the latest.
     */
    private function show(object $application, ?string $text): void
    {
        if ($text === $this->shown) {
            return;
        }

        if ($this->queued !== null) {
            $this->withdraw($application, $this->queued);
            $this->queued = null;
        }

        $this->shown = $text;

        if ($text === null) {
            return;
        }

        $before = \count($application->getMessageQueue());
        $application->enqueueMessage($text, self::MESSAGE_TYPE);
        $queue = $application->getMessageQueue();

        // Joomla drops a message already in the queue. Then nothing of ours was added,
        // and there is nothing to withdraw later.
        if (\count($queue) > $before) {
            $this->queued = \end($queue);
        }
    }

    /**
     * Take one entry out of the application's queue and put the rest back in order.
     *
     * Joomla has no call that removes one message, so the queue is cleared and refilled.
     *
     * @param array{message: string, type: string} $entry
     */
    private function withdraw(object $application, array $entry): void
    {
        $removed = false;

        foreach ($application->getMessageQueue(true) as $queued) {
            if (!$removed && $queued === $entry) {
                $removed = true;

                continue;
            }

            $application->enqueueMessage($queued['message'], $queued['type']);
        }
    }

    private static function escape(string $text): string
    {
        return \htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Write one line to the plugin's own log file.
     *
     * The category brings its own logger, for the reason the component's installer
     * script gives: a category-only `Log::add()` is discarded unless `log_everything`
     * is on. A log that cannot be written is swallowed too.
     */
    private function log(string $message): void
    {
        try {
            if (!$this->logger) {
                Log::addLogger(['text_file' => self::LOG_CATEGORY . '.php'], Log::ALL, [self::LOG_CATEGORY]);
                $this->logger = true;
            }

            Log::add($message, Log::WARNING, self::LOG_CATEGORY);
        } catch (\Throwable) {
            // Nothing left to try.
        }
    }
}
