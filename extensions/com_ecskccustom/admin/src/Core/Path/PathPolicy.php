<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path;

use RuntimeException;

/**
 * Decides whether the package may write to a target path, and where that path is.
 *
 * Brief 5.4 puts these limits in the tool rather than in the rule set. Making the
 * customisations data removed a safety property the old hand-maintained inventory
 * had by accident: section 3.3 excluded `.htaccess` because writing it from an
 * extension is the highest-risk operation on the site, but that was an editorial
 * decision about a list. Once the list is data, nothing in the list stops a rule
 * naming that file. So the constraint has to live here, where a rule cannot reach
 * it, and both lists are hardcoded - a list the rule set could extend would be
 * decoration.
 *
 * ## The order, and why deny wins
 *
 * Resolve, confine, then judge the resolved path against both lists. Where they
 * disagree the deny list wins, and its reason is the one reported: `configuration.php`
 * is an allowed extension at a denied path, which is the case that shows why both
 * lists exist. A deny list fails open on whatever nobody enumerated; an allow list
 * fails closed. The allow list refuses unknown file *types*, extensionless files
 * included; the deny list refuses known *paths*.
 *
 * `.ini` is deliberately absent from the allow list. Brief section 6 singles out
 * `params.ini` as saved settings that must never be shipped, and an allow list
 * permitting it would invite the exact mistake it exists to prevent.
 *
 * ## Resolution, and what actually guarantees confinement
 *
 * Confinement is decided on the **resolved** path, because a root and a relative
 * path can each look harmless and still combine to point outside. The target need
 * not exist yet - a rule may create a file - so resolution takes the deepest
 * existing ancestor, canonicalises that, and appends the part that does not exist.
 * Components that do not exist cannot be symbolic links, so nothing is lost.
 *
 * The lexical checks below come first only because they give a better message. The
 * containment test on the resolved path is the guarantee, and it holds whether or
 * not the lexical checks spot anything: a symbolic-link parent, a link as the final
 * component, and a site root that is itself reached through a link all answer the
 * one question of where the path landed.
 *
 * ## The package's own tree
 *
 * Confinement cannot cover it. The component lives inside the site root, so without
 * an explicit entry a rule could rewrite the payloads every check is made against,
 * the rule set itself, or the backup tree holding the backup chain and the freeze
 * markers. The patch must not patch itself.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class PathPolicy
{
    /**
     * Matched after resolution, against the file's extension in lower case.
     */
    public const ALLOWED_EXTENSIONS = ['php', 'css', 'xml', 'png', 'js', 'svg', 'jpg', 'gif', 'ico'];

    /**
     * Denied by file name, at any depth.
     *
     * Brief 5.4 enumerates these three without a `/**` suffix, which reads as the
     * site root alone. They are refused at every depth instead, a deliberate
     * widening: each one configures the server or the CMS for the directory it sits
     * in, so a copy of it three levels down is the same operation with the same
     * risk. Nothing in the managed inventory names any of them, so the widening
     * costs nothing and fails loudly at build time if that ever changes.
     */
    public const DENIED_NAMES = ['configuration.php', '.htaccess', 'web.config'];

    /**
     * Denied as whole trees, measured from the site root.
     *
     * `administrator/logs` is not in brief 5.4's enumeration and is added here.
     * Measured on the target site: `configuration.php` has
     * `log_path = '/var/www/html/administrator/logs'`, which is Joomla's default,
     * and there is no top-level `logs/` at all. The enumerated `logs` therefore
     * guarded a directory that does not exist while the real log tree stayed
     * writable. The brief already pairs `cache` with `administrator/cache`; the
     * missing twin for logs reads as an oversight rather than a decision.
     *
     * Both paths are operator-configurable, so a list of names can still be wrong on
     * a site that moves them. Deriving them from the CMS configuration would need
     * the adapter and is a decision for the plan, not for this class.
     */
    public const DENIED_TREES = ['cache', 'administrator/cache', 'tmp', 'logs', 'administrator/logs'];

    private readonly string $siteRoot;

    private readonly string $packageRoot;

    /**
     * @param string $siteRoot    The site root. Must exist: confinement to a root
     *                            that cannot be found is not confinement.
     * @param string $packageRoot The installed package tree, which must never be a
     *                            target. Resolved when it exists; the build runs
     *                            this policy with no package installed, so a root
     *                            that does not exist is kept as given rather than
     *                            refused.
     */
    public function __construct(string $siteRoot, string $packageRoot)
    {
        $resolved = \realpath($siteRoot);

        if ($resolved === false) {
            throw new RuntimeException(\sprintf('The site root does not exist at %s', $siteRoot));
        }

        $this->siteRoot = $resolved;

        // Canonicalised the same way a target is, so the comparison in denial() has a
        // canonical value on both sides. realpath() alone is not enough: the package
        // may not be installed - the build runs with none on disk - and a raw string
        // never matches a resolved target when the site root is itself reached
        // through a symbolic link, which would leave the guard inert and silent.
        $this->packageRoot = $this->resolve($packageRoot) ?? \rtrim($packageRoot, '/');
    }

    public function siteRoot(): string
    {
        return $this->siteRoot;
    }

    /**
     * @param string $targetPath A target in canonical form: relative to the site
     *                           root, with no leading slash, as RuleObject produces.
     */
    public function decide(string $targetPath): PathDecision
    {
        $lexical = $this->lexicalRefusal($targetPath);

        if ($lexical !== null) {
            return PathDecision::refuse($lexical);
        }

        $dangling = null;
        $resolved = $this->resolve($this->siteRoot . '/' . $targetPath, $dangling);

        if ($dangling !== null) {
            return PathDecision::refuse(\sprintf(
                '%s: %s is a symbolic link whose destination does not exist, so where a write '
                . 'would land cannot be established',
                $targetPath,
                $dangling
            ));
        }

        if ($resolved === null) {
            return PathDecision::refuse(
                \sprintf('%s: the path could not be resolved', $targetPath)
            );
        }

        if (!$this->isInsideSiteRoot($resolved)) {
            return PathDecision::refuse(
                \sprintf('%s: resolves outside the site root, to %s', $targetPath, $resolved)
            );
        }

        // Judged after resolution so neither list can be evaded by reaching the same
        // file along a different route. Deny is tested first because where the two
        // disagree it wins, and its reason is the one worth reporting.
        $denial = $this->denial($targetPath, $resolved);

        if ($denial !== null) {
            return PathDecision::refuse($denial);
        }

        $extension = \strtolower(\pathinfo($resolved, \PATHINFO_EXTENSION));

        if (!\in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return PathDecision::refuse(\sprintf(
                '%s: %s is not on the extension allow list',
                $targetPath,
                $extension === '' ? 'a file with no extension' : '.' . $extension
            ));
        }

        return PathDecision::allow($resolved);
    }

    /**
     * Refusals a string can be judged on, which give a better message than a
     * containment failure does. The containment test is what guarantees the result.
     */
    private function lexicalRefusal(string $targetPath): ?string
    {
        if ($targetPath === '') {
            return 'the target path is empty';
        }

        if (\str_starts_with($targetPath, '/')) {
            return \sprintf(
                '%s: a target reaches path policy in canonical form, relative to the site root '
                . 'with no leading slash',
                $targetPath
            );
        }

        if (\str_contains($targetPath, '\\')) {
            return \sprintf('%s: a backslash is not a path separator here', $targetPath);
        }

        foreach (\explode('/', $targetPath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return \sprintf(
                    '%s: %s is not a path segment',
                    $targetPath,
                    $segment === '' ? 'an empty segment' : \var_export($segment, true)
                );
            }
        }

        return null;
    }

    /**
     * Canonicalise as much of the path as exists, and keep the rest verbatim.
     *
     * A target a rule creates does not exist yet, so realpath() on the whole path
     * returns false and says nothing about where it would land. The deepest existing
     * ancestor is what can be canonicalised, and the components below it cannot be
     * symbolic links because they do not exist.
     *
     * **Unless they are dangling ones.** realpath() fails the same way for a missing
     * file and for a symbolic link whose destination does not exist, so a link
     * treated as a missing component keeps its own name in the resolved path while a
     * write through it lands wherever the link points. Measured: a link to a
     * not-yet-created file outside the site root was reported as an in-root target,
     * and writing to it created the file outside. A dangling link is therefore
     * refused rather than guessed at - the same rule TempTree applies to its own
     * tree, and for the same reason.
     *
     * @param string|null $danglingLink Set to the component that is a symbolic link
     *                                  with no resolvable destination.
     */
    private function resolve(string $absolutePath, ?string &$danglingLink = null): ?string
    {
        $existing = $absolutePath;
        $missing  = [];

        while (($resolved = \realpath($existing)) === false) {
            if (\is_link($existing)) {
                $danglingLink = $existing;

                return null;
            }

            $missing[] = \basename($existing);
            $parent    = \dirname($existing);

            if ($parent === $existing) {
                return null;
            }

            $existing = $parent;
        }

        if ($missing === []) {
            return $resolved;
        }

        return $resolved . '/' . \implode('/', \array_reverse($missing));
    }

    private function isInsideSiteRoot(string $resolved): bool
    {
        return $resolved === $this->siteRoot || \str_starts_with($resolved, $this->siteRoot . '/');
    }

    private function denial(string $targetPath, string $resolved): ?string
    {
        if ($resolved === $this->packageRoot || \str_starts_with($resolved, $this->packageRoot . '/')) {
            return \sprintf(
                '%s: the deny list refuses the package\'s own tree. The patch must not patch itself.',
                $targetPath
            );
        }

        if (\in_array(\basename($resolved), self::DENIED_NAMES, true)) {
            return \sprintf(
                '%s: the deny list refuses every %s',
                $targetPath,
                \basename($resolved)
            );
        }

        $relative = \substr($resolved, \strlen($this->siteRoot) + 1);

        foreach (self::DENIED_TREES as $tree) {
            if ($relative === $tree || \str_starts_with($relative, $tree . '/')) {
                return \sprintf('%s: the deny list refuses everything under %s/', $targetPath, $tree);
            }
        }

        return null;
    }
}
