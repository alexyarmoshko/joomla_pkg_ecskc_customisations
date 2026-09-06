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
 *
 * So the reason travels as one of the codes below and the path travels beside it. The
 * installer maps the code to a language key and the operator reads their own language
 * with the path they need in it. The message this exception carries is for a developer
 * reading a stack trace, and never for a screen.
 *
 * Four codes rather than one per throw site. They are the four things an operator can
 * act on: the package could not read its own state, could not write the archive, could
 * not secure it, or could not put it where it belongs.
 */
final class StateArchiveFailure extends RuntimeException
{
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
