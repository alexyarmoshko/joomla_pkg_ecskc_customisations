<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package;

use RuntimeException;

/**
 * Why an uninstall archive could not be written, in a form the installer can translate.
 *
 * The core carries no Joomla dependency and so cannot reach a language file, but its
 * failures end up in front of an operator: the uninstall hook puts them in the message
 * that says the history was not saved. An exception message would put untranslated
 * English there.
 */
final class StateArchiveFailure extends RuntimeException
{
    /**
     * A write run held `run.lock`, so the tree could not be snapshotted.
     */
    public const RUN_LOCKED = 'run-locked';

    /**
     * A check or dry run held a shared lock on `run.lock` for its read.
     *
     * Nothing was being written, so telling the operator a write was in progress
     * would send them looking for one. The archive still does not wait.
     */
    public const REPORT_READING = 'report-reading';

    /**
     * `run.lock` could not be opened, so the archive could not take the lock at all.
     *
     * A fault in the package's own tree, not a contended lock: no other run is
     * involved.
     */
    public const LOCK_UNAVAILABLE = 'lock-unavailable';

    /**
     * The codes that come from taking the run lock. None names a path an operator
     * can act on, because the lock file is deleted with the tree.
     */
    public const LOCK_REASONS = [self::RUN_LOCKED, self::REPORT_READING, self::LOCK_UNAVAILABLE];

    /** A folder or file under the package's own tree could not be read. */
    public const UNREADABLE = 'unreadable';

    /** The archive could not be built. */
    public const UNWRITABLE = 'unwritable';

    /** The archive was built but could not be given its mode. */
    public const UNSECURED = 'unsecured';

    /** The archive was built and secured but could not be published. */
    public const UNPUBLISHED = 'unpublished';

    /**
     * @param string $reason One of the codes above.
     * @param string $path   The path the failure is about, for the operator to act on.
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $path,
        string $message
    ) {
        parent::__construct($message);
    }
}
