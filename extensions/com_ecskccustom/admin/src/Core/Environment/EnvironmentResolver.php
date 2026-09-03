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
 *
 * Decision 16 puts every derivation rule here rather than in the adapter, so a
 * production run and a temporary-tree test share one derivation path and a test can
 * supply the raw inputs directly. The adapter's whole job is to say what Joomla
 * holds; this class decides what it means.
 *
 * ## SITE_URL and HOST
 *
 * Brief 5.1: SITE_URL is Joomla's `live_site` where it is set, and otherwise
 * `Uri::base()` with the trailing slash and any `administrator/` segment removed.
 * HOST is the host component parsed out of SITE_URL.
 *
 * They are two attributes because they are two shapes - `https://ecskc.eu` against
 * `ecskc.eu` - and an author has to know which one a pattern is written against. A
 * pattern correctly anchored for one fails silently against the other, and section 6
 * records the exclusion that failed open that way.
 *
 * The two removals are not the same kind of act, so they are not applied to the same
 * branch.
 *
 * A trailing slash comes off both. `https://ecskc.eu/` and `https://ecskc.eu` are the
 * same resource (RFC 3986 makes an empty path and `/` equivalent for http and https),
 * so removing it settles a spelling rather than changing a value - the same judgement
 * that lower-cases a host. It matters: left on, the exclusion
 * `^(?!https://ecskc\.eu$).*$` does not match `https://ecskc.eu/`, so it excludes
 * nothing and applies on production.
 *
 * The `administrator` segment comes off only the request-derived branch, which is
 * what the brief and the plan assign it to. `https://ecskc.eu/administrator` is not
 * the same resource as `https://ecskc.eu`, so stripping it from a value an operator
 * configured deliberately would be a guess about what they meant, and this codebase
 * refuses to rewrite an operator's value on a guess. `Uri::base()` in the
 * administrator application genuinely carries the segment as an artefact of which
 * side the run happens to start from, which is why that branch strips it.
 *
 * An empty `live_site` falls back to the request. So does a `live_site` that could
 * not be read at all: both mean no configured site URL, and falling back is what
 * Joomla itself does. The distinction the adapter preserves matters one level down,
 * where a missing request base is a fault rather than an empty string.
 *
 * ## Faults
 *
 * Nothing here substitutes a placeholder. Where a value cannot be derived the
 * attribute is faulted with the reason, the object reports `faulted`, and no pattern
 * is evaluated against it.
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
     * Neither is the author's choice to make. Host names are case-insensitive
     * (RFC 4343) and `ecskc.eu.` is the fully-qualified spelling of `ecskc.eu`, so
     * all three name one host - but `parse_url` returns whatever spelling the URL
     * carried, and where no live_site is configured that spelling comes from the
     * request's own Host header.
     *
     * Left alone, `https://ECSKC.EU/` gives HOST `ECSKC.EU`, the exclusion
     * `^(?!ecskc\.eu$).*$` matches it, and the customisation that exclusion exists
     * to keep off production is applied on production. Nothing reports a problem,
     * because the value is determined and the expression is valid. Section 6's case,
     * reached by a different road.
     *
     * SITE_URL keeps the spelling it was given. It is a URL, and only its authority
     * is case-insensitive - the path is not, so there is no whole-value
     * canonicalisation to apply and guessing at part of one would be its own silent
     * rewrite. A host-shaped expression written against SITE_URL is the build's to
     * warn about (plan section: "a regular expression against SITE_URL that appears
     * to describe a host").
     *
     * Public so the build's warning pass judges a literal HOST operand against this
     * same rule. Folding the operand at evaluation time would make one attribute of
     * the rule language case-insensitive with nothing saying so.
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
     *
     * The segment has to be a path segment, and the authority is where that is
     * decided. A host named `administrator` - ordinary enough on an intranet - gives
     * a base of `http://administrator/`, and cutting the tail off that leaves
     * `http:`, which is not a URL and is not empty either, so it would be carried as
     * a *determined* SITE_URL. An exclusion anchored on the real value would then
     * match the wreckage and apply the customisation it was written to withhold -
     * section 6's case again, reached through a string bug. Review s1.v23, finding 2.
     *
     * One ambiguity is left standing on purpose: a site whose front end genuinely
     * lives in a folder named `administrator`. Nothing in the value says whether the
     * segment is the admin application's artefact or the operator's own path, and
     * this branch is reached only where `live_site` is unset. Configuring
     * `live_site`, which is never rewritten here, settles it.
     */
    private function withoutAdministrator(string $value): string
    {
        if (!\str_ends_with(\strtolower($value), self::ADMIN_SEGMENT)) {
            return $value;
        }

        $authority = \strpos($value, '//');
        $pathStart = $authority === false ? 0 : \strpos($value, '/', $authority + 2);

        if ($pathStart === false || \strlen($value) - \strlen(self::ADMIN_SEGMENT) < $pathStart) {
            // The segment is the authority itself, not a path under it.
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
