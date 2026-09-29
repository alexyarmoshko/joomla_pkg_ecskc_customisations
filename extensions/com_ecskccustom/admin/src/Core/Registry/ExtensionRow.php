<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry;

/**
 * One `#__extensions` row as the reader found it, before anything is decided about it.
 *
 * The reader returns every row that shares a type and element with what it was asked
 * for, whatever its folder, client or state. Matching a row to an entry is the
 * resolver's decision, so it is made once and tested without a database.
 */
final class ExtensionRow
{
    /**
     * @param string  $translatedName      The `name` column after `Text::_()`.
     * @param ?string $manifestName        The manifest's name, translated as
     *                                     `InstallerModel::translate` does it; null when
     *                                     the manifest carries none.
     * @param ?string $manifestDescription The same for the description. A language
     *                                     pack's is passed through untranslated.
     */
    public function __construct(
        public readonly int $extensionId,
        public readonly string $type,
        public readonly string $element,
        public readonly string $folder,
        public readonly int $clientId,
        public readonly int $state,
        public readonly int $enabled,
        public readonly int $packageId,
        public readonly string $manifestCache,
        public readonly string $translatedName,
        public readonly ?string $manifestName,
        public readonly ?string $manifestDescription,
    ) {
    }
}
