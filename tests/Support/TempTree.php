<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Support;

use RuntimeException;

/**
 * A real temporary directory tree for core tests.
 *
 * It stays check-then-act, and on a single-threaded test suite that is fine. PHP
 * offers no openat/O_NOFOLLOW without an extension, so the race cannot be closed
 * here, and against a careless test rather than an attacker it does not need to be.
 */
final class TempTree
{
    private function __construct(private readonly string $root)
    {
    }

    public static function create(string $prefix = 'ecskc-'): self
    {
        // The prefix names the tree; it does not get to say where the tree lives.
        // resolve() cannot guard create(), because create() builds the root that
        // resolve() measures against, and a prefix with a separator could put that
        // root anywhere reachable.
        if (\preg_match('/\A[A-Za-z0-9._-]*\z/', $prefix) !== 1) {
            throw new RuntimeException(\sprintf(
                'Refusing the temporary-tree prefix %s: it must be a bare name, '
                . 'with no path separator',
                \var_export($prefix, true)
            ));
        }

        $base = \sys_get_temp_dir();
        $root = $base . \DIRECTORY_SEPARATOR . \uniqid($prefix, true);

        if (!@\mkdir($root, 0o755) && !\is_dir($root)) {
            throw new RuntimeException(\sprintf('Could not create temporary tree at %s', $root));
        }

        $resolved = \realpath($root);

        if ($resolved === false) {
            throw new RuntimeException(\sprintf('Could not resolve the temporary tree at %s', $root));
        }

        // Canonical from here on, so every containment comparison has a canonical
        // left-hand side. /tmp is itself a symlink on some systems.
        return new self($resolved);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Resolve a relative path inside the tree, or refuse it.
     */
    public function path(string $relative): string
    {
        return $this->resolve($relative);
    }

    /**
     * Write exact bytes at a relative path, creating parent directories.
     */
    public function write(string $relative, string $bytes): string
    {
        $path = $this->resolve($relative);
        $dir  = \dirname($path);

        if (!\is_dir($dir) && !@\mkdir($dir, 0o755, true) && !\is_dir($dir)) {
            throw new RuntimeException(\sprintf('Could not create directory %s', $dir));
        }

        if (\file_put_contents($path, $bytes) !== \strlen($bytes)) {
            throw new RuntimeException(\sprintf('Short write to %s', $path));
        }

        return $path;
    }

    /**
     * Read exact bytes back from a relative path.
     */
    public function read(string $relative): string
    {
        $path  = $this->resolve($relative);
        $bytes = @\file_get_contents($path);

        if ($bytes === false) {
            throw new RuntimeException(\sprintf('Could not read %s', $path));
        }

        return $bytes;
    }

    /**
     * Create a symbolic link inside the tree, pointing anywhere.
     *
     * The link itself must sit inside the tree; only its target may be outside.
     * remove() unlinks it rather than following it.
     */
    public function symlink(string $linkRelative, string $target): string
    {
        $link = $this->resolve($linkRelative);
        $dir  = \dirname($link);

        if (!\is_dir($dir) && !@\mkdir($dir, 0o755, true) && !\is_dir($dir)) {
            throw new RuntimeException(\sprintf('Could not create directory %s', $dir));
        }

        if (!@\symlink($target, $link)) {
            throw new RuntimeException(\sprintf('Could not link %s to %s', $link, $target));
        }

        return $link;
    }

    /**
     * Remove the tree, raising rather than leaving something behind quietly.
     *
     * A suppressed cleanup failure would let one test's leftovers decide the next
     * test's result, which is the kind of order-dependent flake that costs far more
     * to diagnose than to prevent.
     */
    public function remove(): void
    {
        self::removeRecursively($this->root);

        if (\file_exists($this->root)) {
            throw new RuntimeException(
                \sprintf('The temporary tree at %s could not be removed', $this->root)
            );
        }
    }

    /**
     * Turn a relative path into an absolute one, and prove it stays in the tree.
     *
     * The lexical checks come first only because they give a better message than a
     * containment failure does. The containment check below is the guarantee, and
     * it holds whether or not the lexical ones spot anything: it resolves the
     * deepest part of the path that exists and asks whether that is still under the
     * root. A symbolic-link parent, a link as the final component, a root that is
     * itself reached through a link - all of them answer that one question.
     */
    private function resolve(string $relative): string
    {
        if ($relative === '') {
            throw new RuntimeException('An empty path has no place in the temporary tree.');
        }

        if (\str_starts_with($relative, '/') || \str_contains($relative, '\\')) {
            throw new RuntimeException(\sprintf(
                'Refusing an absolute or backslash-separated path in the temporary tree: %s',
                $relative
            ));
        }

        foreach (\explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException(\sprintf(
                    'Refusing a path with an empty, "." or ".." segment in the temporary tree: %s',
                    $relative
                ));
            }
        }

        $intended = $this->root . \DIRECTORY_SEPARATOR . $relative;

        // The path itself need not exist yet; its existing ancestry is what decides
        // where a write would land.
        $probe = $intended;

        while ($probe !== $this->root && !\file_exists($probe) && !\is_link($probe)) {
            $parent = \dirname($probe);

            if ($parent === $probe) {
                break;
            }

            $probe = $parent;
        }

        $resolved = \realpath($probe);

        if ($resolved === false) {
            throw new RuntimeException(\sprintf(
                'Could not resolve %s inside the temporary tree',
                $relative
            ));
        }

        if ($resolved !== $this->root
            && !\str_starts_with($resolved, $this->root . \DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(\sprintf(
                'Refusing %s: it resolves to %s, outside the temporary tree at %s',
                $relative,
                $resolved,
                $this->root
            ));
        }

        $this->assertNotAnInodeAlias($intended);

        return $intended;
    }

    /**
     * Refuse a file that has more than one name.
     *
     * resolve() cannot see this one. A hard link is not a path leading elsewhere -
     * it is a second name for one inode, so realpath() quite correctly reports a
     * path inside the tree while the bytes are also reachable from outside it. The
     * link count is the only signal available.
     */
    private function assertNotAnInodeAlias(string $path): void
    {
        if (!\is_file($path)) {
            return;
        }

        \clearstatcache(true, $path);
        $stat = @\stat($path);

        if ($stat === false) {
            throw new RuntimeException(\sprintf(
                'Refusing %s: the file exists but could not be inspected',
                $path
            ));
        }

        if (($stat['nlink'] ?? 1) > 1) {
            throw new RuntimeException(\sprintf(
                'Refusing %s: it has %d names, so reading or writing here would reach bytes '
                . 'that are also reachable from outside the temporary tree',
                $path,
                $stat['nlink']
            ));
        }
    }

    private static function removeRecursively(string $path): void
    {
        // A link is unlinked, never followed: recursing through one would delete
        // whatever it points at. Everything else that is not a directory goes the
        // same way, ordinary file or not: a test tree can hold a FIFO, and skipping
        // it would leave a directory that cannot be removed.
        if (\is_link($path) || (\file_exists($path) && !\is_dir($path))) {
            if (!\unlink($path)) {
                throw new RuntimeException(\sprintf('Could not remove %s', $path));
            }

            return;
        }

        if (!\is_dir($path)) {
            return;
        }

        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            self::removeRecursively($path . \DIRECTORY_SEPARATOR . $entry);
        }

        if (!\rmdir($path)) {
            throw new RuntimeException(\sprintf('Could not remove the directory %s', $path));
        }
    }
}
