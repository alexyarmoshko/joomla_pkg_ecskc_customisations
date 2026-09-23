<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Runner;

use Closure;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition\ConditionEvaluator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentProviderInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentResolver;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\RawEnvironment;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\Hasher;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path\PathPolicy;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\MergedSetValidator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleLoader;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\SchemaValidator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Runner;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventLogHistory;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\FreezeMarkers;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ObjectWalker;
use YakShaver\ECSKCCustomisation\Tests\Support\RuleXml;
use YakShaver\ECSKCCustomisation\Tests\Support\RunLockProbe;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

/**
 * The runner assembled as the service provider assembles it, over a real site tree.
 */
abstract class RunnerHarness extends TestCase
{
    protected const PACKAGE = 'administrator/components/com_ecskccustom';

    protected const ORIGINAL = "body { color: red; }\r\n.a { top: 0 }";
    protected const FIRST    = "body { color: blue; }\r\n.a { top: 0 }";
    protected const SECOND   = "body { color: green; }\r\n.a { top: 1 }";
    protected const FOREIGN  = "body { color: rebeccapurple; }\r\n/* edited on the server */";

    protected const VENDOR   = 'templates/et_tennis/css/vendor.css';
    protected const CREATED  = 'templates/et_tennis/css/created.css';
    protected const CHAIN    = 'templates/et_tennis/css/chain.css';
    protected const FOREIGN_TARGET = 'templates/et_tennis/css/foreign.css';
    protected const REMOVED  = 'plugins/system/helixultimate/comingsoon.php';

    protected TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-run-');

        foreach (PackagePaths::ALL_FOLDERS as $folder) {
            \mkdir($this->tree->path(self::PACKAGE . '/' . $folder), 0755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->restoreModes();
        $this->tree->remove();
    }

    /** Modes a test lowered, put back before the tree is removed. */
    private array $lowered = [];

    /**
     * Make a path unwritable for the duration of one test.
     */
    protected function lockDown(string $path, int $mode = 0o500): void
    {
        $this->lowered[$path] = \fileperms($path) & 0o7777;
        \chmod($path, $mode);
    }

    private function restoreModes(): void
    {
        foreach ($this->lowered as $path => $mode) {
            if (\file_exists($path)) {
                \chmod($path, $mode);
            }
        }

        $this->lowered = [];
    }

    protected function paths(): PackagePaths
    {
        return new PackagePaths($this->tree->path(self::PACKAGE));
    }

    protected function policy(): PathPolicy
    {
        return new PathPolicy($this->tree->root(), $this->tree->path(self::PACKAGE));
    }

    /**
     * @param Closure(string, string|null): void|null $checkpoint
     */
    protected function runner(?Closure $checkpoint = null): Runner
    {
        $paths  = $this->paths();
        $policy = $this->policy();

        return new Runner(
            $paths,
            new RuleLoader(new SchemaValidator(
                \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/schema/patch.xsd'
            )),
            new MergedSetValidator($paths->payload()),
            new class () implements EnvironmentProviderInterface {
                public function raw(): RawEnvironment
                {
                    return new RawEnvironment('https://ecskc.eu/', '5.1.2', 'et_tennis', '8.3.6');
                }
            },
            new EnvironmentResolver(),
            new ObjectWalker($policy, new Hasher(), new ConditionEvaluator(), new FreezeMarkers($paths)),
            new EventLogHistory($paths),
            $policy,
            $checkpoint
        );
    }

    protected static function digest(string $bytes): string
    {
        return \hash('sha256', $bytes);
    }

    /**
     * The whole fixture: site files, payloads and one rule file.
     */
    protected function writeFixture(): void
    {
        $this->tree->write(self::VENDOR, self::ORIGINAL);
        $this->tree->write(self::CHAIN, self::ORIGINAL);
        $this->tree->write(self::FOREIGN_TARGET, self::FOREIGN);
        $this->tree->write(self::REMOVED, self::ORIGINAL);

        $this->payload('vendor.css.v1', self::FIRST);
        $this->payload('created.css.v1', self::FIRST);
        $this->payload('chain.css.v1', self::FIRST);
        $this->payload('chain.css.v2', self::SECOND);
        $this->payload('foreign.css.v1', self::FIRST);

        $this->tree->write(self::PACKAGE . '/rules/10-run.xml', RuleXml::patch(
            RuleXml::object(
                version: 1,
                dest: self::VENDOR,
                source: 'vendor.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            )
            . RuleXml::object(
                version: 1,
                dest: self::CREATED,
                source: 'created.css.v1',
                sourceHash: self::digest(self::FIRST),
                create: true
            )
            . RuleXml::object(
                version: 1,
                dest: self::CHAIN,
                source: 'chain.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            )
            . RuleXml::object(
                version: 2,
                dest: self::CHAIN,
                source: 'chain.css.v2',
                sourceHash: self::digest(self::SECOND),
                expectedHash: self::digest(self::FIRST)
            )
            . RuleXml::object(
                version: 1,
                dest: self::FOREIGN_TARGET,
                source: 'foreign.css.v1',
                sourceHash: self::digest(self::FIRST),
                expectedHash: self::digest(self::ORIGINAL)
            )
            . RuleXml::object(
                version: 1,
                dest: self::REMOVED,
                expectedHash: self::digest(self::ORIGINAL)
            )
        ));
    }

    protected function payload(string $name, string $bytes): string
    {
        return $this->tree->write(self::PACKAGE . '/payload/' . $name, $bytes);
    }

    protected function target(RunReport $report, string $targetPath): TargetReport
    {
        foreach ($report->targets() as $target) {
            if ($target->targetPath() === $targetPath) {
                return $target;
            }
        }

        self::fail(\sprintf('the run reported no line for %s', $targetPath));
    }

    /**
     * Every path under the tree, with its mode and its bytes. Nothing is excluded.
     *
     * @return array<string, string>
     */
    protected function snapshot(): array
    {
        $snapshot = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tree->root(), \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $path => $info) {
            $path = (string) $path;

            $snapshot[$path] = \sprintf(
                '%o %s',
                (int) \fileperms($path),
                \is_dir($path) ? 'dir' : (string) \hash_file('sha256', $path)
            );
        }

        \ksort($snapshot);

        return $snapshot;
    }

    /**
     * Every committed record in the event log, as its decoded payload.
     *
     * @return list<array<string, mixed>>
     */
    protected function events(): array
    {
        $path = $this->paths()->eventLog();

        if (!\is_file($path)) {
            return [];
        }

        $records = [];

        foreach (\explode("\n", (string) \file_get_contents($path)) as $line) {
            if ($line === '') {
                continue;
            }

            $json = \explode("\t", $line)[0];
            $data = \json_decode($json, true);

            if (\is_array($data)) {
                $records[] = $data;
            }
        }

        return $records;
    }

    /**
     * Can a fresh handle take the run lock? Proof no run is still holding it.
     */
    protected function lockIsFree(): bool
    {
        return RunLockProbe::isFree($this->paths());
    }

    /**
     * @return list<string>
     */
    protected function backupNames(): array
    {
        $directory = $this->paths()->backup();
        $names     = [];

        if (!\is_dir($directory)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $path => $info) {
            $names[] = \substr((string) $path, \strlen($directory) + 1);
        }

        \sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    protected function stagingNames(): array
    {
        $directory = $this->paths()->staging();
        $names     = \array_values(\array_diff(\scandir($directory) ?: [], ['.', '..']));

        \sort($names);

        return $names;
    }
}
