<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
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
        $pipes  = [];
        $process = \proc_open(
            [\PHP_BINARY, $script, '--rules', $rules, '--payload', $payload],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        self::assertIsResource($process);

        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($process), $stdout, $stderr];
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
