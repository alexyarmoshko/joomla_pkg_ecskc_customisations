<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Support;

/**
 * Builds rule XML for tests.
 *
 * The merged-set checks are about relationships between objects - a chain link, a
 * repeated version, a payload named twice - so a test of one of them needs several
 * objects that differ in exactly one respect. Written out as literal XML, the one
 * difference that matters disappears into forty lines that do not.
 *
 * Everything here produces XML the rule schema accepts, so a test that means to
 * exercise the merged-set layer cannot fail in the schema layer by accident. A
 * test that wants a schema rejection writes that file out in full.
 */
final class RuleXml
{
    /**
     * A distinct, valid sha256 digest per name, so a chain's links are readable:
     * hash('v1') is the payload v1 writes and the state v2 expects.
     */
    public static function hash(string $name): string
    {
        return \hash('sha256', $name);
    }

    public static function patch(string $inner, string $hashType = 'sha256'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<patch hashType="' . $hashType . '">' . "\n"
            . $inner . "\n"
            . '</patch>' . "\n";
    }

    public static function root(string $path, string $inner): string
    {
        return '<root path="' . $path . '">' . "\n" . $inner . "\n" . '</root>';
    }

    /**
     * One condition, wrapped in the group the schema requires.
     *
     * The forward walk needs objects that hold on one host and not on another, and
     * a group of one is the smallest shape that expresses it. Nesting and `any`
     * groups belong to the evaluator's own tests.
     */
    public static function conditions(
        string $attribute,
        string $value,
        string $type = 'literal',
        string $match = 'all',
    ): string {
        return '<conditions match="' . $match . '">'
            . '<condition attribute="' . $attribute . '" type="' . $type . '">' . $value . '</condition>'
            . '</conditions>';
    }

    /**
     * One object. A null source makes the action a removal rather than a copy.
     */
    public static function object(
        int $version,
        string $dest,
        ?string $source = null,
        ?string $sourceHash = null,
        ?string $expectedHash = null,
        ?bool $create = null,
        ?bool $replace = null,
        ?bool $enable = null,
        ?string $conditions = null,
    ): string {
        $attributes = 'version="' . $version . '"';

        foreach (['create' => $create, 'replace' => $replace, 'enable' => $enable] as $name => $value) {
            if ($value !== null) {
                $attributes .= ' ' . $name . '="' . ($value ? 'true' : 'false') . '"';
            }
        }

        $operation = $source === null
            ? '<remove/>'
            : '<copy><srcPathFileName>' . $source . '</srcPathFileName>'
                . '<sourceHash>' . ($sourceHash ?? self::hash($source)) . '</sourceHash></copy>';

        return '<object ' . $attributes . '>'
            . '<id>'
            . '<destPathFileName>' . $dest . '</destPathFileName>'
            . ($expectedHash === null ? '' : '<expectedHash>' . $expectedHash . '</expectedHash>')
            . '</id>'
            . '<action>' . ($conditions ?? '') . '<operations>' . $operation . '</operations></action>'
            . '</object>';
    }
}
