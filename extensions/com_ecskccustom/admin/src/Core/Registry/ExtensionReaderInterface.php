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
 * Reads the Joomla tables the Plugins screen needs, and decides nothing.
 *
 * The Joomla adapter queries the database; a test returns fixtures. Every method
 * throws RegistryReadFailure when its query fails, and never returns an empty list
 * in its place.
 *
 * The row classes take integers where the columns are integers. The driver may supply
 * an int or a numeric string, and the adapter converts those. Any other value, `null`
 * or '' included, is a RegistryReadFailure: a cast would turn it into 0, which reads
 * as installed or disabled, a status the package does not know.
 */
interface ExtensionReaderInterface
{
    /**
     * Every `#__extensions` row whose type and element are among those given, in any
     * folder, client and state.
     *
     * @param array<string, list<string>> $elementsByType Element names keyed by type.
     *
     * @return list<ExtensionRow>
     *
     * @throws RegistryReadFailure
     */
    public function extensions(array $elementsByType): array;

    /**
     * The `#__extensions` rows with these IDs, in any state.
     *
     * @param list<int> $extensionIds
     *
     * @return list<ExtensionRow>
     *
     * @throws RegistryReadFailure
     */
    public function extensionsById(array $extensionIds): array;

    /**
     * The `#__updates` rows for these extension IDs.
     *
     * @param list<int> $extensionIds
     *
     * @return list<UpdateRow>
     *
     * @throws RegistryReadFailure
     */
    public function updates(array $extensionIds): array;

    /**
     * The update sites linked to these extension IDs, whatever their location.
     *
     * @param list<int> $extensionIds
     *
     * @return list<UpdateSiteLink>
     *
     * @throws RegistryReadFailure
     */
    public function updateSites(array $extensionIds): array;
}
