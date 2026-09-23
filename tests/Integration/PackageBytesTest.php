<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The packaged component carries the authored payload bytes unchanged.
 */
#[CoversNothing]
final class PackageBytesTest extends TestCase
{
    private static string $repo;

    private static string $zip;

    public static function setUpBeforeClass(): void
    {
        self::$repo = \dirname(__DIR__, 2);
        self::$zip  = self::$repo . '/installation/dev/com_ecskccustom-v0-1-0.zip';
    }

    public function testDistDevProducesAZipWhosePayloadsMatchTheAuthoredBytes(): void
    {
        $this->requireMake();
        $this->runMake(['dist_dev']);

        self::assertFileExists(self::$zip);

        $archive = new ZipArchive();
        self::assertTrue($archive->open(self::$zip, ZipArchive::RDONLY));

        try {
            foreach ($this->payloadFiles() as $relative) {
                $authored = self::$repo . '/data/payload/' . $relative;
                $entry    = 'admin/payload/' . $relative;
                $bytes    = $archive->getFromName($entry);

                self::assertNotFalse($bytes, $entry . ' is missing from the package');
                self::assertSame(
                    \hash_file('sha256', $authored),
                    \hash('sha256', $bytes),
                    $relative . ' changed in the package'
                );
            }

            foreach ($this->ruleFiles() as $relative) {
                $authored = self::$repo . '/data/rules/' . $relative;
                $entry    = 'admin/rules/' . $relative;
                $bytes    = $archive->getFromName($entry);

                self::assertNotFalse($bytes, $entry . ' is missing from the package');
                self::assertSame(
                    \hash_file('sha256', $authored),
                    \hash('sha256', $bytes),
                    $relative . ' changed in the package'
                );
            }

            self::assertNotFalse($archive->getFromName('LICENSE'), 'LICENSE is missing from the package');
            self::assertNotFalse($archive->getFromName('script.php'), 'script.php is missing from the archive root');
            self::assertFalse(
                $archive->locateName('params.ini'),
                'params.ini must not ship; a packager that walked a directory would pick it up'
            );
        } finally {
            $archive->close();
        }
    }

    public function testVendoredJzipMatchesTheMaster(): void
    {
        $vendored = self::$repo . '/tools/jzip.php';
        $master   = \getenv('HOME') . '/repos/ys_toolbox/src/jzip/jzip.php';

        self::assertFileExists($vendored);

        // The master lives beside this repository on the authoring machine and is
        // reachable nowhere else - not in the DDEV container, not on CI. Skipping
        // there loses no cover: `make lint` runs the same `cmp` on the host, and
        // `make release` runs lint, so a drifted copy still cannot be published.
        if (!\is_file($master)) {
            self::markTestSkipped($master . ' is not present, so the vendored copy cannot be compared here');
        }

        self::assertFileEquals($master, $vendored);
    }

    public function testDistDevDeletesTheZipBeforeValidate(): void
    {
        $this->requireMake();
        $this->runMake(['dist_dev']);
        self::assertFileExists(self::$zip);

        $makefile = (string) \file_get_contents(self::$repo . '/Makefile');

        self::assertMatchesRegularExpression(
            '/^dist_dev:\n\t@rm -f "\$\(DEV_ZIP\)"\n\t\$\(MAKE\) validate/m',
            $makefile,
            'dist_dev must delete the zip before validate, so a failed check cannot leave a stale artefact'
        );
    }

    /**
     * @return list<string>
     */
    private function payloadFiles(): array
    {
        return [
            'apple-touch-icon.png.v1',
            'apple-touch-icon-precomposed.png.v1',
            'components/com_comprofiler/plugin/language/cbpaidsubscriptions-en-gb/language.php.v1',
            'components/com_comprofiler/plugin/language/cbpaidsubscriptions-en-gb/override.php.v1',
            'components/com_comprofiler/plugin/user/plug_cbgallery/library/Controller/FolderController.php.v1',
            'components/com_comprofiler/plugin/user/plug_cbgallery/library/Controller/FolderController.php.v2',
            'components/com_kunena/template/nts_kbee/config/config.xml.v1',
            'components/com_kunena/template/nts_kbee/layouts/topic/edit/default.php.v1',
            'components/com_kunena/template/nts_kbee/layouts/topic/edit/default.php.v2',
            'components/com_kunena/template/nts_kbee/layouts/widget/error/default.php.v1',
            'components/com_kunena/template/nts_kbee/layouts/widget/error/default.php.v2',
            'plugins/system/helixultimate/overrides/com_contact/contact/default_form.php.v1',
            'plugins/system/helixultimate/overrides_legacy/com_contact/contact/default_form.php.v1',
            'templates/et_tennis/css/override.css',
            'templates/et_tennis/options.xml.v1',
        ];
    }

    /**
     * @return list<string>
     */
    private function ruleFiles(): array
    {
        return [
            '10-comprofiler.xml',
            '20-helix-ultimate.xml',
            '30-nts-kbee.xml',
            '40-et-tennis.xml',
            '50-site-icons.xml',
        ];
    }

    /**
     * Skip rather than fail where the build itself cannot run.
     *
     * These two tests drive `make`. The DDEV web container has no `make`, so
     * without this they report a missing build tool as a packaging defect. The
     * host builds releases and has it, so the gate still holds where it matters.
     */
    private function requireMake(): void
    {
        foreach (\explode(\PATH_SEPARATOR, (string) \getenv('PATH')) as $dir) {
            if ($dir !== '' && \is_executable($dir . '/make')) {
                return;
            }
        }

        self::markTestSkipped('make is not on PATH, so the build cannot be driven here');
    }

    /**
     * @param list<string> $args
     */
    private function runMake(array $args): void
    {
        $pipes   = [];
        $process = \proc_open(
            \array_merge(['make', '-C', self::$repo], $args),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        self::assertIsResource($process, 'Could not start make');

        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $status = \proc_close($process);

        self::assertSame(0, $status, $stderr === '' ? $stdout : $stderr);
    }
}
