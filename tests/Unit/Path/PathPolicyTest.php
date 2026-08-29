<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Path;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path\PathDecision;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path\PathPolicy;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

/**
 * Run against a real temporary site tree, as decision 6 requires: symbolic links
 * and the difference between a path that exists and one that does not are the whole
 * subject here, and neither survives an in-memory filesystem.
 *
 * Hostile inputs are built as plain strings. TempTree's own resolve() refuses most
 * of them, and using it to construct them would test the helper rather than the
 * policy.
 */
#[CoversClass(PathPolicy::class)]
#[CoversClass(PathDecision::class)]
final class PathPolicyTest extends TestCase
{
    private const PACKAGE = 'administrator/components/com_ecskccustom';

    private TempTree $tree;

    private PathPolicy $policy;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-site-');

        // A site tree with the shapes the policy has to tell apart.
        $this->tree->write(self::PACKAGE . '/rules/10-kunena.xml', 'a rule');
        $this->tree->write(self::PACKAGE . '/payload/one.css', 'a payload');
        $this->tree->write('templates/et_tennis/css/override.css', 'a stylesheet');
        $this->tree->write('configuration.php', 'the site configuration');
        $this->tree->write('.htaccess', 'server configuration');
        $this->tree->write('cache/page/stale.php', 'cached output');
        $this->tree->write('cached/keeper.css', 'not the cache directory');

        $this->policy = new PathPolicy($this->tree->root(), $this->tree->path(self::PACKAGE));
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    public function testAnOrdinaryTargetIsAllowedAndCarriesItsResolvedPath(): void
    {
        $decision = $this->policy->decide('templates/et_tennis/css/override.css');

        self::assertTrue($decision->allowed, (string) $decision->reason);
        self::assertSame($this->tree->path('templates/et_tennis/css/override.css'), $decision->path);
    }

    /**
     * A rule may create a file, so the target need not exist. realpath() on the whole
     * path says nothing in that case; the deepest existing ancestor is what can be
     * canonicalised.
     */
    public function testATargetThatDoesNotExistYetIsResolvedFromItsDeepestExistingAncestor(): void
    {
        $decision = $this->policy->decide('templates/et_tennis/css/new/deeper/added.css');

        self::assertTrue($decision->allowed, (string) $decision->reason);
        self::assertSame(
            $this->tree->path('templates/et_tennis/css') . '/new/deeper/added.css',
            $decision->path
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function lexicallyRefusedTargets(): array
    {
        return [
            'empty'              => ['', 'the target path is empty'],
            'a leading slash'    => ['/templates/a.css', 'canonical form'],
            'a backslash'        => ['templates\\a.css', 'not a path separator'],
            'a traversal'        => ['templates/../../etc/passwd', "'..' is not a path segment"],
            'a bare traversal'   => ['..', "'..' is not a path segment"],
            'a dot segment'      => ['templates/./a.css', "'.' is not a path segment"],
            'a doubled slash'    => ['templates//a.css', 'an empty segment is not a path segment'],
        ];
    }

    #[DataProvider('lexicallyRefusedTargets')]
    public function testAMalformedTargetIsRefusedWithItsOwnReason(string $target, string $expected): void
    {
        $decision = $this->policy->decide($target);

        self::assertFalse($decision->allowed);
        self::assertNull($decision->path);
        self::assertStringContainsString($expected, (string) $decision->reason);
    }

    /**
     * The guarantee, as distinct from the lexical checks above: a link is followed
     * and the question asked of where the path landed, not of how it was spelled.
     */
    public function testASymbolicLinkCannotLeaveTheSiteRoot(): void
    {
        $this->tree->symlink('escape', '/etc');

        $decision = $this->policy->decide('escape/passwd');

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('resolves outside the site root', (string) $decision->reason);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function danglingLinks(): array
    {
        return [
            'pointing outside the site root' => ['escape.css', '/nowhere/not-yet-created.css'],
            'pointing into a denied tree'    => ['into-denied.css', 'cache/not-yet.css'],
            'pointing nowhere in particular' => ['broken.css', 'no/such/file.css'],
        ];
    }

    /**
     * realpath() fails the same way for a file that is not there and for a symbolic
     * link whose destination is not there. Read as a missing component, such a link
     * keeps its own name in the resolved path and passes confinement, while a write
     * through it lands wherever the link points. Measured before this was fixed: a
     * link to a not-yet-created file outside the site root was reported as an in-root
     * target, and writing to it created the file outside.
     */
    #[DataProvider('danglingLinks')]
    public function testADanglingSymbolicLinkIsRefusedRatherThanTreatedAsAMissingFile(
        string $name,
        string $destination,
    ): void {
        $this->tree->symlink($name, \str_starts_with($destination, '/')
            ? $destination
            : $this->tree->root() . '/' . $destination);

        $decision = $this->policy->decide($name);

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('destination does not exist', (string) $decision->reason);
    }

    /**
     * A dangling link as a parent, not as the target itself.
     */
    public function testADanglingSymbolicLinkIsRefusedWhenItIsAParentDirectory(): void
    {
        $this->tree->symlink('bridge', $this->tree->root() . '/no-such-directory');

        $decision = $this->policy->decide('bridge/deeper/added.css');

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('destination does not exist', (string) $decision->reason);
    }

    /**
     * The refusal above is about the link having no destination, not about it being a
     * link. One that resolves inside the site root is an ordinary target.
     */
    public function testALinkThatResolvesInsideTheSiteRootIsAllowed(): void
    {
        $this->tree->symlink('shortcut.css', $this->tree->path('templates/et_tennis/css/override.css'));

        $decision = $this->policy->decide('shortcut.css');

        self::assertTrue($decision->allowed, (string) $decision->reason);
        self::assertSame($this->tree->path('templates/et_tennis/css/override.css'), $decision->path);
    }

    /**
     * There is no canonical spelling for the site root - a target is relative to it
     * with no dot segments - but a link can still land on it. It is a directory, and
     * every check below resolution has to survive being handed one.
     */
    public function testALinkThatLandsOnTheSiteRootIsNotATarget(): void
    {
        $this->tree->symlink('whole-site', $this->tree->root());

        $decision = $this->policy->decide('whole-site');

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('allow list', (string) $decision->reason);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function packageTargets(): array
    {
        return [
            'the rule set'   => [self::PACKAGE . '/rules/10-kunena.xml'],
            'a payload'      => [self::PACKAGE . '/payload/one.css'],
            'the tree root'  => [self::PACKAGE . '/anything.css'],
        ];
    }

    /**
     * Confinement cannot catch these: the component lives inside the site root.
     */
    #[DataProvider('packageTargets')]
    public function testTheDenyListRefusesThePackagesOwnTreeByDirectPath(string $target): void
    {
        $decision = $this->policy->decide($target);

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('must not patch itself', (string) $decision->reason);
    }

    /**
     * The same tree reached by another route. A deny list matched before resolution
     * would pass this, which is why it is matched after.
     */
    public function testTheDenyListRefusesThePackagesOwnTreeThroughASymbolicLinkAlias(): void
    {
        $this->tree->symlink('alias', $this->tree->path(self::PACKAGE));

        $decision = $this->policy->decide('alias/payload/one.css');

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('must not patch itself', (string) $decision->reason);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function deniedNames(): array
    {
        return [
            'the site configuration'      => ['configuration.php'],
            'a configuration lower down'  => ['administrator/configuration.php'],
            'the root htaccess'           => ['.htaccess'],
            'an htaccess lower down'      => ['templates/et_tennis/.htaccess'],
            'a web.config'                => ['web.config'],
        ];
    }

    #[DataProvider('deniedNames')]
    public function testTheDenyListRefusesServerAndSiteConfigurationAtEveryDepth(string $target): void
    {
        $decision = $this->policy->decide($target);

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('the deny list refuses every', (string) $decision->reason);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function deniedTrees(): array
    {
        return [
            'the cache'               => ['cache/page/stale.php', 'cache/'],
            'the cache itself'        => ['cache', 'cache/'],
            'the admin cache'         => ['administrator/cache/x.php', 'administrator/cache/'],
            'the temporary directory' => ['tmp/upload.php', 'tmp/'],
            'the logs'                => ['logs/error.php', 'logs/'],
            'the real logs'           => ['administrator/logs/error.php', 'administrator/logs/'],
        ];
    }

    #[DataProvider('deniedTrees')]
    public function testTheDenyListRefusesWholeTrees(string $target, string $tree): void
    {
        $decision = $this->policy->decide($target);

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('everything under ' . $tree, (string) $decision->reason);
    }

    /**
     * A denied tree is a directory, not a prefix of a name. `cached/` shares six
     * characters with `cache/` and is an ordinary directory.
     */
    public function testADirectoryWhoseNameStartsWithADeniedOneIsNotDenied(): void
    {
        $decision = $this->policy->decide('cached/keeper.css');

        self::assertTrue($decision->allowed, (string) $decision->reason);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedExtensions(): array
    {
        $cases = [];

        foreach (PathPolicy::ALLOWED_EXTENSIONS as $extension) {
            $cases['a .' . $extension] = ['media/asset.' . $extension];
        }

        $cases['an upper-case extension'] = ['media/ASSET.CSS'];

        return $cases;
    }

    #[DataProvider('allowedExtensions')]
    public function testTheAllowListAcceptsEveryTypeItNames(string $target): void
    {
        $decision = $this->policy->decide($target);

        self::assertTrue($decision->allowed, (string) $decision->reason);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedExtensions(): array
    {
        return [
            // Brief section 6 singles out params.ini as saved settings that must
            // never be shipped. The allow list is what keeps it unreachable.
            'a settings file'      => ['components/com_kunena/params.ini'],
            'a shell script'       => ['media/deploy.sh'],
            'an archive'           => ['media/backup.zip'],
            'a file with no type'  => ['media/README'],
            'a dotfile'            => ['media/.gitignore'],
        ];
    }

    #[DataProvider('refusedExtensions')]
    public function testTheAllowListRefusesEveryTypeItDoesNotName(string $target): void
    {
        $decision = $this->policy->decide($target);

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('not on the extension allow list', (string) $decision->reason);
    }

    /**
     * The case the two lists exist to settle between them. `configuration.php` has an
     * allowed extension at a denied path, so the deny list has to win - and the
     * reason reported has to be the deny list's, since that is what the author got
     * wrong.
     */
    public function testWhereTheListsDisagreeTheDenyListWinsAndSaysSo(): void
    {
        $decision = $this->policy->decide('configuration.php');

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('the deny list refuses', (string) $decision->reason);
        self::assertStringNotContainsString('allow list', (string) $decision->reason);
    }

    public function testASiteRootThatDoesNotExistIsRefusedOutright(): void
    {
        $this->expectException(RuntimeException::class);

        new PathPolicy($this->tree->root() . '/absent', $this->tree->path(self::PACKAGE));
    }

    /**
     * The build runs this policy with no package installed, so a package root that
     * does not exist must still be denied by its position rather than ignored.
     */
    public function testAPackageRootThatIsNotInstalledYetIsStillDenied(): void
    {
        $policy = new PathPolicy($this->tree->root(), $this->tree->root() . '/administrator/components/com_absent');

        $decision = $policy->decide('administrator/components/com_absent/rules/10.xml');

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('must not patch itself', (string) $decision->reason);
    }

    /**
     * The two above meeting: a site root reached through a symbolic link, and a
     * package not on disk. Targets resolve to the link's destination, so a package
     * root kept as the caller spelled it never matches one - and the guard that
     * stops the patch patching itself is inert, with nothing to say it.
     */
    public function testThePackageTreeIsStillDeniedUnderASymbolicallyLinkedSiteRoot(): void
    {
        $this->tree->write('real/index.php', 'the site');
        $this->tree->symlink('link', $this->tree->path('real'));

        $linkedRoot = $this->tree->root() . '/link';
        $policy     = new PathPolicy($linkedRoot, $linkedRoot . '/' . self::PACKAGE);

        $decision = $policy->decide(self::PACKAGE . '/payload/one.css');

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('must not patch itself', (string) $decision->reason);
    }
}
