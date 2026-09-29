<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Registry;

use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\ExtensionReaderInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\ExtensionRow;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryReadFailure;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\UpdateRow;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\UpdateSiteLink;

/**
 * Reads `#__extensions`, `#__updates` and the update site tables, and decides nothing.
 *
 * It returns what the query found, translated as `InstallerModel::translate` does.
 * DatabaseRead turns a driver exception into a RegistryReadFailure.
 */
final class JoomlaExtensionReader implements ExtensionReaderInterface
{
    private const EXTENSION_COLUMNS = [
        'extension_id', 'type', 'element', 'folder', 'client_id', 'state',
        'enabled', 'package_id', 'manifest_cache', 'name',
    ];

    /**
     * @param DatabaseDriver $db The container's driver; typed by its interface, which
     *                           declares no query builder.
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ExtensionLanguageLoader $languages,
    ) {
    }

    public function extensions(array $elementsByType): array
    {
        $elementsByType = \array_filter($elementsByType, static fn (array $elements): bool => $elements !== []);

        if ($elementsByType === []) {
            return [];
        }

        return DatabaseRead::guarded('#__extensions', function () use ($elementsByType): array {
            $db    = $this->db;
            $query = $this->extensionQuery();
            $pairs = [];

            foreach ($elementsByType as $type => $elements) {
                [$typeName] = $query->bindArray([(string) $type], ParameterType::STRING);
                $elementNames = $query->bindArray(\array_values($elements), ParameterType::STRING);
                $pairs[]      = '(' . $db->quoteName('type') . ' = ' . $typeName
                    . ' AND ' . $db->quoteName('element') . ' IN (' . \implode(',', $elementNames) . '))';
            }

            $query->where('(' . \implode(' OR ', $pairs) . ')');

            return $this->extensionRows($query);
        });
    }

    public function extensionsById(array $extensionIds): array
    {
        if ($extensionIds === []) {
            return [];
        }

        return DatabaseRead::guarded('#__extensions', function () use ($extensionIds): array {
            $query = $this->extensionQuery()
                ->whereIn($this->db->quoteName('extension_id'), \array_values($extensionIds), ParameterType::INTEGER);

            return $this->extensionRows($query);
        });
    }

    public function updates(array $extensionIds): array
    {
        if ($extensionIds === []) {
            return [];
        }

        return DatabaseRead::guarded('#__updates', function () use ($extensionIds): array {
            $db    = $this->db;
            $query = $db->createQuery()
                ->select($db->quoteName(['extension_id', 'version']))
                ->from($db->quoteName('#__updates'))
                ->whereIn($db->quoteName('extension_id'), \array_values($extensionIds), ParameterType::INTEGER)
                ->order($db->quoteName('update_id'));

            $rows = [];

            foreach (DatabaseRead::rows($db, $query, '#__updates') as $row) {
                $rows[] = new UpdateRow(
                    DriverValue::integer($row, 'extension_id', '#__updates.extension_id'),
                    DriverValue::text($row, 'version', '#__updates.version'),
                );
            }

            return $rows;
        });
    }

    public function updateSites(array $extensionIds): array
    {
        if ($extensionIds === []) {
            return [];
        }

        return DatabaseRead::guarded('#__update_sites', function () use ($extensionIds): array {
            $db    = $this->db;
            $query = $db->createQuery()
                ->select($db->quoteName(['use.extension_id', 'us.update_site_id', 'us.location', 'us.enabled']))
                ->from($db->quoteName('#__update_sites_extensions', 'use'))
                ->join(
                    'INNER',
                    $db->quoteName('#__update_sites', 'us'),
                    $db->quoteName('us.update_site_id') . ' = ' . $db->quoteName('use.update_site_id')
                )
                ->whereIn($db->quoteName('use.extension_id'), \array_values($extensionIds), ParameterType::INTEGER)
                ->order([$db->quoteName('use.extension_id'), $db->quoteName('us.update_site_id')]);

            $rows = [];

            foreach (DatabaseRead::rows($db, $query, '#__update_sites') as $row) {
                $rows[] = new UpdateSiteLink(
                    DriverValue::integer($row, 'extension_id', '#__update_sites_extensions.extension_id'),
                    DriverValue::integer($row, 'update_site_id', '#__update_sites.update_site_id'),
                    DriverValue::text($row, 'location', '#__update_sites.location'),
                    DriverValue::integer($row, 'enabled', '#__update_sites.enabled'),
                );
            }

            return $rows;
        });
    }

    /**
     * @return \Joomla\Database\DatabaseQuery
     */
    private function extensionQuery(): object
    {
        $db = $this->db;

        return $db->createQuery()
            ->select($db->quoteName(self::EXTENSION_COLUMNS))
            ->from($db->quoteName('#__extensions'))
            ->order($db->quoteName('extension_id'));
    }

    /**
     * @return list<ExtensionRow>
     *
     * @throws RegistryReadFailure
     */
    private function extensionRows(object $query): array
    {
        $rows = [];

        foreach (DatabaseRead::rows($this->db, $query, '#__extensions') as $row) {
            $rows[] = $this->extensionRow($row);
        }

        return $rows;
    }

    /**
     * @throws RegistryReadFailure
     */
    private function extensionRow(object $row): ExtensionRow
    {
        $type          = DriverValue::text($row, 'type', '#__extensions.type');
        $element       = DriverValue::text($row, 'element', '#__extensions.element');
        $folder        = DriverValue::text($row, 'folder', '#__extensions.folder');
        $clientId      = DriverValue::integer($row, 'client_id', '#__extensions.client_id');
        $manifestCache = DriverValue::text($row, 'manifest_cache', '#__extensions.manifest_cache');
        $name          = DriverValue::text($row, 'name', '#__extensions.name');

        $this->languages->load($type, $element, $folder, $clientId);

        // InstallerModel::translate copies the decoded manifest over the row, then runs
        // the name and, except for a language pack, the description through Text::_().
        $manifest     = \strlen($manifestCache) > 0 ? \json_decode($manifestCache) : null;
        $manifest     = \is_object($manifest) ? $manifest : null;
        $name         = Text::_($name);
        $manifestName = $manifest !== null && isset($manifest->name) && \is_string($manifest->name)
            ? Text::_($manifest->name)
            : null;
        $description  = $manifest !== null && isset($manifest->description) && \is_string($manifest->description)
            ? ($type === 'language' ? $manifest->description : Text::_($manifest->description))
            : null;

        return new ExtensionRow(
            DriverValue::integer($row, 'extension_id', '#__extensions.extension_id'),
            $type,
            $element,
            $folder,
            $clientId,
            DriverValue::integer($row, 'state', '#__extensions.state'),
            DriverValue::integer($row, 'enabled', '#__extensions.enabled'),
            DriverValue::integer($row, 'package_id', '#__extensions.package_id'),
            $manifestCache,
            $name,
            $manifestName,
            $description,
        );
    }
}
