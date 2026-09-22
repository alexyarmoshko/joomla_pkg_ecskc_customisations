<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Support;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunLock;

/**
 * Can a fresh handle take the run lock exclusively?
 *
 * That pair - acquire, then release - is the whole proof that an operation gave back
 * what it held, and four suites need it: the runner's, the freezer's, the archive's
 * and the installer's. It lives here rather than in each of them because the pair is
 * the assertion, and a copy that drifted into asking something slightly different
 * would have those suites proving different things about the same lock.
 *
 * It takes the exclusive lock on purpose. `RunLock::observe()` would answer a weaker
 * question: a shared lock succeeds while another shared one is held, and what these
 * suites are proving is that nothing at all is holding this file.
 */
final class RunLockProbe
{
    public static function isFree(PackagePaths $paths): bool
    {
        $lock = new RunLock($paths);

        if (!$lock->acquire()) {
            return false;
        }

        $lock->release();

        return true;
    }
}
