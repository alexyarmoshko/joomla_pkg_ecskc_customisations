<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use YakShaver\ECSKCCustomisation\Tests\Support\ChildProcess;
use YakShaver\ECSKCCustomisation\Tests\Support\MakefileList;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

/**
 * The release gates of work items 1, 3 and 4 refuse a tree that must not be tagged
 * or built. Each case runs the repository's Makefile in its own scratch git
 * repository, so the real repository's tags and tree stay untouched.
 *
 * Every case stops at a refusal that comes before `make release` runs the suite,
 * so none of them recurses into it. PackageBytesTest covers the command-line
 * refusal and the version check.
 */
#[CoversNothing]
final class ReleaseGateTest extends TestCase
{
    private const MANIFEST = 'extensions/pkg_ecskc_customisations/pkg_ecskc_customisations.xml';

    private static string $repo;

    private static string $version;

    private static TempTree $scratch;

    public static function setUpBeforeClass(): void
    {
        self::$repo = \dirname(__DIR__, 2);

        $manifest = \simplexml_load_file(self::$repo . '/' . self::MANIFEST);
        self::assertNotFalse($manifest);

        self::$version = (string) $manifest->version;
        self::$scratch = TempTree::create('ecskc-release-gate-');
    }

    public static function tearDownAfterClass(): void
    {
        self::$scratch->remove();
    }

    protected function setUp(): void
    {
        if (!MakefileList::makeIsAvailable()) {
            self::markTestSkipped('make is not on PATH, so the build cannot be driven here');
        }
    }

    public function testReleaseRefusesADirtyTree(): void
    {
        $root = $this->repository('release-dirty');
        self::$scratch->write('release-dirty/stray.txt', "not committed\n");

        $this->assertRefused($root, 'release', 'working tree is dirty');
        $this->assertNoTag($root);
    }

    public function testReleaseRefusesAnExistingTag(): void
    {
        $root = $this->repository('release-tagged');
        $this->git($root, ['tag', self::$version]);

        $this->assertRefused($root, 'release', 'tag ' . self::$version . ' already exists');
    }

    public function testReleaseRefusesMissingReleaseNotes(): void
    {
        $root = $this->repository('release-no-notes', null);

        $this->assertRefused($root, 'release', 'docs/RELEASE.md is missing');
        $this->assertNoTag($root);
    }

    public function testReleaseRefusesNotesWithoutTheVersionHeading(): void
    {
        $root = $this->repository('release-no-heading', "# Release notes\n\n## 9.9.9\n\nAnother version.\n");

        $this->assertRefused($root, 'release', "has no '## " . self::$version . "' heading");
        $this->assertNoTag($root);
    }

    public function testDistReleaseRefusesAMissingTag(): void
    {
        $root = $this->repository('dist-untagged');

        $this->assertRefused($root, 'dist_release', 'no tag ' . self::$version);
        $this->assertNoPackage($root);
    }

    public function testDistReleaseRefusesADirtyTree(): void
    {
        $root = $this->repository('dist-dirty');
        $this->git($root, ['tag', self::$version]);
        self::$scratch->write('dist-dirty/stray.txt', "not committed\n");

        $this->assertRefused($root, 'dist_release', 'working tree is dirty');
        $this->assertNoPackage($root);
    }

    public function testDistReleaseRefusesAHeadThatIsNotTheTag(): void
    {
        $root = $this->repository('dist-past-tag');
        $this->git($root, ['tag', self::$version]);
        self::$scratch->write('dist-past-tag/later.txt', "a commit after the tag\n");
        $this->git($root, ['add', 'later.txt']);
        $this->git($root, ['commit', '-q', '-m', 'After the tag']);

        $this->assertRefused($root, 'dist_release', 'HEAD is not tag ' . self::$version);
        $this->assertNoPackage($root);
    }

    public function testDistReleaseRefusesAMissingPackagerRatherThanFallingBackToZip(): void
    {
        $root = $this->repository('dist-no-jzip');
        $this->git($root, ['tag', self::$version]);

        $output = $this->assertRefused($root, 'dist_release', 'tools/jzip.php is missing');
        self::assertStringNotContainsString('falling back to zip', $output);
        $this->assertNoPackage($root);
    }

    /**
     * A scratch repository holding the Makefile, the package manifest, the update
     * template and, unless `$notes` is null, release notes, all committed. It has
     * no tools/, so a case that got past its gate would fail rather than build.
     */
    private function repository(string $name, ?string $notes = "# Release notes\n\n## %s\n\nThe release.\n"): string
    {
        $files = [
            'Makefile'                            => (string) \file_get_contents(self::$repo . '/Makefile'),
            self::MANIFEST                        => (string) \file_get_contents(self::$repo . '/' . self::MANIFEST),
            'pkg_ecskc_customisations.update.xml' => (string) \file_get_contents(self::$repo . '/pkg_ecskc_customisations.update.xml'),
        ];

        if ($notes !== null) {
            $files['docs/RELEASE.md'] = \sprintf($notes, self::$version);
        }

        foreach ($files as $path => $bytes) {
            self::$scratch->write($name . '/' . $path, $bytes);
        }

        $root = self::$scratch->path($name);

        $this->git($root, ['init', '-q']);
        $this->git($root, ['add', '.']);
        $this->git($root, ['commit', '-q', '-m', 'Scratch release tree']);

        return $root;
    }

    /**
     * Run git in `$root` without the host's signing settings, which would prompt.
     *
     * @param list<string> $args
     */
    private function git(string $root, array $args): void
    {
        [$status, $stdout, $stderr] = ChildProcess::run(\array_merge([
            'git', '-C', $root,
            '-c', 'user.name=Release gate test',
            '-c', 'user.email=release-gate@example.invalid',
            '-c', 'commit.gpgsign=false',
            '-c', 'tag.gpgsign=false',
        ], $args));

        self::assertSame(0, $status, 'git ' . \implode(' ', $args) . ': ' . $stdout . $stderr);
    }

    /**
     * @return string The combined output, for further checks.
     */
    private function assertRefused(string $root, string $target, string $failure): string
    {
        [$status, $stdout, $stderr] = ChildProcess::run(['make', '-s', '-C', $root, $target]);
        $output                     = $stdout . $stderr;

        self::assertNotSame(0, $status, $output);
        self::assertStringContainsString('FAIL: ', $output);
        self::assertStringContainsString($failure, $output);

        return $output;
    }

    private function assertNoTag(string $root): void
    {
        [, $tags] = ChildProcess::run(['git', '-C', $root, 'tag', '--list']);

        self::assertSame('', $tags, 'a refused release must create no tag');
    }

    private function assertNoPackage(string $root): void
    {
        self::assertDirectoryDoesNotExist($root . '/installation/release', 'a refused build must write nothing');
    }
}
