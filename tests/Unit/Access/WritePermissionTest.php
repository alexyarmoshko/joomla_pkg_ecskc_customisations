<?php

declare(strict_types=1);

namespace Joomla\CMS\User {
    class User
    {
        /** @var list<array{string, ?string}> */
        public array $asked = [];

        /**
         * @param  list<string>  $granted  Actions granted on com_ecskccustom.
         */
        public function __construct(private readonly array $granted)
        {
        }

        public function authorise($action, $assetname = null)
        {
            $this->asked[] = [$action, $assetname];

            return $assetname === 'com_ecskccustom' && \in_array($action, $this->granted, true);
        }
    }
}

namespace {
    if (!\defined('_JEXEC')) {
        \define('_JEXEC', 1);
    }
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
