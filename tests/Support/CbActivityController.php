<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The CB Activity controller the package ships, and the vendor original it replaces.
 *
 * The original is not stored in the repository. The payload differs from it in one
 * argument, so swapping that argument back yields the vendor bytes, and the rule's
 * expectedHash proves it.
 */
final class CbActivityController
{
    public const TARGET  = 'components/com_comprofiler/plugin/user/plug_cbactivity/library/Controller/ActivityController.php';
    public const PAYLOAD = self::TARGET . '.v1';

    public const ORIGINAL_SHA256 = 'f1f836fa324109ea3bd6cf5b54223305d24ea0c9f329682fbe6e71a1a4b1b51f';
    public const PAYLOAD_SHA256  = '8b72a092f18b161c4c63b411f83de9702595ec9046ecfb79a6296cc9d3bd3b16';

    private const FIXED  = '$flairId, $date, ( $loop + 1 )';
    private const FAULTY = '$flairId, $loop, ( $loop + 1 )';

    public static function repository(): string
    {
        return \dirname(__DIR__, 2);
    }

    public static function payload(): string
    {
        $bytes = \file_get_contents(self::repository() . '/data/payload/' . self::PAYLOAD);
        Assert::assertIsString($bytes);

        return $bytes;
    }

    public static function original(): string
    {
        $payload = self::payload();
        Assert::assertSame(1, \substr_count($payload, self::FIXED), 'The payload carries the fix exactly once.');

        return \str_replace(self::FIXED, self::FAULTY, $payload);
    }
}
