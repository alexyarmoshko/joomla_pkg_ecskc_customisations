<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment;

/**
 * Derives the four condition attributes from the raw Joomla inputs.
 */
final class EnvironmentResolver
{
    private const ADMIN_SEGMENT = '/administrator';

    public function resolve(RawEnvironment $raw): EnvironmentValues
    {
        $values = [];
        $faults = [];

        $siteUrl = $this->siteUrl($raw, $faults);
        $values[EnvironmentValues::SITE_URL] = $siteUrl;
        $values[EnvironmentValues::HOST]     = $this->host($siteUrl, $faults);

        $values[EnvironmentValues::JOOMLA_VERSION] = $raw->joomlaVersion;
        $values[EnvironmentValues::PHP_VERSION]    = $raw->phpVersion;

        if ($this->isBlank($raw->joomlaVersion)) {
            $faults[EnvironmentValues::JOOMLA_VERSION] = 'Joomla did not report its version';
        }

        if ($this->isBlank($raw->phpVersion)) {
            $faults[EnvironmentValues::PHP_VERSION] = 'PHP did not report its version';
        }

        return new EnvironmentValues($values, $faults);
    }

    /**
     * @param array<string, string> $faults
     */
    private function siteUrl(RawEnvironment $raw, array &$faults): ?string
    {
        if (!$this->isBlank($raw->liveSite)) {
            return $this->withoutTrailingSlash((string) $raw->liveSite);
        }

        if (!$this->isBlank($raw->uriBase)) {
            return $this->withoutAdministrator($this->withoutTrailingSlash((string) $raw->uriBase));
        }

        $faults[EnvironmentValues::SITE_URL] = 'live_site is not configured and the request '
            . 'carried no base URL to derive it from, which is the ordinary case outside a web '
            . 'request';

        return null;
    }

    /**
     * @param array<string, string> $faults
     */
    private function host(?string $siteUrl, array &$faults): ?string
    {
        if ($siteUrl === null) {
            $faults[EnvironmentValues::HOST] = 'SITE_URL could not be determined, and HOST is '
                . 'the host component of SITE_URL';

            return null;
        }

        $parsed = \parse_url($siteUrl, \PHP_URL_HOST);
        $host   = \is_string($parsed) ? self::canonicalHost($parsed) : '';

        if ($host === '') {
            $faults[EnvironmentValues::HOST] = \sprintf(
                'SITE_URL is "%s", which has no host component. SITE_URL is a full URL '
                . '(https://ecskc.eu) and HOST is the host on its own (ecskc.eu); a value '
                . 'without a scheme parses as a path and yields no host.',
                $siteUrl
            );

            return null;
        }

        return $host;
    }

    /**
     * Lower case, and without the root label's trailing dot.
     *
     * Left alone, `https://ECSKC.EU/` gives HOST `ECSKC.EU`, the exclusion
     * `^(?!ecskc\.eu$).*$` matches it, and the customisation that exclusion exists
     * to keep off production is applied on production. Nothing reports a problem,
     * because the value is determined and the expression is valid. Section 6's case,
     * reached by a different road.
     */
    public static function canonicalHost(string $host): string
    {
        $host = \strtolower($host);

        if (\str_ends_with($host, '.')) {
            $host = \substr($host, 0, -1);
        }

        return $host;
    }

    private function withoutTrailingSlash(string $value): string
    {
        return \rtrim(\trim($value), '/');
    }

    /**
     * `Uri::base()` in the administrator application returns
     * `https://ecskc.eu/administrator/`, and a rule about the site must not be written
     * twice to cover the side the run happens to start from.
     */
    private function withoutAdministrator(string $value): string
    {
        if (!\str_ends_with(\strtolower($value), self::ADMIN_SEGMENT)) {
            return $value;
        }

        $authority = \strpos($value, '//');
        $pathStart = $authority === false ? 0 : \strpos($value, '/', $authority + 2);

        if ($pathStart === false || \strlen($value) - \strlen(self::ADMIN_SEGMENT) < $pathStart) {

            return $value;
        }

        return \rtrim(\substr($value, 0, -\strlen(self::ADMIN_SEGMENT)), '/');
    }

    /**
     * Null and empty are one answer here: nothing to derive from. They are kept
     * apart in RawEnvironment because the adapter must not decide what an empty
     * live_site means, and this is the class that decides it.
     */
    private function isBlank(?string $value): bool
    {
        return $value === null || \trim($value) === '';
    }
}
