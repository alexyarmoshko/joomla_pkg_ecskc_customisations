<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\SchemaValidator;

#[CoversClass(SchemaValidator::class)]
final class SchemaValidatorTest extends TestCase
{
    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    private static function validator(): SchemaValidator
    {
        return new SchemaValidator(self::repositoryRoot() . '/extensions/com_ecskccustom/admin/schema/patch.xsd');
    }

    private static function fixture(string $name): string
    {
        return self::repositoryRoot() . '/tests/Fixtures/rules/' . $name;
    }

    /**
     * One patch carrying a single target path, for the path table below.
     */
    private static function patchWithTarget(string $target): string
    {
        return \sprintf(
            '<?xml version="1.0"?><patch hashType="sha256"><object version="1">'
            . '<id><destPathFileName>%s</destPathFileName></id>'
            . '<action><operations><remove/></operations></action>'
            . '</object></patch>',
            \htmlspecialchars($target, \ENT_XML1)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validRuleFiles(): array
    {
        return [
            'vendor-owned replace with nested conditions' => ['valid-vendor-owned.xml'],
            'package-owned create with no expectedHash'   => ['valid-package-owned.xml'],
            'removal, and an object outside any root'     => ['valid-removal-and-bare-object.xml'],
            'a web-root-absolute target'                  => ['valid-absolute-target.xml'],
            'literal conditions, explicit and defaulted'  => ['valid-literal-conditions.xml'],
        ];
    }

    /**
     * Each invalid fixture is pinned to the diagnostic it must produce.
     *
     * Asserting only "something was rejected" let a fixture pass while the schema
     * refused it for the wrong reason - which is how the absolute-target defect
     * (an early schema review finding 1) reached a green suite.
     *
     * @return array<string, array{string, string}>
     */
    public static function invalidRuleFiles(): array
    {
        return [
            'an element outside the vocabulary' => [
                'invalid-unknown-element.xml',
                "Element 'sneakyElement': This element is not expected.",
            ],
            'a condition attribute outside the closed set' => [
                'invalid-condition-attribute.xml',
                "The value 'SITE_NAME' is not an element of the set",
            ],
            'a match type outside the closed set' => [
                'invalid-condition-type.xml',
                "The value 'glob' is not an element of the set",
            ],
            'an action with two operations' => [
                'invalid-two-operations.xml',
                "Element 'remove': This element is not expected.",
            ],
            'an action with no operation' => [
                'invalid-zero-operations.xml',
                'Expected is one of ( copy, remove )',
            ],
            'an object with no version' => [
                'invalid-missing-version.xml',
                "The attribute 'version' is required but missing",
            ],
            'a target path escaping upwards' => [
                'invalid-traversal-path.xml',
                "Element 'destPathFileName': [facet 'pattern']",
            ],
            'a web-root-absolute payload source' => [
                'invalid-absolute-payload-source.xml',
                "Element 'srcPathFileName': [facet 'pattern']",
            ],
            'a patch that declares no hash algorithm' => [
                'invalid-missing-hashtype.xml',
                "The attribute 'hashType' is required but missing",
            ],
            'a copy with no sourceHash' => [
                'invalid-copy-without-hash.xml',
                'Expected is ( sourceHash )',
            ],
            'a file that is not well-formed XML' => [
                'invalid-not-well-formed.xml',
                'Opening and ending tag mismatch',
            ],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedTargetPaths(): array
    {
        $inventory = [
            'plugins/system/helixultimate/overrides/com_contact/contact/default_form.php',
            'plugins/system/helixultimate/overrides_legacy/com_contact/contact/default_form.php',
            'components/com_kunena/template/nts_kbee/config/config.xml',
            'components/com_kunena/template/nts_kbee/layouts/topic/edit/default.php',
            'components/com_kunena/template/nts_kbee/layouts/widget/error/default.php',
            'templates/et_tennis/options.xml',
            'components/com_comprofiler/plugin/user/plug_cbgallery/library/Controller/FolderController.php',
            'apple-touch-icon.png',
            'apple-touch-icon-precomposed.png',
            'components/com_comprofiler/plugin/language/cbpaidsubscriptions-en-gb/override.php',
            'components/com_comprofiler/plugin/language/cbpaidsubscriptions-en-gb/language.php',
            'templates/et_tennis/css/override.css',
        ];

        $cases = [];

        foreach ($inventory as $path) {
            $cases['inventory, relative: ' . $path] = [$path];
            $cases['inventory, absolute: /' . $path] = ['/' . $path];
        }

        $cases['a dotfile']                     = ['.htaccess'];
        $cases['a payload revision suffix']     = ['templates/et_tennis/css/override.css.v1'];
        $cases['a payload variant suffix']      = ['templates/et_tennis/css/override.css.dev.v1'];
        $cases['a name of three dots']          = ['...'];
        $cases['a name beginning with dots']    = ['..a'];

        $cases['a drive-like first segment']    = ['C:/x.php'];

        return $cases;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedTargetPaths(): array
    {
        return [
            'empty'                       => [''],
            'a bare slash'                => ['/'],
            'a doubled slash'             => ['a//b.php'],
            'a trailing slash'            => ['a/b.php/'],
            'a leading dot segment'       => ['./a.php'],
            'a mid dot segment'           => ['a/./b.php'],
            'a mid traversal segment'     => ['a/../b.php'],
            'a bare traversal'            => ['..'],
            'traversal after a slash'     => ['/../a.php'],
            'a doubled leading slash'     => ['//a/b.php'],
            'a backslash separator'       => ['a\\b.php'],
            'a backslash traversal'       => ['..\\..\\a.php'],

            'a name containing a space'   => ['a file.php'],
            'a segment that is one space' => [' '],
            'a segment of spaces'         => ['a/   /b.php'],
            'a leading newline'           => ["\n            a/b.php"],
            'a trailing newline'          => ["a/b.php\n        "],
            'an internal newline'         => ["a/\nb.php"],
            'a tab'                       => ["a/\tb.php"],
        ];
    }

    #[DataProvider('validRuleFiles')]
    public function testAcceptsAValidRuleFile(string $fixture): void
    {
        $violations = self::validator()->validateFile(self::fixture($fixture));

        self::assertSame([], $violations, \implode("\n", $violations));
    }

    #[DataProvider('invalidRuleFiles')]
    public function testRejectsAnInvalidRuleFileForTheStatedReason(
        string $fixture,
        string $expectedDiagnostic
    ): void {
        $violations = self::validator()->validateFile(self::fixture($fixture));

        self::assertNotSame([], $violations, 'Expected the schema to refuse this rule file.');
        self::assertStringContainsString(
            $expectedDiagnostic,
            \implode("\n", $violations),
            'The rule file was refused, but not for the reason this fixture exists to prove.'
        );
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function objectVersions(): array
    {
        return [
            'one'                        => ['1', true],
            'the largest exact value'    => ['2147483647', true],
            'one past it'                => ['2147483648', false],
            'past PHP_INT_MAX'           => ['9223372036854775808', false],
            'zero'                       => ['0', false],
            'negative'                   => ['-1', false],
        ];
    }

    /**
     * A version above PHP_INT_MAX saturates on conversion, so two distinct versions
     * would collapse into one and silently change both the walk order and whether a
     * duplicate exists. The schema is what stops that reaching the parser.
     */
    #[DataProvider('objectVersions')]
    public function testBoundsTheObjectVersion(string $version, bool $accepted): void
    {
        $patch = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<patch hashType="sha256"><object version="' . $version . '">'
            . '<id><destPathFileName>a/one.css</destPathFileName></id>'
            . '<action><operations><remove/></operations></action>'
            . '</object></patch>';

        $violations = self::validator()->validateString($patch, 'version');

        if ($accepted) {
            self::assertSame([], $violations, \implode("\n", $violations));
        } else {
            self::assertNotSame([], $violations, 'Expected version ' . $version . ' to be refused.');
        }
    }

    #[DataProvider('acceptedTargetPaths')]
    public function testAcceptsATargetPath(string $target): void
    {
        $violations = self::validator()->validateString(self::patchWithTarget($target), 'path');

        self::assertSame([], $violations, \implode("\n", $violations));
    }

    #[DataProvider('refusedTargetPaths')]
    public function testRefusesATargetPath(string $target): void
    {
        $violations = self::validator()->validateString(self::patchWithTarget($target), 'path');

        self::assertNotSame([], $violations, \sprintf('Expected %s to be refused.', \var_export($target, true)));
    }

    public function testEveryViolationNamesTheRuleAndALine(): void
    {
        $violations = self::validator()->validateFile(self::fixture('invalid-condition-attribute.xml'));

        self::assertNotSame([], $violations);

        foreach ($violations as $violation) {
            self::assertStringContainsString('invalid-condition-attribute.xml', $violation);
            self::assertMatchesRegularExpression('/\(line \d+\)/', $violation);
        }
    }

    public function testReportsEveryViolationInOnePassRatherThanTheFirst(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <patch hashType="sha256">
                <root path="a">
                    <object version="1">
                        <id><destPathFileName>b.php</destPathFileName></id>
                        <action>
                            <conditions match="sometimes">
                                <condition type="glob" attribute="SITE_NAME">x</condition>
                            </conditions>
                            <operations><remove/></operations>
                        </action>
                    </object>
                </root>
            </patch>
            XML;

        $violations = self::validator()->validateString($xml, 'inline');

        self::assertGreaterThan(1, \count($violations));
    }

    public function testSuccessiveCallsDoNotLeakViolationsIntoEachOther(): void
    {
        $validator = self::validator();

        $first = $validator->validateFile(self::fixture('invalid-condition-attribute.xml'));
        self::assertNotSame([], $first);

        $second = $validator->validateFile(self::fixture('valid-package-owned.xml'));
        self::assertSame([], $second, 'A valid rule must not inherit the previous call\'s errors.');

        $third = $validator->validateFile(self::fixture('invalid-missing-version.xml'));
        self::assertNotSame([], $third);
        self::assertStringNotContainsString('SITE_NAME', \implode("\n", $third));
    }

    public function testRestoresTheLibxmlInternalErrorModeItFound(): void
    {
        $validator = self::validator();

        foreach ([true, false] as $mode) {
            \libxml_use_internal_errors($mode);
            $validator->validateFile(self::fixture('invalid-missing-version.xml'));

            self::assertSame(
                $mode,
                \libxml_use_internal_errors($mode),
                'The validator must leave libxml\'s internal-error mode as it found it.'
            );
        }

        \libxml_use_internal_errors(false);
    }

    public function testAMissingRuleFileIsAViolationNotAFatal(): void
    {
        $violations = self::validator()->validateFile(self::fixture('does-not-exist.xml'));

        self::assertCount(1, $violations);
        self::assertStringContainsString('does not exist', $violations[0]);
    }

    public function testAMissingSchemaIsRefusedAtConstruction(): void
    {
        $this->expectException(RuntimeException::class);

        new SchemaValidator(self::repositoryRoot() . '/extensions/com_ecskccustom/admin/schema/nope.xsd');
    }

    public function testTheBriefSampleIsStillTheModelThisSchemaEncodes(): void
    {

        $sample = \file_get_contents(self::repositoryRoot() . '/tests/Fixtures/sample_schema.xml');
        self::assertIsString($sample);

        $withRealHashes = \preg_replace('/HASH_OF_[A-Z0-9_]+/', \str_repeat('a', 64), $sample);
        self::assertIsString($withRealHashes);

        $violations = self::validator()->validateString($withRealHashes, 'brief sample');

        self::assertSame([], $violations, \implode("\n", $violations));
    }
}
