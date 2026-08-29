<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Environment;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentProviderInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\RawEnvironment;

/**
 * Reads the raw inputs out of Joomla, and derives nothing from them.
 *
 * Deliberately the thinnest possible adapter. Anything resembling a rule -
 * stripping the administrator segment from a base URL, parsing a host out of it,
 * deciding what an empty live_site means - belongs to the core, where one
 * implementation serves both production and the tests. See decision 16.
 *
 * null means one thing only: the value could not be obtained. An empty string is
 * passed through as an empty string, because "live_site is empty, so fall back to
 * Uri::base()" is a derivation rule and those all live in the core.
 *
 * The two must stay distinguishable. The core faults on a value it cannot
 * determine and evaluates no pattern against it; substituting an empty string
 * there would quietly satisfy a negative lookahead and invert the exclusion its
 * author wrote.
 */
final class JoomlaEnvironmentProvider implements EnvironmentProviderInterface
{
    public function raw(): RawEnvironment
    {
        return new RawEnvironment(
            $this->liveSite(),
            $this->uriBase(),
            \defined('JVERSION') ? (string) \JVERSION : null,
            \PHP_VERSION,
        );
    }

    private function liveSite(): ?string
    {
        try {
            $value = Factory::getApplication()->get('live_site');
        } catch (\Throwable) {
            return null;
        }

        // Returned exactly as Joomla holds it, including an empty string. Deciding
        // that an empty live_site means "not set, fall back to Uri::base()" is a
        // derivation rule, and decision 16 puts every derivation rule in the core.
        // null here means only one thing: the value could not be obtained at all.
        return \is_string($value) ? $value : null;
    }

    private function uriBase(): ?string
    {
        // Uri::base() reads $_SERVER['HTTP_HOST'] and raises a PHP warning when
        // there is no request behind it - a warning, not a Throwable, so catching
        // is not enough. Checking first keeps a CLI or scheduled context quiet, and
        // null is the honest answer there: the core faults on it rather than
        // guessing a site URL.
        if (!isset($_SERVER['HTTP_HOST']) || $_SERVER['HTTP_HOST'] === '') {
            return null;
        }

        try {
            $value = Uri::base();
        } catch (\Throwable) {
            return null;
        }

        // Likewise raw: an empty base is the core's to interpret, not the adapter's.
        return \is_string($value) ? $value : null;
    }
}
