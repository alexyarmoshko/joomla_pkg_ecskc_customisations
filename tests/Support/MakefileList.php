<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Support;

use RuntimeException;

/**
 * Reads a list variable from the Makefile, one entry per element.
 *
 * `read()` asks make for the expanded value where make is on PATH, so the tests see
 * what the build sees. The DDEV web container has no make, and there `read()` falls
 * back to the source text, which is the same for a list that names no variable.
 */
final class MakefileList
{
    /**
     * @return list<string>
     */
    public static function read(string $name, ?string $makefile = null): array
    {
        $makefile ??= self::repositoryMakefile();

        return self::makeIsAvailable()
            ? self::evaluate($name, $makefile)
            : self::source($name, $makefile);
    }

    /**
     * The entries as written, with any `$(...)` left unexpanded.
     *
     * @return list<string>
     */
    public static function source(string $name, ?string $makefile = null): array
    {
        $makefile ??= self::repositoryMakefile();
        $text     = \file_get_contents($makefile);

        if ($text === false) {
            throw new RuntimeException(\sprintf('Could not read %s', $makefile));
        }

        if (\preg_match('/^' . \preg_quote($name, '/') . '\s*:=((?:[^\n]*\\\\\n)*[^\n]*)$/m', $text, $match) !== 1) {
            throw new RuntimeException(\sprintf('%s defines no list %s', $makefile, $name));
        }

        $entries = \preg_split('/\s+/', \str_replace("\\\n", ' ', $match[1]), -1, \PREG_SPLIT_NO_EMPTY);

        return self::nonEmpty($name, $entries === false ? [] : $entries);
    }

    public static function makeIsAvailable(): bool
    {
        foreach (\explode(\PATH_SEPARATOR, (string) \getenv('PATH')) as $dir) {
            if ($dir !== '' && \is_executable($dir . '/make')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function evaluate(string $name, string $makefile): array
    {
        [$status, $stdout, $stderr] = ChildProcess::run([
            'make',
            '-s',
            '-C',
            \dirname($makefile),
            '-f',
            \basename($makefile),
            '--eval',
            'print_list: ; @printf "%s\\n" $($(LIST))',
            'print_list',
            'LIST=' . $name,
        ]);

        if ($status !== 0) {
            throw new RuntimeException(\sprintf('make could not print %s: %s', $name, $stdout . $stderr));
        }

        return self::nonEmpty($name, \preg_split('/\n/', \trim($stdout), -1, \PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * @param list<string> $entries
     *
     * @return list<string>
     */
    private static function nonEmpty(string $name, array $entries): array
    {
        if ($entries === []) {
            throw new RuntimeException(\sprintf('The Makefile list %s is empty', $name));
        }

        return $entries;
    }

    private static function repositoryMakefile(): string
    {
        return \dirname(__DIR__, 2) . '/Makefile';
    }
}
