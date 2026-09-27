<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use YakShaver\ECSKCCustomisation\Tests\Support\ChildProcess;
use YakShaver\ECSKCCustomisation\Tests\Support\RuleXml;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;
use ZipArchive;

/**
 * The CLI the Makefile runs on every built package: the payloads inside the
 * package's component archive must match the rules beside them.
 *
 * The packages here are fixtures. A real payload is never edited to make one
 * fail (AGENTS.md, "Payload bytes are identity").
 */
#[CoversNothing]
final class VerifyPackageScriptTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-verify-package-cli-');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    public function testMatchingPayloadsExitZero(): void
    {
        [$status, $stdout, $stderr] = $this->verify($this->package(['admin/payload/one.v1' => 'one.v1']));

        self::assertSame(0, $status, $stderr);
        self::assertSame('', $stderr);
        self::assertStringEndsWith(": payload hashes verified\n", $stdout);
    }

    public function testOneChangedByteFailsNamingTheSourceHash(): void
    {
        [$status, , $stderr] = $this->verify($this->package(['admin/payload/one.v1' => 'one.v2']));

        self::assertSame(1, $status);
        self::assertStringContainsString('FAIL:', $stderr);
        self::assertStringContainsString('payload one.v1 hashes to', $stderr);
        self::assertStringContainsString('not the declared sourceHash', $stderr);
    }

    public function testAMissingPayloadFails(): void
    {
        [$status, , $stderr] = $this->verify($this->package(['admin/payload/index.html' => '']));

        self::assertSame(1, $status);
        self::assertStringContainsString('names the payload one.v1, which does not exist', $stderr);
    }

    public function testAPayloadNoRuleNamesFails(): void
    {
        [$status, , $stderr] = $this->verify($this->package([
            'admin/payload/one.v1'   => 'one.v1',
            'admin/payload/stray.v1' => 'stray.v1',
        ]));

        self::assertSame(1, $status);
        self::assertStringContainsString('payload stray.v1 is present but no object names it', $stderr);
    }

    public function testAPackageWithoutTheComponentArchiveFails(): void
    {
        $path = $this->tree->path('empty.zip');
        $zip  = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE));
        $zip->addFromString('pkg_ecskc_customisations.xml', '<extension/>');
        $zip->close();

        [$status, , $stderr] = $this->verify($path);

        self::assertSame(1, $status);
        self::assertStringContainsString('holds no packages/com_ecskccustom.zip', $stderr);
    }

    /**
     * A package holding a component archive with one rule, for `one.v1`, and
     * `$payloads` beside it.
     *
     * @param array<string, string> $payloads Archive path => bytes.
     */
    private function package(array $payloads): string
    {
        $component = $this->tree->path('com_ecskccustom.zip');
        $zip       = new ZipArchive();
        self::assertTrue($zip->open($component, ZipArchive::CREATE));
        $zip->addFromString(
            'admin/rules/10-one.xml',
            RuleXml::patch(RuleXml::object(version: 1, dest: 'a/one.css', source: 'one.v1', create: true))
        );

        foreach ($payloads as $path => $bytes) {
            $zip->addFromString($path, $bytes);
        }

        $zip->close();

        $package = $this->tree->path('package.zip');
        $zip     = new ZipArchive();
        self::assertTrue($zip->open($package, ZipArchive::CREATE));
        $zip->addFile($component, 'packages/com_ecskccustom.zip');
        $zip->close();

        return $package;
    }

    /**
     * @return array{int, string, string}
     */
    private function verify(string $package): array
    {
        return ChildProcess::run([\PHP_BINARY, \dirname(__DIR__, 3) . '/tools/verify_package.php', $package]);
    }
}
