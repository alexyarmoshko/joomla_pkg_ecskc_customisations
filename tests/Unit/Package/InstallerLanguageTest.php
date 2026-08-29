<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Package;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every string the installer script prints has to be in the .sys language file.
 *
 * `InstallerAdapter::doLoadLanguage()` loads `<extension>.sys` and nothing else, so
 * a key defined only in the runtime `com_ecskccustom.ini` renders during install as
 * its own name. The operator would be shown COM_ECSKCCUSTOM_ERROR_STATE_PROTECTION
 * with no folder in it - at exactly the moment they need to know which folder is
 * unprotected and that the install rolled back.
 *
 * Read from the files rather than asserted as a list, so adding a key to script.php
 * and forgetting the language file fails here rather than in front of an operator.
 */
final class InstallerLanguageTest extends TestCase
{
    private static function languageDirectory(): string
    {
        return \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/language/en-GB';
    }

    /**
     * @return list<string>
     */
    private static function keysDefinedIn(string $file): array
    {
        $contents = \file_get_contents(self::languageDirectory() . '/' . $file);
        self::assertIsString($contents, $file . ' must be readable.');

        \preg_match_all('/^([A-Z0-9_]+)=/m', $contents, $matches);

        return $matches[1];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function keysUsedByTheInstaller(): array
    {
        $script = \file_get_contents(
            \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/script.php'
        );

        if (!\is_string($script)) {
            return ['script.php is unreadable' => ['']];
        }

        \preg_match_all("/'(COM_ECSKCCUSTOM_[A-Z0-9_]+)'/", $script, $matches);

        $cases = [];

        foreach (\array_unique($matches[1]) as $key) {
            $cases[$key] = [$key];
        }

        return $cases;
    }

    #[DataProvider('keysUsedByTheInstaller')]
    public function testAnInstallerStringIsDefinedWhereTheInstallerCanLoadIt(string $key): void
    {
        self::assertNotSame('', $key, 'The installer script must be readable.');
        self::assertContains(
            $key,
            self::keysDefinedIn('com_ecskccustom.sys.ini'),
            $key . ' is printed by the installer, so it must be in com_ecskccustom.sys.ini.'
        );
    }

    /**
     * The installer script uses at least one string; a regular expression that
     * matched nothing would make the test above vacuously pass.
     */
    public function testTheInstallerScriptUsesLanguageKeys(): void
    {
        self::assertGreaterThan(1, \count(self::keysUsedByTheInstaller()));
    }
}
