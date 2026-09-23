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
 */
final class PathPolicy
{
    /**
     * Matched after resolution, against the file's extension in lower case.
     */
    public const ALLOWED_EXTENSIONS = ['php', 'css', 'xml', 'png', 'js', 'svg', 'jpg', 'gif', 'ico'];

    /**
     * Denied by file name, at any depth.
     */
    public const DENIED_NAMES = ['configuration.php', '.htaccess', 'web.config'];

    /**
     * Denied as whole trees, measured from the site root.
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
