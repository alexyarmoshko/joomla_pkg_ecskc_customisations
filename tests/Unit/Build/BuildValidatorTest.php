<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Build\BuildCheck;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Build\BuildValidator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentValues;
use YakShaver\ECSKCCustomisation\Tests\Support\RuleXml;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

/**
 * The build's own pass: merged-set and schema errors already have homes, and this
 * is the layer that hashes payloads, runs path policy without a site, and warns.
 *
 * Fixtures write payload bytes equal to the source path so the default
 * `RuleXml::hash($source)` matches the file. A test that wants a mismatch writes
 * different bytes.
 */
#[CoversClass(BuildValidator::class)]
#[CoversClass(BuildCheck::class)]
final class BuildValidatorTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create('ecskc-build-');
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    /**
     * @param array<string, string> $files    Rule file name to contents.
     * @param list<string>          $payloads Payload paths; bytes equal the path.
     */
    private function check(array $files, array $payloads): BuildCheck
    {
        $this->tree->write('rules/index.html', '');
        $this->tree->write('payload/index.html', '');

        foreach ($files as $name => $contents) {
            $this->tree->write('rules/' . $name, $contents);
        }

        foreach ($payloads as $payload) {
            $this->tree->write('payload/' . $payload, $payload);
        }

        return self::validator()->check(
            $this->tree->path('rules'),
            $this->tree->path('payload')
        );
    }

    private static function validator(): BuildValidator
    {
        return new BuildValidator(
            \dirname(__DIR__, 3) . '/extensions/com_ecskccustom/admin/schema/patch.xsd'
        );
    }

    private static function oneObject(
        string $dest,
        string $source,
        bool $create = true,
        ?string $expectedHash = null,
        ?bool $enable = null,
        ?string $conditions = null,
        ?bool $replace = null,
    ): string {
        return RuleXml::patch(RuleXml::object(
            version: 1,
            dest: $dest,
            source: $source,
            create: $create,
            expectedHash: $expectedHash,
            enable: $enable,
            conditions: $conditions,
            replace: $replace,
        ));
    }

    public function testTheAuthoredInventoryHasNoErrorsAndNoWarnings(): void
    {
        $repo = \dirname(__DIR__, 3);
        $check = self::validator()->check($repo . '/data/rules', $repo . '/data/payload');

        self::assertSame([], $check->errors, \implode("\n", $check->errors));
        self::assertSame([], $check->warnings, \implode("\n", $check->warnings));
        self::assertTrue($check->ok());
    }

    public function testASoundTemporarySetHasNoErrors(): void
    {
        $check = $this->check([
            '10-ok.xml' => self::oneObject('templates/et_tennis/css/override.css', 'override.css.v1'),
        ], ['override.css.v1']);

        self::assertSame([], $check->errors);
        self::assertTrue($check->ok());
    }

    public function testInvalidXmlIsAnErrorAndProducesNoWarningsAboutTheSet(): void
    {
        $check = $this->check([
            '10-bad.xml' => '<not-xml',
        ], []);

        self::assertNotSame([], $check->errors);
        self::assertFalse($check->ok());
        self::assertStringContainsString('10-bad.xml', $check->errors[0]);
    }

    public function testAnUnknownElementIsAnError(): void
    {
        $check = $this->check([
            '10-unknown.xml' => RuleXml::patch(
                '<object version="1" create="true"><id>'
                . '<destPathFileName>a/one.css</destPathFileName></id>'
                . '<action><operations><copy>'
                . '<srcPathFileName>one.v1</srcPathFileName>'
                . '<sourceHash>' . RuleXml::hash('one.v1') . '</sourceHash>'
                . '</copy></operations></action>'
                . '<sneakyElement/></object>'
            ),
        ], ['one.v1']);

        self::assertFalse($check->ok());
        self::assertNotSame([], $check->errors);
    }

    public function testADuplicatePathAndVersionIsAnError(): void
    {
        $check = $this->check([
            '10-first.xml' => self::oneObject('a/one.css', 'one.v1'),
            '20-second.xml' => self::oneObject('a/one.css', 'one.other'),
        ], ['one.v1', 'one.other']);

        self::assertFalse($check->ok());
        self::assertStringContainsString('appears twice', $check->errors[0]);
    }

    public function testObjectsOutOfVersionOrderAreAnError(): void
    {
        $check = $this->check([
            '10-order.xml' => RuleXml::patch(
                RuleXml::object(version: 2, dest: 'a/one.css', source: 'one.v2', create: true)
                . RuleXml::object(version: 1, dest: 'a/one.css', source: 'one.v1', create: true)
            ),
        ], ['one.v1', 'one.v2']);

        self::assertFalse($check->ok());
        self::assertStringContainsString('out of version order', $check->errors[0]);
    }

    public function testABrokenPackageOwnedChainIsAnError(): void
    {
        $check = $this->check([
            '10-chain.xml' => RuleXml::patch(
                RuleXml::object(
                    version: 1,
                    dest: 'a/one.css',
                    source: 'one.v1',
                    create: true
                )
                . RuleXml::object(
                    version: 2,
                    dest: 'a/one.css',
                    source: 'one.v2',
                    expectedHash: RuleXml::hash('a typo'),
                    create: true
                )
            ),
        ], ['one.v1', 'one.v2']);

        self::assertFalse($check->ok());
        self::assertStringContainsString('does not link up', $check->errors[0]);
    }

    public function testAMissingPayloadIsAnError(): void
    {
        $check = $this->check([
            '10-missing.xml' => self::oneObject('a/one.css', 'gone.v1'),
        ], []);

        self::assertFalse($check->ok());
        self::assertStringContainsString('does not exist', $check->errors[0]);
    }

    public function testAnUnreferencedPayloadIsAnError(): void
    {
        $check = $this->check([
            '10-ok.xml' => self::oneObject('a/one.css', 'one.v1'),
        ], ['one.v1', 'stray.v1']);

        self::assertFalse($check->ok());
        self::assertStringContainsString('stray.v1', $check->errors[0]);
        self::assertStringContainsString('no object names it', $check->errors[0]);
    }

    public function testAPayloadNamedByTwoObjectsIsAnError(): void
    {
        $check = $this->check([
            '10-share.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/one.css', source: 'shared.v1', create: true)
                . RuleXml::object(version: 1, dest: 'b/two.css', source: 'shared.v1', create: true)
            ),
        ], ['shared.v1']);

        self::assertFalse($check->ok());
        self::assertStringContainsString('named more than once', $check->errors[0]);
    }

    public function testAPayloadWhoseBytesDoNotMatchSourceHashIsAnError(): void
    {
        $this->tree->write('rules/index.html', '');
        $this->tree->write('payload/index.html', '');
        $this->tree->write(
            'rules/10-hash.xml',
            self::oneObject('a/one.css', 'one.v1')
        );
        $this->tree->write('payload/one.v1', 'these bytes are not the declared hash');

        $check = self::validator()->check(
            $this->tree->path('rules'),
            $this->tree->path('payload')
        );

        self::assertFalse($check->ok());
        self::assertCount(1, $check->errors);
        self::assertSame(
            '10-hash.xml: object a/one.css version 1: payload one.v1 hashes to '
            . \hash('sha256', 'these bytes are not the declared hash')
            . ', not the declared sourceHash ' . RuleXml::hash('one.v1'),
            $check->errors[0]
        );
    }

    public function testAPayloadThatIsASymbolicLinkIsAnError(): void
    {
        $this->tree->write('rules/index.html', '');
        $this->tree->write('payload/index.html', '');
        $this->tree->write('outside/real.css', 'one.v1');
        $this->tree->write(
            'rules/10-link.xml',
            self::oneObject('a/one.css', 'one.v1')
        );
        $this->tree->symlink('payload/one.v1', $this->tree->path('outside/real.css'));

        $check = self::validator()->check(
            $this->tree->path('rules'),
            $this->tree->path('payload')
        );

        self::assertFalse($check->ok());
        self::assertStringContainsString('symbolic link', \implode("\n", $check->errors));
    }

    public function testADeniedTargetPathIsAnError(): void
    {
        $check = $this->check([
            '10-deny.xml' => self::oneObject('configuration.php', 'config.v1'),
        ], ['config.v1']);

        self::assertFalse($check->ok());
        self::assertStringContainsString('configuration.php', $check->errors[0]);
    }

    public function testATargetOutsideTheAllowListIsAnError(): void
    {
        $check = $this->check([
            '10-ini.xml' => self::oneObject('components/com_kunena/template/nts_kbee/params.ini', 'params.v1'),
        ], ['params.v1']);

        self::assertFalse($check->ok());
        self::assertStringContainsString('not on the extension allow list', $check->errors[0]);
    }

    public function testThePackageTreeIsADeniedTarget(): void
    {
        $check = $this->check([
            '10-self.xml' => self::oneObject(
                'administrator/components/com_ecskccustom/payload/one.css',
                'one.v1'
            ),
        ], ['one.v1']);

        self::assertFalse($check->ok());
        self::assertStringContainsString('package', \strtolower($check->errors[0]));
    }

    public function testAnObjectThatCanNeitherCreateNorReplaceIsAnError(): void
    {
        $check = $this->check([
            '10-dead.xml' => self::oneObject(
                dest: 'a/one.css',
                source: 'one.v1',
                create: false,
                replace: false,
            ),
        ], ['one.v1']);

        self::assertFalse($check->ok());
        self::assertStringContainsString('neither create nor replace', $check->errors[0]);
    }

    public function testATargetNamedInBothAbsoluteAndRelativeFormIsAnError(): void
    {
        $check = $this->check([
            '10-both.xml' => RuleXml::patch(RuleXml::root(
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

        self::assertFalse($check->ok());
        self::assertStringContainsString('absolute', $check->errors[0]);
    }

    public function testAnUnsupportedHashAlgorithmIsAnError(): void
    {
        $check = $this->check([
            '10-md5.xml' => RuleXml::patch(
                RuleXml::object(version: 1, dest: 'a/one.css', source: 'one.v1', create: true),
                'md5'
            ),
        ], ['one.v1']);

        self::assertFalse($check->ok());
        self::assertStringContainsString('md5', $check->errors[0]);
    }

    public function testAnAmbiguousRuleFilenameOrderIsAnError(): void
    {
        $check = $this->check([
            'B.xml' => self::oneObject('b/two.css', 'two.v1'),
            'a.xml' => self::oneObject('a/one.css', 'one.v1'),
        ], ['one.v1', 'two.v1']);

        self::assertFalse($check->ok());
        self::assertStringContainsString('ambiguous', $check->errors[0]);
    }

    public function testAReplaceOnlyObjectWithNoExpectedHashIsAWarningNotAnError(): void
    {
        $check = $this->check([
            '10-vendor.xml' => self::oneObject(
                dest: 'templates/et_tennis/css/vendor.css',
                source: 'vendor.v1',
                create: false,
            ),
        ], ['vendor.v1']);

        self::assertTrue($check->ok(), \implode("\n", $check->errors));
        self::assertCount(1, $check->warnings);
        self::assertSame(
            '10-vendor.xml: object templates/et_tennis/css/vendor.css version 1: may replace '
            . 'but not create, and declares no expectedHash. A vendor file without a hash is '
            . 'overwritten whatever it currently holds.',
            $check->warnings[0]
        );
    }

    public function testAnUnanchoredRegularExpressionIsAWarning(): void
    {
        $check = $this->check([
            '10-unanchored.xml' => self::oneObject(
                dest: 'a/one.css',
                source: 'one.v1',
                conditions: RuleXml::conditions(EnvironmentValues::HOST, 'ecskc\\.eu', 'regex'),
            ),
        ], ['one.v1']);

        self::assertTrue($check->ok(), \implode("\n", $check->errors));
        self::assertCount(1, $check->warnings);
        self::assertStringContainsString('unanchored', $check->warnings[0]);
        self::assertStringContainsString('HOST', $check->warnings[0]);
    }

    public function testARegularExpressionMatchingTheEmptyStringIsAWarningEvenWhenAnchored(): void
    {
        $check = $this->check([
            '10-empty.xml' => self::oneObject(
                dest: 'a/one.css',
                source: 'one.v1',
                conditions: RuleXml::conditions(EnvironmentValues::HOST, '^.*$', 'regex'),
            ),
        ], ['one.v1']);

        self::assertTrue($check->ok(), \implode("\n", $check->errors));
        self::assertCount(1, $check->warnings);
        self::assertStringContainsString('empty string', $check->warnings[0]);
        self::assertStringContainsString('^.*$', $check->warnings[0]);
    }

    public function testASiteUrlRegularExpressionThatLooksLikeAHostIsAWarning(): void
    {
        $check = $this->check([
            '10-hostish.xml' => self::oneObject(
                dest: 'a/one.css',
                source: 'one.v1',
                conditions: RuleXml::conditions(
                    EnvironmentValues::SITE_URL,
                    '^ecskc\\.eu$',
                    'regex'
                ),
            ),
        ], ['one.v1']);

        self::assertTrue($check->ok(), \implode("\n", $check->errors));
        self::assertCount(1, $check->warnings);
        self::assertStringContainsString('SITE_URL', $check->warnings[0]);
        self::assertStringContainsString('host', $check->warnings[0]);
    }

    public function testASiteUrlRegularExpressionThatIncludesASchemeIsNotAHostWarning(): void
    {
        $check = $this->check([
            '10-url.xml' => self::oneObject(
                dest: 'a/one.css',
                source: 'one.v1',
                conditions: RuleXml::conditions(
                    EnvironmentValues::SITE_URL,
                    '^https://ecskc\\.eu$',
                    'regex'
                ),
            ),
        ], ['one.v1']);

        self::assertTrue($check->ok(), \implode("\n", $check->errors));
        self::assertSame([], $check->warnings);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonCanonicalHosts(): array
    {
        return [
            'upper case'     => ['ECSKC.EU'],
            'trailing dot'   => ['ecskc.eu.'],
            'both'           => ['ECSKC.EU.'],
        ];
    }

    #[DataProvider('nonCanonicalHosts')]
    public function testALiteralHostThatIsNotCanonicalIsAWarning(string $host): void
    {
        $check = $this->check([
            '10-host.xml' => self::oneObject(
                dest: 'a/one.css',
                source: 'one.v1',
                conditions: RuleXml::conditions(EnvironmentValues::HOST, $host),
            ),
        ], ['one.v1']);

        self::assertTrue($check->ok(), \implode("\n", $check->errors));
        self::assertCount(1, $check->warnings);
        self::assertStringContainsString($host, $check->warnings[0]);
        self::assertStringContainsString('canonical', $check->warnings[0]);
    }

    public function testACanonicalHostLiteralIsSilent(): void
    {
        $check = $this->check([
            '10-host.xml' => self::oneObject(
                dest: 'a/one.css',
                source: 'one.v1',
                conditions: RuleXml::conditions(EnvironmentValues::HOST, 'ecskc.eu'),
            ),
        ], ['one.v1']);

        self::assertTrue($check->ok(), \implode("\n", $check->errors));
        self::assertSame([], $check->warnings);
    }

    public function testADisabledObjectBeforeALaterObjectAtTheSamePathIsAWarning(): void
    {
        $check = $this->check([
            '10-disabled.xml' => RuleXml::patch(
                RuleXml::object(
                    version: 1,
                    dest: 'a/one.css',
                    source: 'one.v1',
                    create: true,
                    enable: false
                )
                . RuleXml::object(
                    version: 2,
                    dest: 'a/one.css',
                    source: 'one.v2',
                    expectedHash: RuleXml::hash('one.v1'),
                    create: true
                )
            ),
        ], ['one.v1', 'one.v2']);

        self::assertTrue($check->ok(), \implode("\n", $check->errors));
        self::assertCount(1, $check->warnings);
        self::assertStringContainsString('enable="false"', $check->warnings[0]);
        self::assertStringContainsString('later object', $check->warnings[0]);
    }

    public function testADisabledObjectThatIsLastAtItsPathIsSilent(): void
    {
        $check = $this->check([
            '10-off.xml' => RuleXml::patch(
                RuleXml::object(
                    version: 1,
                    dest: 'a/one.css',
                    source: 'one.v1',
                    create: true,
                    enable: false
                )
            ),
        ], ['one.v1']);

        self::assertTrue($check->ok(), \implode("\n", $check->errors));
        self::assertSame([], $check->warnings);
    }

    public function testAChainWhoseFirstObjectCannotCreateIsAWarning(): void
    {
        $check = $this->check([
            '10-stuck.xml' => RuleXml::patch(
                RuleXml::object(
                    version: 1,
                    dest: 'a/one.css',
                    source: 'one.v1',
                    expectedHash: RuleXml::hash('original'),
                    create: false
                )
                . RuleXml::object(
                    version: 2,
                    dest: 'a/one.css',
                    source: 'one.v2',
                    expectedHash: RuleXml::hash('one.v1'),
                    create: false
                )
            ),
        ], ['one.v1', 'one.v2']);

        self::assertTrue($check->ok(), \implode("\n", $check->errors));
        self::assertNotSame([], $check->warnings);
        self::assertStringContainsString('missing file', \implode("\n", $check->warnings));
    }

    public function testALaterPackageOwnedObjectWithNoExpectedHashIsAWarning(): void
    {
        $check = $this->check([
            '10-chain.xml' => RuleXml::patch(
                RuleXml::object(
                    version: 1,
                    dest: 'a/one.css',
                    source: 'one.v1',
                    create: true
                )
                . RuleXml::object(
                    version: 2,
                    dest: 'a/one.css',
                    source: 'one.v2',
                    create: true
                )
            ),
        ], ['one.v1', 'one.v2']);

        self::assertTrue($check->ok(), \implode("\n", $check->errors));
        self::assertCount(1, $check->warnings);
        self::assertSame(
            '10-chain.xml: object a/one.css version 2: is after the first object at a '
            . 'package-owned path and declares no expectedHash. It advances on path alone '
            . 'and writes over whatever it finds.',
            $check->warnings[0]
        );
    }

    public function testWarningsDoNotPreventOk(): void
    {
        $check = $this->check([
            '10-warn.xml' => self::oneObject(
                dest: 'a/one.css',
                source: 'one.v1',
                create: false,
            ),
        ], ['one.v1']);

        self::assertTrue($check->ok());
        self::assertNotSame([], $check->warnings);
        self::assertSame([], $check->errors);
    }
}
