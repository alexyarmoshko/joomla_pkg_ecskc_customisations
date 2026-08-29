<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleAction;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleLoader;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleObject;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\SchemaValidator;
use YakShaver\ECSKCCustomisation\Tests\Support\RuleXml;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

#[CoversClass(RuleLoader::class)]
#[CoversClass(RuleObject::class)]
#[CoversClass(RuleAction::class)]
final class RuleLoaderTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-rules-');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    private static function loader(): RuleLoader
    {
        return new RuleLoader(new SchemaValidator(
            \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/schema/patch.xsd'
        ));
    }

    /**
     * @param array<string, string> $files Name to contents, inside the rule directory.
     */
    private function rulesDirectory(array $files): string
    {
        foreach ($files as $name => $contents) {
            $this->tree->write('rules/' . $name, $contents);
        }

        return $this->tree->path('rules');
    }

    private static function oneObject(string $dest, string $source): string
    {
        return RuleXml::patch(RuleXml::object(version: 1, dest: $dest, source: $source, create: true));
    }

    public function testFilesLoadInByteOrderAlphabeticalOrderAndObjectsKeepDocumentOrder(): void
    {
        // Written in an order that is neither the byte order nor the reverse, so a
        // loader that simply kept the directory's own order could not pass.
        $directory = $this->rulesDirectory([
            '20-kunena.xml' => self::oneObject('b/second.css', 'b/second.css'),
            '10-helix.xml'  => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/first.css', source: 'a/first.css', create: true)
                . RuleXml::object(version: 2, dest: 'a/also.css', source: 'a/also.css', create: true)
            ),
            '30-tennis.xml' => self::oneObject('c/third.css', 'c/third.css'),
        ]);

        $result = self::loader()->load($directory);

        self::assertSame([], $result->violations);
        self::assertSame(
            ['a/first.css', 'a/also.css', 'b/second.css', 'c/third.css'],
            \array_map(static fn (RuleObject $o): string => $o->targetPath, $result->ruleSet->objects())
        );
    }

    public function testOnlyXmlFilesAreRead(): void
    {
        $directory = $this->rulesDirectory([
            'index.html'  => '<!DOCTYPE html><title></title>',
            'notes.txt'   => 'not a rule',
            '10-real.xml' => self::oneObject('a/one.css', 'a/one.css'),
        ]);

        $result = self::loader()->load($directory);

        self::assertSame([], $result->violations);
        self::assertCount(1, $result->ruleSet->objects());
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function ambiguousDirectories(): array
    {
        return [
            'differing only in case'      => [['a.xml', 'A.xml'], 'differ only in case'],
            'capital sorting before lower' => [['B.xml', 'a.xml'], 'is ambiguous'],
        ];
    }

    /**
     * Byte order puts every capital ahead of every lower-case letter, so a rule set
     * whose names disagree on case cannot be read in the order its author sees.
     *
     * @param list<string> $names
     */
    #[DataProvider('ambiguousDirectories')]
    public function testAnAmbiguousLoadOrderIsRejected(array $names, string $expected): void
    {
        $files = [];

        foreach ($names as $index => $name) {
            $files[$name] = self::oneObject('a/file' . $index . '.css', 'a/file' . $index . '.css');
        }

        $result = self::loader()->load($this->rulesDirectory($files));

        self::assertCount(1, $result->violations);
        self::assertStringContainsString($expected, $result->violations[0]);

        foreach ($names as $name) {
            self::assertStringContainsString($name, $result->violations[0]);
        }
    }

    /**
     * Mixed case is not itself the fault: the two orders agreeing is.
     */
    public function testMixedCaseNamesWhoseTwoOrdersAgreeAreAccepted(): void
    {
        $result = self::loader()->load($this->rulesDirectory([
            'Alpha.xml' => self::oneObject('a/one.css', 'a/one.css'),
            'beta.xml'  => self::oneObject('b/two.css', 'b/two.css'),
        ]));

        self::assertSame([], $result->violations);
        self::assertCount(2, $result->ruleSet->objects());
    }

    public function testASchemaRejectionNamesTheFileAndContributesNoObjects(): void
    {
        $result = self::loader()->load($this->rulesDirectory([
            '10-broken.xml' => RuleXml::patch('<object version="1"><id/></object>'),
            '20-sound.xml'  => self::oneObject('a/one.css', 'a/one.css'),
        ]));

        self::assertNotSame([], $result->violations);

        foreach ($result->violations as $violation) {
            self::assertStringStartsWith('10-broken.xml:', $violation);
        }

        // The sound file still loads: one bad file does not hide the rest of the set.
        self::assertSame(
            ['a/one.css'],
            \array_map(static fn (RuleObject $o): string => $o->targetPath, $result->ruleSet->objects())
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function schemaRejections(): array
    {
        return [
            'an action with no operation'      => ['invalid-zero-operations.xml'],
            'an action with two operations'    => ['invalid-two-operations.xml'],
            'an attribute outside the closed vocabulary' => ['invalid-condition-attribute.xml'],
            'a match type outside the closed vocabulary' => ['invalid-condition-type.xml'],
        ];
    }

    /**
     * These faults are named in ticket 12's rejection list but are settled inside a
     * single file, so the schema layer owns them. What matters here is that loading
     * a directory containing one still refuses the file and names it: an author gets
     * the same answer whether the fault is a shape or a relationship.
     */
    #[DataProvider('schemaRejections')]
    public function testLoadingRefusesAFileTheSchemaRejects(string $fixture): void
    {
        $contents = \file_get_contents(\dirname(__DIR__, 2) . '/Fixtures/rules/' . $fixture);
        self::assertIsString($contents);

        $result = self::loader()->load($this->rulesDirectory(['10-under-test.xml' => $contents]));

        self::assertNotSame([], $result->violations);
        self::assertStringStartsWith('10-under-test.xml:', $result->violations[0]);
        self::assertTrue($result->ruleSet->isEmpty());
    }

    /**
     * Brief 5.4: "The rule set is only ever read from the installed package. Rules are
     * not accepted from an upload, an arbitrary location, or the database." A symbolic
     * link is an arbitrary location wearing the package's own name, and is_file()
     * follows one without saying so - measured: a rule file linked in from outside
     * loaded with no violation at all.
     */
    public function testARuleFileThatIsASymbolicLinkIsRefused(): void
    {
        $directory = $this->rulesDirectory([
            '10-real.xml' => self::oneObject('a/one.css', 'a/one.css'),
        ]);

        $this->tree->write('elsewhere/99-foreign.xml', self::oneObject('b/two.css', 'b/two.css'));
        $this->tree->symlink('rules/99-foreign.xml', $this->tree->path('elsewhere/99-foreign.xml'));

        $result = self::loader()->load($directory);

        self::assertSame(
            ['99-foreign.xml: is a symbolic link. The rule set is only ever read from the '
                . 'installed package, so a rule file has to be one.'],
            $result->violations
        );
        self::assertSame(
            ['a/one.css'],
            \array_map(static fn (RuleObject $o): string => $o->targetPath, $result->ruleSet->objects())
        );
    }

    public function testAMissingRuleDirectoryIsReportedRatherThanRead(): void
    {
        $directory = $this->tree->root() . '/absent';

        $result = self::loader()->load($directory);

        self::assertSame([$directory . ': the rule directory does not exist'], $result->violations);
        self::assertTrue($result->ruleSet->isEmpty());
    }

    public function testAnEmptyRuleDirectoryLoadsToAnEmptySet(): void
    {
        $result = self::loader()->load($this->rulesDirectory(['index.html' => '']));

        self::assertSame([], $result->violations);
        self::assertTrue($result->ruleSet->isEmpty());
        self::assertTrue($result->isValid());
    }

    /**
     * The schema declares these defaults; the loader applies them itself, so an
     * object means the same thing whether or not libxml filled them in.
     */
    public function testPermissionDefaultsAreAppliedWhenTheAttributesAreAbsent(): void
    {
        $result = self::loader()->load($this->rulesDirectory([
            '10-defaults.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/one.css', source: 'a/one.css')
            ),
        ]));

        $object = $result->ruleSet->objects()[0];

        self::assertFalse($object->create, 'create defaults to false');
        self::assertTrue($object->replace, 'replace defaults to true');
        self::assertTrue($object->enable, 'enable defaults to true');
        self::assertNull($object->expectedHash);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function booleanSpellings(): array
    {
        return [
            'true'  => ['true', true],
            'one'   => ['1', true],
            'false' => ['false', false],
            'zero'  => ['0', false],
        ];
    }

    /**
     * xs:boolean has two spellings for each value and the schema accepts all four.
     */
    #[DataProvider('booleanSpellings')]
    public function testBothSpellingsOfEachBooleanAreUnderstood(string $written, bool $expected): void
    {
        $result = self::loader()->load($this->rulesDirectory([
            '10-boolean.xml' => RuleXml::patch(
                '<object version="1" create="' . $written . '">'
                . '<id><destPathFileName>a/one.css</destPathFileName></id>'
                . '<action><operations><remove/></operations></action>'
                . '</object>'
            ),
        ]));

        self::assertSame([], $result->violations);
        self::assertSame($expected, $result->ruleSet->objects()[0]->create);
    }

    /**
     * @return array<string, array{string, string, string, bool}>
     */
    public static function targetSpellings(): array
    {
        return [
            'relative to its root' => [
                'templates/et_tennis/css',
                'override.css',
                'templates/et_tennis/css/override.css',
                false,
            ],
            'absolute from the web root, ignoring the root' => [
                'templates/et_tennis/css',
                '/media/kunena/style.css',
                'media/kunena/style.css',
                true,
            ],
            'relative under an empty root' => [
                '',
                'configuration/one.css',
                'configuration/one.css',
                false,
            ],
        ];
    }

    /**
     * Both spellings reduce to one canonical target, and which was written is kept:
     * the merged set reports a target named both ways rather than resolving it.
     */
    #[DataProvider('targetSpellings')]
    public function testATargetReducesToItsCanonicalPath(
        string $root,
        string $dest,
        string $expected,
        bool $absolute,
    ): void {
        $result = self::loader()->load($this->rulesDirectory([
            '10-paths.xml' => RuleXml::patch(RuleXml::root(
                $root,
                RuleXml::object(version: 1, dest: $dest, source: 'a/one.css', create: true)
            )),
        ]));

        self::assertSame([], $result->violations);

        $object = $result->ruleSet->objects()[0];

        self::assertSame($expected, $object->targetPath);
        self::assertSame($absolute, $object->absoluteForm);
        self::assertSame($dest, $object->destPathFileName);
        self::assertSame($root, $object->rootPath);
    }

    public function testAnObjectOutsideAnyRootResolvesFromTheWebRoot(): void
    {
        $result = self::loader()->load($this->rulesDirectory([
            '10-bare.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'media/one.css', source: 'a/one.css', create: true)
            ),
        ]));

        self::assertSame('media/one.css', $result->ruleSet->objects()[0]->targetPath);
        self::assertSame('', $result->ruleSet->objects()[0]->rootPath);
    }

    public function testACopyCarriesItsPayloadAndARemovalCarriesItsBackupFlag(): void
    {
        $result = self::loader()->load($this->rulesDirectory([
            '10-operations.xml' => RuleXml::patch(
                RuleXml::object(
                    version: 1,
                    dest: 'a/one.css',
                    source: 'a/one.css',
                    sourceHash: RuleXml::hash('v1'),
                    create: true
                )
                . '<object version="1"><id><destPathFileName>b/two.css</destPathFileName></id>'
                . '<action><operations><remove backup="false"/></operations></action></object>'
            ),
        ]));

        [$copy, $remove] = $result->ruleSet->objects();

        self::assertTrue($copy->actions[0]->isCopy());
        self::assertSame('a/one.css', $copy->actions[0]->source);
        self::assertSame(RuleXml::hash('v1'), $copy->actions[0]->sourceHash);
        self::assertSame([RuleXml::hash('v1')], $copy->payloadHashes());

        self::assertFalse($remove->actions[0]->isCopy());
        self::assertNull($remove->actions[0]->source);
        self::assertFalse($remove->actions[0]->backup);
        self::assertSame([], $remove->payloadSources());
    }

    /**
     * The hash algorithm travels with the object, so whatever hashes a payload later
     * needs no second lookup to learn which one the author declared.
     */
    public function testEachObjectCarriesTheAlgorithmItsFileDeclared(): void
    {
        $result = self::loader()->load($this->rulesDirectory([
            '10-sha.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/one.css', source: 'a/one.css', create: true),
                'sha512'
            ),
        ]));

        self::assertSame('sha512', $result->ruleSet->objects()[0]->hashType);
    }

    /**
     * A pretty-printed path carries its own indentation in the text node. Were that
     * accepted, it would be silently a different target from the same path written
     * inline: it groups apart, defeats duplicate detection, and reaches the
     * filesystem. The schema refuses whitespace so the author is told instead.
     */
    public function testAPathBrokenAcrossLinesIsRefusedRatherThanQuietlyRenamed(): void
    {
        $result = self::loader()->load($this->rulesDirectory([
            '10-pretty.xml' => RuleXml::patch(
                '<object version="1" create="true"><id><destPathFileName>' . "\n"
                . '                templates/foo/index.php' . "\n"
                . '            </destPathFileName></id>'
                . '<action><operations><remove/></operations></action></object>'
            ),
        ]));

        self::assertNotSame([], $result->violations);
        self::assertStringContainsString('destPathFileName', $result->violations[0]);
        self::assertTrue($result->ruleSet->isEmpty());
    }

    /**
     * hashType is an xs:token, so the schema judges it collapsed. Reading it raw
     * would reject a padded but perfectly valid declaration.
     */
    public function testAPaddedHashTypeIsReadAsTheTokenTheSchemaJudged(): void
    {
        $result = self::loader()->load($this->rulesDirectory([
            '10-padded.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/one.css', source: 'a/one.css', create: true),
                '  sha256  '
            ),
        ]));

        self::assertSame([], $result->violations);
        self::assertSame('sha256', $result->ruleSet->objects()[0]->hashType);
    }

    public function testAnObjectNamesItsFileAndVersionInItsLabel(): void
    {
        $result = self::loader()->load($this->rulesDirectory([
            '10-label.xml' => RuleXml::patch(RuleXml::root(
                'templates/et_tennis/css',
                RuleXml::object(version: 3, dest: 'override.css', source: 'a/one.css', create: true)
            )),
        ]));

        self::assertSame(
            '10-label.xml: object templates/et_tennis/css/override.css version 3',
            $result->ruleSet->objects()[0]->label()
        );
    }
}
