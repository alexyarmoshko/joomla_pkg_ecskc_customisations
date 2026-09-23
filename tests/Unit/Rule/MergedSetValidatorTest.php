<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\MergedSetValidator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleLoader;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleSet;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\SchemaValidator;
use YakShaver\ECSKCCustomisation\Tests\Support\RuleXml;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

#[CoversClass(MergedSetValidator::class)]
#[CoversClass(RuleSet::class)]
final class MergedSetValidatorTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-merged-');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    /**
     * Runs the real pipeline: files on disk, loaded in byte order, schema-validated,
     * merged, then checked. A test that hand-built a RuleSet would prove the checks
     * work on a shape the loader might never produce.
     *
     * @param array<string, string> $files    Rule file name to contents.
     * @param list<string>          $payloads Payload paths to lay down.
     *
     * @return list<string>
     */
    private function validate(array $files, array $payloads): array
    {
        // Both folders ship with their own index.html, so every fixture has one:
        // the loader must ignore it, and the payload check must not call it a payload.
        $this->tree->write('rules/index.html', '');
        $this->tree->write('payload/index.html', '');

        foreach ($files as $name => $contents) {
            $this->tree->write('rules/' . $name, $contents);
        }

        foreach ($payloads as $payload) {
            $this->tree->write('payload/' . $payload, 'bytes of ' . $payload);
        }

        $loader = new RuleLoader(new SchemaValidator(
            \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/schema/patch.xsd'
        ));

        $result = $loader->load($this->tree->path('rules'));

        self::assertSame([], $result->violations, 'The fixture must load cleanly to test the merged set.');

        return (new MergedSetValidator($this->tree->path('payload')))->validate($result->ruleSet);
    }

    public function testASoundRuleSetHasNoViolations(): void
    {
        $violations = $this->validate([
            '10-helix.xml' => RuleXml::patch(RuleXml::root(
                'templates/et_tennis/css',
                RuleXml::object(version: 1, dest: 'override.css', source: 'override.css.v1', create: true)
            )),
            '20-kunena.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'media/kunena/style.css', source: 'kunena/style.css')
            ),
        ], ['override.css.v1', 'kunena/style.css']);

        self::assertSame([], $violations);
    }

    /**
     * The whole reason the set is merged before it is checked: neither half of this
     * is wrong on its own.
     */
    public function testADuplicateVersionIsFoundAcrossTwoRuleFiles(): void
    {
        $violations = $this->validate([
            '10-first.xml'  => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/one.css', source: 'one.v1', create: true)
            ),
            '20-second.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/one.css', source: 'one.other', create: true)
            ),
        ], ['one.v1', 'one.other']);

        self::assertCount(1, $violations);
        self::assertSame(
            '20-second.xml: object a/one.css version 1: version 1 appears twice at this target, '
            . 'first at 10-first.xml: object a/one.css version 1. A repeated version makes the '
            . 'walk ambiguous and the backup names collide.',
            $violations[0]
        );
    }

    public function testObjectsOutOfVersionOrderAreRejected(): void
    {
        $violations = $this->validate([
            '10-order.xml' => RuleXml::patch(
                RuleXml::object(version: 2, dest: 'a/one.css', source: 'one.v2', create: true)
                . RuleXml::object(version: 1, dest: 'a/one.css', source: 'one.v1', create: true)
            ),
        ], ['one.v1', 'one.v2']);

        self::assertCount(1, $violations);
        self::assertSame(
            '10-order.xml: object a/one.css version 1: appears after version 2, so the objects '
            . 'at a/one.css are out of version order',
            $violations[0]
        );
    }

    public function testAPackageOwnedChainThatLinksUpAcrossTwoFilesIsAccepted(): void
    {
        $violations = $this->validate([
            '10-first.xml'  => RuleXml::patch(RuleXml::object(
                version: 1,
                dest: 'a/one.css',
                source: 'one.v1',
                sourceHash: RuleXml::hash('v1'),
                create: true
            )),
            '20-second.xml' => RuleXml::patch(RuleXml::object(
                version: 2,
                dest: 'a/one.css',
                source: 'one.v2',
                sourceHash: RuleXml::hash('v2'),
                expectedHash: RuleXml::hash('v1')
            )),
        ], ['one.v1', 'one.v2']);

        self::assertSame([], $violations);
    }

    public function testABrokenPackageOwnedChainIsRejected(): void
    {
        $violations = $this->validate([
            '10-chain.xml' => RuleXml::patch(
                RuleXml::object(
                    version: 1,
                    dest: 'a/one.css',
                    source: 'one.v1',
                    sourceHash: RuleXml::hash('v1'),
                    create: true
                )
                . RuleXml::object(
                    version: 2,
                    dest: 'a/one.css',
                    source: 'one.v2',
                    sourceHash: RuleXml::hash('v2'),
                    expectedHash: RuleXml::hash('a typo')
                )
            ),
        ], ['one.v1', 'one.v2']);

        self::assertCount(1, $violations);
        self::assertSame(
            '10-chain.xml: object a/one.css version 2: expects a hash no earlier object at this '
            . 'target writes, so the package-owned chain does not link up',
            $violations[0]
        );
    }

    /**
     * A link written in the other case is the same link. Hex digests are
     * case-insensitive and the schema accepts both, so comparing them literally
     * would report a sound chain as broken.
     */
    public function testAChainLinksThroughAnUppercaseDigest(): void
    {
        $violations = $this->validate([
            '10-chain.xml' => RuleXml::patch(
                RuleXml::object(
                    version: 1,
                    dest: 'a/one.css',
                    source: 'one.v1',
                    sourceHash: RuleXml::hash('v1'),
                    create: true
                )
                . RuleXml::object(
                    version: 2,
                    dest: 'a/one.css',
                    source: 'one.v2',
                    sourceHash: RuleXml::hash('v2'),
                    expectedHash: \strtoupper(RuleXml::hash('v1'))
                )
            ),
        ], ['one.v1', 'one.v2']);

        self::assertSame([], $violations);
    }

    /**
     * The mistake the chain check exists to catch, in its commonest form: the
     * expectedHash copy-pasted from the sourceHash directly above it. Nothing
     * earlier at this target writes that hash, so the object links to itself and
     * the walk can never reach it.
     */
    public function testAnObjectMayNotLinkToItsOwnPayload(): void
    {
        $violations = $this->validate([
            '10-selflink.xml' => RuleXml::patch(
                RuleXml::object(
                    version: 1,
                    dest: 'a/one.css',
                    source: 'one.v1',
                    sourceHash: RuleXml::hash('v1'),
                    create: true
                )
                . RuleXml::object(
                    version: 2,
                    dest: 'a/one.css',
                    source: 'one.v2',
                    sourceHash: RuleXml::hash('v2'),
                    expectedHash: RuleXml::hash('v2')
                )
            ),
        ], ['one.v1', 'one.v2']);

        self::assertSame(
            ['10-selflink.xml: object a/one.css version 2: expects a hash no earlier object at '
                . 'this target writes, so the package-owned chain does not link up'],
            $violations
        );
    }

    /**
     * The counterpart to the chain check, and the reason it reads ownership from the
     * permissions. These two objects are alternatives for different vendor builds,
     * each expecting an original the package never wrote. Treating every unmatched
     * expectation as a broken link would reject the entire vendor half of the
     * inventory - 7 of the 12 managed files.
     */
    public function testVendorAlternativesExpectingOriginalsThePackageNeverWroteAreAccepted(): void
    {
        $violations = $this->validate([
            '10-vendor.xml' => RuleXml::patch(
                RuleXml::object(
                    version: 1,
                    dest: 'components/com_comprofiler/one.php',
                    source: 'cb/one.php.build1',
                    expectedHash: RuleXml::hash('vendor build 1')
                )
                . RuleXml::object(
                    version: 2,
                    dest: 'components/com_comprofiler/one.php',
                    source: 'cb/one.php.build2',
                    expectedHash: RuleXml::hash('vendor build 2')
                )
            ),
        ], ['cb/one.php.build1', 'cb/one.php.build2']);

        self::assertSame([], $violations);
    }

    public function testAFirstObjectVendorNoCopyRemovalIsRejected(): void
    {
        // backup="false" keeps no predecessor, and a first vendor object never
        // shipped the original it expects, so revert would be permanently one-way.
        $violations = $this->validate([
            '10-vendor-remove.xml' => RuleXml::patch(RuleXml::object(
                version: 1,
                dest: 'components/com_comprofiler/one.php',
                expectedHash: RuleXml::hash('vendor build 1'),
                backup: false
            )),
        ], []);

        self::assertSame(
            ['10-vendor-remove.xml: object components/com_comprofiler/one.php version 1: removes '
                . 'with backup="false" and expects a hash no earlier object at this target ships, '
                . 'so revert can never restore the predecessor'],
            $violations
        );
    }

    public function testANoCopyRemovalThatNamesNoExpectedOriginalIsRejected(): void
    {
        // No expectedHash means the removal matches whatever file is there. It keeps
        // no copy of it and names no state, so revert has nothing to put back and
        // the bytes it took away may be ones the package never saw.
        $violations = $this->validate([
            '10-unconditional-remove.xml' => RuleXml::patch(RuleXml::object(
                version: 1,
                dest: 'components/com_comprofiler/one.php',
                backup: false
            )),
        ], []);

        self::assertSame(
            ['10-unconditional-remove.xml: object components/com_comprofiler/one.php version 1: '
                . 'removes with backup="false" and names no expected original, so it may take away '
                . 'any file it finds and leaves revert nothing to restore'],
            $violations
        );
    }

    public function testANoCopyRemovalWhosePredecessorIsShippedIsAccepted(): void
    {
        $violations = $this->validate([
            '10-no-copy.xml' => RuleXml::patch(
                RuleXml::object(
                    version: 1,
                    dest: 'a/one.css',
                    source: 'one.v1',
                    sourceHash: RuleXml::hash('v1'),
                    create: true
                )
                . RuleXml::object(
                    version: 2,
                    dest: 'a/one.css',
                    expectedHash: RuleXml::hash('v1'),
                    create: true,
                    backup: false
                )
            ),
        ], ['one.v1']);

        self::assertSame([], $violations);
    }

    public function testATargetNamedInBothAbsoluteAndRelativeFormIsRejected(): void
    {
        $violations = $this->validate([
            '10-spelling.xml' => RuleXml::patch(RuleXml::root(
                'templates/et_tennis/css',
                RuleXml::object(version: 1, dest: 'override.css', source: 'one.v1', create: true)
                . RuleXml::object(
                    version: 2,
                    dest: '/templates/et_tennis/css/override.css',
                    source: 'one.v2',
                    create: true
                )
            )),
        ], ['one.v1', 'one.v2']);

        self::assertCount(1, $violations);
        self::assertSame(
            'target templates/et_tennis/css/override.css is named in both absolute and relative '
            . 'form: absolute at 10-spelling.xml: object templates/et_tennis/css/override.css '
            . 'version 2, relative at 10-spelling.xml: object templates/et_tennis/css/override.css '
            . 'version 1',
            $violations[0]
        );
    }

    public function testAnObjectThatMayNeitherCreateNorReplaceIsRejected(): void
    {
        $violations = $this->validate([
            '10-dead.xml' => RuleXml::patch(RuleXml::object(
                version: 1,
                dest: 'a/one.css',
                source: 'one.v1',
                create: false,
                replace: false
            )),
        ], ['one.v1']);

        self::assertSame(
            ['10-dead.xml: object a/one.css version 1: may neither create nor replace, so it can '
                . 'do nothing'],
            $violations
        );
    }

    public function testAMissingPayloadIsRejectedAndNamesTheObjectThatWantsIt(): void
    {
        $violations = $this->validate([
            '10-missing.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/one.css', source: 'one.v1', create: true)
            ),
        ], []);

        self::assertSame(
            ['10-missing.xml: object a/one.css version 1: names the payload one.v1, which does not exist'],
            $violations
        );
    }

    public function testAPayloadNamedByTwoObjectsIsRejected(): void
    {
        $violations = $this->validate([
            '10-shared.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/one.css', source: 'shared.css', create: true)
                . RuleXml::object(version: 1, dest: 'b/two.css', source: 'shared.css', create: true)
            ),
        ], ['shared.css']);

        self::assertSame(
            ['payload shared.css is named more than once, at 10-shared.xml: object a/one.css '
                . 'version 1, 10-shared.xml: object b/two.css version 1. A payload belongs to '
                . 'exactly one object.'],
            $violations
        );
    }

    public function testAPayloadNoObjectNamesIsRejected(): void
    {
        $violations = $this->validate([
            '10-one.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/one.css', source: 'one.v1', create: true)
            ),
        ], ['one.v1', 'nested/forgotten.css']);

        self::assertSame(
            ['payload nested/forgotten.css is present but no object names it. An unreferenced '
                . 'payload ships bytes nothing will ever write.'],
            $violations
        );
    }

    /**
     * index.html is the folder's own guard. Reporting it as an unreferenced payload
     * would make every sound rule set fail.
     */
    public function testTheFolderGuardIsNotMistakenForAnUnreferencedPayload(): void
    {
        $violations = $this->validate([
            '10-one.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/one.css', source: 'nested/one.v1', create: true)
            ),
        ], ['nested/one.v1']);

        self::assertSame([], $violations);
    }

    /**
     * The installer writes a deny-all .htaccess into payload/ on every install. Were
     * it counted as a payload, every installed site would report its own guard as an
     * unreferenced payload and fail its own rule set.
     */
    public function testThePackagesOwnGuardFilesAreNotPayloads(): void
    {
        $this->tree->write('payload/.htaccess', 'Require all denied');
        $this->tree->write('payload/.htaccess.abc123abc123.tmp', 'an interrupted write');

        $violations = $this->validate([
            '10-one.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/one.css', source: 'one.v1', create: true)
            ),
        ], ['one.v1']);

        self::assertSame([], $violations);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nearMissesForAGuardName(): array
    {
        return [
            'a payload whose name starts with the guard' => ['.htaccess.css'],
            'a longer name with the same prefix'         => ['.htaccessible.css'],
            'the guard name one level down'              => ['nested/.htaccess'],
            'the folder index one level down'            => ['nested/index.html'],
        ];
    }

    /**
     * The guard is matched exactly, and only at the payload folder's own root. A
     * prefix match would be simpler and would swallow every name below, and a payload
     * hidden from this check is a check that stopped running.
     */
    #[DataProvider('nearMissesForAGuardName')]
    public function testAFileThatMerelyResemblesAGuardIsStillJudgedAsAPayload(string $name): void
    {
        $violations = $this->validate([
            '10-one.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/one.css', source: 'one.v1', create: true)
            ),
        ], ['one.v1', $name]);

        self::assertSame(
            ['payload ' . $name . ' is present but no object names it. An unreferenced payload '
                . 'ships bytes nothing will ever write.'],
            $violations
        );
    }

    /**
     * The schema accepts 32 to 128 hex characters, because the algorithm is read from
     * the declaration and the shape layer cannot know the width. A file declaring
     * sha256 while carrying md5 digests is internally consistent - the chain links,
     * every other check passes - and would only fail once real bytes are hashed, at
     * which point no target ever matches again.
     */
    public function testADigestOfTheWrongWidthForItsAlgorithmIsRejected(): void
    {
        $violations = $this->validate([
            '10-md5ish.xml' => RuleXml::patch(
                RuleXml::object(
                    version: 1,
                    dest: 'a/one.css',
                    source: 'one.v1',
                    sourceHash: \md5('v1'),
                    create: true
                )
            ),
        ], ['one.v1']);

        self::assertCount(1, $violations);
        self::assertStringContainsString('is 32 characters; sha256 produces 64', $violations[0]);
        self::assertStringStartsWith('10-md5ish.xml: object a/one.css version 1:', $violations[0]);
    }

    public function testAnUnsupportedHashAlgorithmIsRejectedOncePerFile(): void
    {
        $violations = $this->validate([
            '10-md5.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/one.css', source: 'one.v1', create: true)
                . RuleXml::object(version: 1, dest: 'b/two.css', source: 'two.v1', create: true),
                'md5'
            ),
        ], ['one.v1', 'two.v1']);

        self::assertSame(
            ["10-md5.xml: the rule declares hashType 'md5'; version 1 supports sha256 only"],
            $violations
        );
    }

    /**
     * With three objects at one version, the third must still be sent to the first.
     * The author is being told where to go and compare.
     */
    public function testARepeatedVersionAlwaysNamesTheFirstObjectAtThatVersion(): void
    {
        $violations = $this->validate([
            '10-thrice.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/one.css', source: 'one.a', create: true)
                . RuleXml::object(version: 1, dest: 'a/one.css', source: 'one.b', create: true)
                . RuleXml::object(version: 1, dest: 'a/one.css', source: 'one.c', create: true)
            ),
        ], ['one.a', 'one.b', 'one.c']);

        self::assertCount(2, $violations);

        foreach ($violations as $violation) {
            self::assertStringContainsString('first at 10-thrice.xml: object a/one.css version 1', $violation);
        }
    }

    public function testOneObjectMayNameOnePayloadFromTwoActions(): void
    {
        $copy = '<action><operations><copy>'
            . '<srcPathFileName>one.v1</srcPathFileName>'
            . '<sourceHash>' . RuleXml::hash('one.v1') . '</sourceHash>'
            . '</copy></operations></action>';

        $violations = $this->validate([
            '10-twice.xml' => RuleXml::patch(
                '<object version="1" create="true">'
                . '<id><destPathFileName>a/one.css</destPathFileName></id>'
                . $copy . $copy
                . '</object>'
            ),
        ], ['one.v1']);

        self::assertSame([], $violations);
    }

    /**
     * Payload paths are cut out of a full pathname by length, so a directory handed
     * in with a trailing separator would shift every one of them by a character and
     * report the whole tree as unreferenced.
     */
    public function testATrailingSeparatorOnThePayloadDirectoryChangesNothing(): void
    {
        $this->tree->write('rules/index.html', '');
        $this->tree->write('payload/one.v1', 'bytes');
        $this->tree->write('rules/10-one.xml', RuleXml::patch(
            RuleXml::object(version: 1, dest: 'a/one.css', source: 'one.v1', create: true)
        ));

        $loader = new RuleLoader(new SchemaValidator(
            \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/schema/patch.xsd'
        ));

        $ruleSet = $loader->load($this->tree->path('rules'))->ruleSet;

        self::assertSame([], (new MergedSetValidator($this->tree->path('payload')))->validate($ruleSet));
        self::assertSame([], (new MergedSetValidator($this->tree->path('payload') . '/'))->validate($ruleSet));
    }

    /**
     * The schema lets a patch element carry no objects at all. Judged from the
     * objects, such a file declares an algorithm nothing ever looks at.
     */
    public function testAnEmptyRuleFileStillHasItsHashAlgorithmJudged(): void
    {
        $violations = $this->validate([
            '10-empty.xml' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<patch hashType="md5"/>',
        ], []);

        self::assertSame(
            ["10-empty.xml: the rule declares hashType 'md5'; version 1 supports sha256 only"],
            $violations
        );
    }

    /**
     * A payload is bytes the package ships. A link is a promise about bytes somewhere
     * else, and whatever hashes and copies it later would take them from there -
     * measured: a link planted in payload/ read back a file from outside the tree.
     */
    public function testAPayloadThatIsASymbolicLinkIsRefused(): void
    {
        $this->tree->write('rules/index.html', '');
        $this->tree->symlink('payload/leak.css', $this->tree->path('rules/index.html'));

        $violations = $this->validate([
            '10-one.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/one.css', source: 'leak.css', create: true)
            ),
        ], []);

        self::assertContains(
            'payload leak.css is a symbolic link. A payload is bytes the package ships, so it '
                . 'has to be a file in the payload directory.',
            $violations
        );
        self::assertStringContainsString(
            'resolves outside the payload directory',
            \implode("\n", $violations)
        );
    }

    /**
     * The same escape one level up: the payload itself is an ordinary file, and the
     * directory holding it is the link. RecursiveDirectoryIterator does not descend
     * into one, so a scan that merely skipped what is not a file would see nothing at
     * all.
     */
    public function testAPayloadReachedThroughASymbolicallyLinkedDirectoryIsRefused(): void
    {
        $this->tree->write('rules/index.html', '');
        $this->tree->symlink('payload/viadir', $this->tree->path('rules'));

        $violations = $this->validate([
            '10-one.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/one.css', source: 'viadir/index.html', create: true)
            ),
        ], []);

        self::assertStringContainsString(
            'resolves outside the payload directory',
            \implode("\n", $violations)
        );
        self::assertStringContainsString('payload viadir is a symbolic link', \implode("\n", $violations));
    }

    public function testAMissingPayloadDirectoryIsReported(): void
    {
        $this->tree->write('rules/10-one.xml', RuleXml::patch(
            RuleXml::object(version: 1, dest: 'a/one.css', source: 'one.v1', create: true)
        ));

        $loader = new RuleLoader(new SchemaValidator(
            \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/schema/patch.xsd'
        ));

        $absent     = $this->tree->root() . '/payload';
        $violations = (new MergedSetValidator($absent))
            ->validate($loader->load($this->tree->path('rules'))->ruleSet);

        self::assertContains($absent . ': the payload directory does not exist', $violations);
    }

    /**
     * A directory the account cannot list throws out of the iterator, and an exception
     * from here would leave the runner with no report at all - the one thing a check
     * always has to produce. Review s1.v53 spec finding 1. The scan cannot see what
     * the closed directory holds, so it says so instead of judging the set complete.
     */
    public function testAnUnreadablePayloadDirectoryIsReportedRatherThanThrown(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('Permission bits do not stop the superuser listing the directory.');
        }

        $this->tree->write('payload/nested/one.v1', 'bytes');
        $closed = $this->tree->path('payload/nested');
        \chmod($closed, 0o000);

        try {
            $violations = $this->validate([
                '10-one.xml' => RuleXml::patch(
                    RuleXml::object(version: 1, dest: 'a/one.css', source: 'nested/one.v1', create: true)
                ),
            ], []);
        } finally {
            \chmod($closed, 0o755);
        }

        self::assertContains(
            'the payload directory nested cannot be read. A directory that cannot be listed may '
                . 'hold a payload, so the set cannot be judged complete.',
            $violations
        );
    }

    /**
     * The root fails one step earlier, in the iterator's constructor rather than in a
     * descent, so it needs its own guard. Nothing below it can be judged, and the
     * directory is named in full because there is no payload-relative path to name.
     */
    public function testAPayloadDirectoryThatCannotBeOpenedAtAllIsReported(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('Permission bits do not stop the superuser listing the directory.');
        }

        $this->tree->write('payload/index.html', '');
        $closed = $this->tree->path('payload');
        \chmod($closed, 0o000);

        $loader = new RuleLoader(new SchemaValidator(
            \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/schema/patch.xsd'
        ));

        $this->tree->write('rules/10-one.xml', RuleXml::patch(
            RuleXml::object(version: 1, dest: 'a/one.css', source: 'one.v1', create: true)
        ));

        try {
            $violations = (new MergedSetValidator($closed))
                ->validate($loader->load($this->tree->path('rules'))->ruleSet);
        } finally {
            \chmod($closed, 0o755);
        }

        self::assertContains(
            'the payload directory ' . $closed . ' cannot be read. A directory that cannot be '
                . 'listed may hold a payload, so the set cannot be judged complete.',
            $violations
        );
    }

    public function testAnEmptyRuleSetWithNoPayloadsIsSound(): void
    {
        self::assertSame([], $this->validate([], []));
    }
}
