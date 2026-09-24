<?php

declare(strict_types=1);

namespace {
    require_once \dirname(__DIR__, 2) . '/Support/joomla_stubs.php';
}

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Access {

    use Joomla\CMS\User\User;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;
    use YakShaver\Component\ECSKCCustomisation\Administrator\Access\WritePermission;

    /**
     * Ticket 42 proves the real ACL on DDEV. This proves the action and asset asked for.
     */
    #[CoversClass(WritePermission::class)]
    final class WritePermissionTest extends TestCase
    {
        public function testManageAloneDoesNotAllowAWrite(): void
        {
            self::assertFalse(WritePermission::allows(new User(['core.manage'])));
        }

        public function testManageAndWriteAllowAWrite(): void
        {
            $user = new User(['core.manage', 'ecskccustom.write']);

            self::assertTrue(WritePermission::allows($user));
            self::assertSame([['ecskccustom.write', 'com_ecskccustom']], $user->asked);
        }

        public function testNoPermissionDoesNotAllowAWrite(): void
        {
            self::assertFalse(WritePermission::allows(new User([])));
        }
    }
}
