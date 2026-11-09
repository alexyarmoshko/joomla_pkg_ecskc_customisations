<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The two CB GroupJive files the package ships, and the vendor originals they replace.
 *
 * The originals are not stored in the repository. Each payload differs from its
 * original only in the guest-branch condition strings, which lost a leading
 * " AND ", so putting it back yields the vendor bytes, and the rule's
 * expectedHash proves it.
 */
final class CbGroupJiveFiles
{
    public const EVENTS = 'components/com_comprofiler/plugin/user/plug_cbgroupjive/plugins/cbgroupjiveevents/component.cbgroupjiveevents.php';
    public const MODULE = 'modules/mod_cbgroupjive/src/Dispatcher/Dispatcher.php';

    /**
     * Per target: original SHA-256, payload SHA-256, and the 1-based lines
     * whose condition string lost its leading " AND ". The module's groups
     * mode has a guest branch of the same shape that the vendor already got
     * right, so the lines are named rather than found by pattern.
     */
    public const FILES = [
        self::EVENTS => [
            '72e42fd97e1d9629bba7df9fee0f174e11a12ce07938ffa9c07da841e4c15631',
            '9818700e727c2ce7b4a172aff69dbf6baacfb015469e78ffeb077d88d1a9fe27',
            [209, 210, 284, 285],
        ],
        self::MODULE => [
            '7de8dfec38f448ac727035dbd94d28776aa2489d07939e7274b8b1adcf08cb54',
            '42caa28d7efc42778e13b95f40c5cf230627cc9872b9ca3450765d3791885cbe',
            [288, 289],
        ],
    ];

    public static function repository(): string
    {
        return \dirname(__DIR__, 2);
    }

    public static function payload(string $target): string
    {
        $bytes = \file_get_contents(self::repository() . '/data/payload/' . $target . '.v1');
        Assert::assertIsString($bytes);

        return $bytes;
    }

    public static function original(string $target): string
    {
        $lines = \explode("\n", self::payload($target));

        foreach (self::FILES[$target][2] as $number) {
            $fixed = \preg_replace('/(\$queryWhere\[\]\s*=\s*")g\./', '$1 AND g.', $lines[$number - 1], 1, $count);
            Assert::assertSame(1, $count, 'Line ' . $number . ' carries a fixed condition.');
            $lines[$number - 1] = (string) $fixed;
        }

        return \implode("\n", $lines);
    }
}
