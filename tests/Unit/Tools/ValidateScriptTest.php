<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use YakShaver\ECSKCCustomisation\Tests\Support\ChildProcess;
use YakShaver\ECSKCCustomisation\Tests\Support\RuleXml;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

/**
 * The CLI the Makefile calls. Warnings exit 0; errors exit 1 and name the file.
 */
#[CoversNothing]
final class ValidateScriptTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-validate-cli-');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    /**
     * @return array{int, string, string}
     */
    private function runValidate(string $rules, string $payload): array
    {
        $script = \dirname(__DIR__, 3) . '/tools/validate.php';

        return ChildProcess::run([\PHP_BINARY, $script, '--rules', $rules, '--payload', $payload]);
    }

    /**
     * @return array{int, string, string}
     */
    private function runValidateWithRegistry(string $registry): array
    {
        $repo   = \dirname(__DIR__, 3);
        $script = $repo . '/tools/validate.php';

        return ChildProcess::run([
            \PHP_BINARY,
            $script,
            '--rules',
            $repo . '/data/rules',
            '--payload',
            $repo . '/data/payload',
            '--registry',
            $registry,
        ]);
    }

    public function testTheCommittedRegistryExitsZero(): void
    {
        [$status, $stdout, $stderr] = $this->runValidateWithRegistry(\dirname(__DIR__, 3) . '/data/registry.xml');

        self::assertSame(0, $status, $stderr);
        self::assertSame('', $stderr);
        self::assertSame("build: ok\n", $stdout);
    }

    public function testARegistryThatFailsTheSchemaExitsOneAndNamesTheFile(): void
    {
        $registry = $this->tree->write(
            'registry.xml',
            '<?xml version="1.0" encoding="UTF-8"?><registry><package element="pkg_a"/></registry>'
        );

        [$status, $stdout, $stderr] = $this->runValidateWithRegistry($registry);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('FAIL: ' . $registry, $stderr);
        self::assertStringContainsString("'repo'", $stderr);
        self::assertStringContainsString('no artefact', $stderr);
    }

    public function testAMalformedRegistryExitsOne(): void
    {
        $registry = $this->tree->write('registry.xml', '<registry><package');

        [$status, , $stderr] = $this->runValidateWithRegistry($registry);

        self::assertSame(1, $status);
        self::assertStringContainsString('FAIL: ' . $registry, $stderr);
    }

    public function testAMissingRegistryExitsOne(): void
    {
        $registry = $this->tree->path('absent.xml');

        [$status, , $stderr] = $this->runValidateWithRegistry($registry);

        self::assertSame(1, $status);
        self::assertStringContainsString('FAIL: ' . $registry . ': the registry file does not exist', $stderr);
    }

    public function testTheAuthoredInventoryExitsZero(): void
    {
        $repo = \dirname(__DIR__, 3);
        [$status, $stdout, $stderr] = $this->runValidate(
            $repo . '/data/rules',
            $repo . '/data/payload'
        );

        self::assertSame(0, $status, $stderr);
        self::assertSame('', $stderr);
        self::assertSame("build: ok\n", $stdout);
    }

    public function testASourceHashMismatchExitsOneAndNamesTheObject(): void
    {
        $this->tree->write('rules/index.html', '');
        $this->tree->write('payload/index.html', '');
        $this->tree->write(
            'rules/10-bad.xml',
            RuleXml::patch(RuleXml::object(
                version: 1,
                dest: 'a/one.css',
                source: 'one.v1',
                create: true
            ))
        );
        $this->tree->write('payload/one.v1', 'not the declared bytes');

        [$status, $stdout, $stderr] = $this->runValidate(
            $this->tree->path('rules'),
            $this->tree->path('payload')
        );

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('FAIL:', $stderr);
        self::assertStringContainsString('sourceHash', $stderr);
        self::assertStringContainsString('no artefact', $stderr);
    }

    public function testAWarningExitsZeroSoTheArtefactIsStillPermitted(): void
    {
        $this->tree->write('rules/index.html', '');
        $this->tree->write('payload/index.html', '');
        $this->tree->write(
            'rules/10-warn.xml',
            RuleXml::patch(RuleXml::object(
                version: 1,
                dest: 'a/one.css',
                source: 'one.v1',
                create: false
            ))
        );
        $this->tree->write('payload/one.v1', 'one.v1');

        [$status, $stdout, $stderr] = $this->runValidate(
            $this->tree->path('rules'),
            $this->tree->path('payload')
        );

        self::assertSame(0, $status, $stderr);
        self::assertSame('', $stdout);
        self::assertStringStartsWith('WARNING:', $stderr);
        self::assertStringContainsString('artefact permitted', $stderr);
    }
}
