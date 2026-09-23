<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Environment;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentResolver;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentValues;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\RawEnvironment;

/**
 * The derivation the core owns, exercised through the same path production uses.
 */
#[CoversClass(EnvironmentResolver::class)]
#[CoversClass(EnvironmentValues::class)]
#[CoversClass(RawEnvironment::class)]
final class EnvironmentResolverTest extends TestCase
{
    private EnvironmentResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new EnvironmentResolver();
    }

    private function resolve(
        ?string $liveSite = null,
        ?string $uriBase = null,
        ?string $joomlaVersion = '5.4.7',
        ?string $phpVersion = '8.3.31',
    ): EnvironmentValues {
        return $this->resolver->resolve(
            new RawEnvironment($liveSite, $uriBase, $joomlaVersion, $phpVersion)
        );
    }

    public function testSiteUrlAndHostAreDerivedFromLiveSiteWhereItIsSet(): void
    {
        $values = $this->resolve(liveSite: 'https://ecskc.eu', uriBase: 'https://staging.example/');

        self::assertSame('https://ecskc.eu', $values->value(EnvironmentValues::SITE_URL));
        self::assertSame('ecskc.eu', $values->value(EnvironmentValues::HOST));
    }

    /**
     * The ordinary case on this site: no live_site, so both come from the request.
     */
    public function testSiteUrlAndHostAreDerivedFromTheRequestWhenLiveSiteIsEmpty(): void
    {
        $values = $this->resolve(liveSite: '', uriBase: 'https://ecskc.eu/');

        self::assertSame('https://ecskc.eu', $values->value(EnvironmentValues::SITE_URL));
        self::assertSame('ecskc.eu', $values->value(EnvironmentValues::HOST));
    }

    /**
     * A live_site that could not be read at all means the same thing for this
     * derivation as one that is not configured: there is no configured site URL, so
     * the request answers. The two stay distinguishable in RawEnvironment because
     * the adapter must not be the one deciding that.
     */
    public function testAnUnreadableLiveSiteAlsoFallsBackToTheRequest(): void
    {
        $values = $this->resolve(liveSite: null, uriBase: 'https://ecskc.eu/');

        self::assertSame('https://ecskc.eu', $values->value(EnvironmentValues::SITE_URL));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function normalisedBases(): array
    {
        return [
            'trailing slash removed'        => ['https://ecskc.eu/', 'https://ecskc.eu'],
            'administrator segment removed' => ['https://ecskc.eu/administrator/', 'https://ecskc.eu'],
            'administrator without slash'   => ['https://ecskc.eu/administrator', 'https://ecskc.eu'],
            'subdirectory install kept'     => ['https://ecskc.eu/site/', 'https://ecskc.eu/site'],
            'subdirectory administrator'    => ['https://ecskc.eu/site/administrator/', 'https://ecskc.eu/site'],
            'nothing to remove'             => ['https://ecskc.eu', 'https://ecskc.eu'],

            'a host named administrator'    => ['http://administrator/', 'http://administrator'],
            'that host with a port'         => ['http://administrator:8080/', 'http://administrator:8080'],

            'that host with the segment'    => ['http://administrator/administrator/', 'http://administrator'],
        ];
    }

    #[DataProvider('normalisedBases')]
    public function testTheRequestBaseIsNormalisedToOneShape(string $base, string $expected): void
    {
        self::assertSame($expected, $this->resolve(uriBase: $base)->value(EnvironmentValues::SITE_URL));
    }

    /**
     * The wreckage was determined rather than faulted, which is what made it
     * dangerous: a value of "http:" is not empty, so nothing reported a problem and
     * every pattern was evaluated against it. HOST still faults, because "http:" has
     * no host component, but SITE_URL is judged on its own. Review s1.v23, finding 2.
     */
    public function testAHostNamedAdministratorLeavesAUsableSiteUrl(): void
    {
        $values = $this->resolve(uriBase: 'http://administrator/');

        self::assertTrue($values->isDetermined(EnvironmentValues::SITE_URL));
        self::assertSame('http://administrator', $values->value(EnvironmentValues::SITE_URL));
        self::assertSame('administrator', $values->value(EnvironmentValues::HOST));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function configuredLiveSites(): array
    {
        return [

            'trailing slash removed'     => ['https://ecskc.eu/', 'https://ecskc.eu'],
            'nothing to remove'          => ['https://ecskc.eu', 'https://ecskc.eu'],
            'subdirectory install kept'  => ['https://ecskc.eu/site/', 'https://ecskc.eu/site'],

            'administrator segment kept' => ['https://ecskc.eu/administrator', 'https://ecskc.eu/administrator'],
            'administrator with slash'   => ['https://ecskc.eu/administrator/', 'https://ecskc.eu/administrator'],
        ];
    }

    /**
     * A configured live_site loses a trailing slash and nothing else. The two removals
     * are different acts: one settles a spelling, the other changes which resource the
     * value names.
     */
    #[DataProvider('configuredLiveSites')]
    public function testAConfiguredLiveSiteLosesOnlyATrailingSlash(string $live, string $expected): void
    {
        self::assertSame($expected, $this->resolve(liveSite: $live)->value(EnvironmentValues::SITE_URL));
    }

    /**
     * Measured on the DDEV copy, where live_site carries the port. HOST does not:
     * parse_url puts the port in its own component. A pattern anchored `^ecskc\.eu$`
     * against HOST therefore keeps working where the same pattern against SITE_URL
     * would not.
     */
    public function testAPortStaysInSiteUrlAndIsAbsentFromHost(): void
    {
        $values = $this->resolve(liveSite: 'https://example.ddev.site:8443');

        self::assertSame('https://example.ddev.site:8443', $values->value(EnvironmentValues::SITE_URL));
        self::assertSame('example.ddev.site', $values->value(EnvironmentValues::HOST));
    }

    public function testNeitherSiteUrlNorHostIsDeterminableWithoutALiveSiteOrARequest(): void
    {
        $values = $this->resolve(liveSite: '', uriBase: null);

        self::assertFalse($values->isDetermined(EnvironmentValues::SITE_URL));
        self::assertFalse($values->isDetermined(EnvironmentValues::HOST));
        self::assertNull($values->value(EnvironmentValues::SITE_URL));
        self::assertNull($values->value(EnvironmentValues::HOST));
        self::assertStringContainsString(
            'live_site is not configured',
            (string) $values->reason(EnvironmentValues::SITE_URL)
        );
        self::assertStringContainsString(
            'SITE_URL could not be determined',
            (string) $values->reason(EnvironmentValues::HOST)
        );
    }

    /**
     * A live_site written without a scheme parses as a path, so it has no host
     * component. SITE_URL is still what the operator configured; HOST faults rather
     * than guessing that the whole value is the host.
     */
    public function testASiteUrlWithNoHostComponentFaultsHostAlone(): void
    {
        $values = $this->resolve(liveSite: 'ecskc.eu');

        self::assertSame('ecskc.eu', $values->value(EnvironmentValues::SITE_URL));
        self::assertFalse($values->isDetermined(EnvironmentValues::HOST));
        self::assertStringContainsString(
            'has no host component',
            (string) $values->reason(EnvironmentValues::HOST)
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function hostSpellings(): array
    {
        return [
            'upper case'            => ['https://ECSKC.EU/', 'ecskc.eu'],
            'mixed case'            => ['https://Ecskc.Eu/administrator/', 'ecskc.eu'],
            'fully qualified'       => ['https://ecskc.eu./', 'ecskc.eu'],
            'both at once'          => ['https://ECSKC.EU./', 'ecskc.eu'],
            'already canonical'     => ['https://ecskc.eu', 'ecskc.eu'],
            'canonical with a port' => ['https://ECSKC.EU:8443', 'ecskc.eu'],
        ];
    }

    /**
     * Every one of these names the same host, and where no live_site is configured
     * the spelling comes from the request rather than from anyone's choice. Left as
     * parse_url returns it, `ECSKC.EU` is matched by `^(?!ecskc\.eu$).*$` and the
     * exclusion that expression exists to enforce stops excluding production.
     */
    #[DataProvider('hostSpellings')]
    public function testHostIsCanonicalWhateverSpellingTheUrlCarried(string $base, string $expected): void
    {
        self::assertSame($expected, $this->resolve(uriBase: $base)->value(EnvironmentValues::HOST));
    }

    /**
     * SITE_URL is not canonicalised with it. Only a URL's authority is
     * case-insensitive - its path is not - so there is no whole-value form to reduce
     * to, and rewriting half of one would be the silent rewrite this codebase keeps
     * refusing. A host-shaped expression written against SITE_URL is the build's to
     * warn about.
     */
    public function testSiteUrlKeepsTheSpellingItWasGiven(): void
    {
        $values = $this->resolve(uriBase: 'https://ECSKC.EU/');

        self::assertSame('https://ECSKC.EU', $values->value(EnvironmentValues::SITE_URL));
        self::assertSame('ecskc.eu', $values->value(EnvironmentValues::HOST));
    }

    /**
     * A host of nothing but the root label reduces to an empty string, and an empty
     * string is never a value.
     */
    public function testAHostThatReducesToNothingFaults(): void
    {
        $values = $this->resolve(liveSite: 'https://./somewhere');

        self::assertFalse($values->isDetermined(EnvironmentValues::HOST));
    }

    public function testTheVersionsArePassedThroughAsJoomlaAndPhpReportThem(): void
    {
        $values = $this->resolve();

        self::assertSame('5.4.7', $values->value(EnvironmentValues::JOOMLA_VERSION));
        self::assertSame('8.3.31', $values->value(EnvironmentValues::PHP_VERSION));
    }

    /**
     * Both spellings of "no version" keep the reason that names what was missing. An
     * empty string must not be answered with the empty-value explanation, which is
     * about regular expressions and would send an operator looking in the wrong
     * place for a missing JVERSION.
     */
    public function testAnUnreportedVersionIsAFaultWithItsOwnReason(): void
    {
        $values = $this->resolve(joomlaVersion: null, phpVersion: '');

        self::assertFalse($values->isDetermined(EnvironmentValues::JOOMLA_VERSION));
        self::assertFalse($values->isDetermined(EnvironmentValues::PHP_VERSION));
        self::assertStringContainsString(
            'Joomla did not report its version',
            (string) $values->reason(EnvironmentValues::JOOMLA_VERSION)
        );
        self::assertStringContainsString(
            'PHP did not report its version',
            (string) $values->reason(EnvironmentValues::PHP_VERSION)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptyValues(): array
    {
        return [
            'empty string'    => [''],
            'a space'         => [' '],
            'a newline'       => ["\n"],
            'a tab and space' => ["\t "],
        ];
    }

    /**
     * The clause the whole fault rule rests on: no condition path can hand back an
     * empty string. Asserted at the value object rather than through the resolver,
     * because the guarantee has to hold for anything that ever builds one.
     */
    #[DataProvider('emptyValues')]
    public function testAnEmptyValueBecomesAFaultWhateverBuiltIt(string $value): void
    {
        $values = new EnvironmentValues([EnvironmentValues::HOST => $value]);

        self::assertFalse($values->isDetermined(EnvironmentValues::HOST));
        self::assertNull($values->value(EnvironmentValues::HOST));
        self::assertStringContainsString(
            'negative lookahead',
            (string) $values->reason(EnvironmentValues::HOST)
        );
    }

    /**
     * Whitespace around a value can only have come from a configuration field
     * someone pasted into, and it silently breaks every anchored pattern.
     */
    public function testAValueIsTrimmed(): void
    {
        $values = new EnvironmentValues([EnvironmentValues::HOST => "  ecskc.eu\n"]);

        self::assertSame('ecskc.eu', $values->value(EnvironmentValues::HOST));
    }

    /**
     * The schema closes the vocabulary, so this is defence in depth. It answers with
     * a reason naming the four rather than with a bare null, so a typo cannot read
     * as an environment the package failed to inspect.
     */
    public function testAnAttributeOutsideTheVocabularyIsUnaskable(): void
    {
        $values = $this->resolve(liveSite: 'https://ecskc.eu');

        self::assertFalse($values->isDetermined('DATABASE_PREFIX'));
        self::assertNull($values->value('DATABASE_PREFIX'));
        self::assertStringContainsString('SITE_URL, HOST', (string) $values->reason('DATABASE_PREFIX'));
    }

    public function testADeterminedAttributeHasNoReason(): void
    {
        self::assertNull($this->resolve(liveSite: 'https://ecskc.eu')->reason(EnvironmentValues::HOST));
    }
}
