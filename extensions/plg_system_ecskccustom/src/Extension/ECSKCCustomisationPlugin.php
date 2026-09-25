<?php

/**
 * @package     YakShaver\Plugin\System\ECSKCCustomisation
 * @subpackage  System
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Plugin\System\ECSKCCustomisation\Extension;

use Joomla\CMS\Event\Application\BeforeCompileHeadEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Delivers the package-owned `override.css` on site pages, and does nothing else
 * (plan decisions 19 and 20).
 *
 * It registers the applied target under the site root, not the copy under the
 * component's `payload/`, which a deny-all `.htaccess` keeps unservable. Before
 * the first apply, and after a revert of the create, the target is absent and
 * the plugin registers nothing. That is a normal state, not an error.
 *
 * It loads no rules, builds no runner, reads nothing under `state/`, and issues
 * no query, so a rule-set fault never reaches a front-end request.
 */
final class ECSKCCustomisationPlugin extends CMSPlugin implements SubscriberInterface
{
    /**
     * The applied target, relative to the site root and to the site URL alike.
     */
    public const STYLESHEET = 'templates/et_tennis/css/override.css';

    /**
     * @param  array<string, mixed>  $config    The plugin's row, as PluginHelper returns it.
     * @param  string                $siteRoot  JPATH_ROOT, passed in so a test can point it at a temporary tree.
     */
    public function __construct(array $config, private readonly string $siteRoot)
    {
        parent::__construct($config);
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return ['onBeforeCompileHead' => 'onBeforeCompileHead'];
    }

    /**
     * Register the stylesheet on a site HTML page when the applied file exists.
     *
     * The checks run cheapest first, and the client check comes before anything
     * touches the document or the file system. `addStyleSheet()` is deprecated
     * in favour of the Web Asset Manager, but decision 19 measured that route
     * losing 14 rules to later sheets, so this stays on the document API.
     */
    public function onBeforeCompileHead(BeforeCompileHeadEvent $event): void
    {
        if (!$event->getApplication()->isClient('site')) {
            return;
        }

        $document = $event->getDocument();

        if ($document->getType() !== 'html') {
            return;
        }

        if (!\is_file($this->siteRoot . '/' . self::STYLESHEET)) {
            return;
        }

        $document->addStyleSheet(Uri::root(true) . '/' . self::STYLESHEET, ['version' => 'auto']);
    }
}
