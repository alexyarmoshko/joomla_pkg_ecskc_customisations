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

use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\CommunityBuilderPluginRow;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\CommunityBuilderReaderInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\CommunityBuilderXmlManifest;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryEntry;

/**
 * Reads `#__comprofiler_plugin` and each row's XML manifest, and decides nothing.
 *
 * The XML is read for every row, whatever its `manifest_cache` holds, because
 * CommunityBuilderReaderInterface leaves that choice to the resolver. DatabaseRead
 * turns a driver exception into a RegistryReadFailure. A manifest file that is
 * absent, unreadable or not a Community Builder manifest is null, not a failure.
 */
final class JoomlaCommunityBuilderReader implements CommunityBuilderReaderInterface
{
    private const TABLE = '#__comprofiler_plugin';

    /** One path segment: no separator, and not `.` or `..`. */
    private const SEGMENT = '/^(?!\.{1,2}$)[A-Za-z0-9_.-]+$/';

    /**
     * @param DatabaseDriver $db   The container's driver; typed by its interface, which
     *                             declares no query builder.
     * @param string         $site JPATH_SITE, under which Community Builder keeps its plugins.
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $site,
    ) {
    }

    public function plugins(array $entries): array
    {
        $elements = \array_values(\array_unique(\array_map(
            static fn (RegistryEntry $entry): string => $entry->element,
            $entries
        )));

        if ($elements === []) {
            return [];
        }

        return DatabaseRead::guarded(self::TABLE, function () use ($elements): array {
            $db    = $this->db;
            $query = $db->createQuery()
                ->select($db->quoteName(['id', 'type', 'folder', 'element', 'published', 'manifest_cache', 'name']))
                ->from($db->quoteName(self::TABLE))
                ->whereIn($db->quoteName('element'), $elements, ParameterType::STRING)
                ->order($db->quoteName('id'));

            $rows = [];

            foreach (DatabaseRead::rows($db, $query, self::TABLE) as $row) {
                $type    = DriverValue::text($row, 'type', self::TABLE . '.type');
                $folder  = DriverValue::text($row, 'folder', self::TABLE . '.folder');
                $element = DriverValue::text($row, 'element', self::TABLE . '.element');

                $rows[] = new CommunityBuilderPluginRow(
                    DriverValue::integer($row, 'id', self::TABLE . '.id'),
                    $type,
                    $folder,
                    $element,
                    DriverValue::integer($row, 'published', self::TABLE . '.published'),
                    DriverValue::text($row, 'manifest_cache', self::TABLE . '.manifest_cache'),
                    DriverValue::text($row, 'name', self::TABLE . '.name'),
                    $this->xmlManifest($type, $folder, $element),
                );
            }

            return $rows;
        });
    }

    /**
     * The manifest at `components/com_comprofiler/plugin/<type>/<folder>/<element>.xml`.
     *
     * Each part comes from the database, so a part that is empty or would leave its
     * directory means no file rather than a path outside the plugin tree. A symbolic
     * link that resolves outside the tree is no file either.
     */
    private function xmlManifest(string $type, string $folder, string $element): ?CommunityBuilderXmlManifest
    {
        foreach ([$type, $folder, $element] as $segment) {
            if (\preg_match(self::SEGMENT, $segment) !== 1) {
                return null;
            }
        }

        $tree = \realpath($this->site . '/components/com_comprofiler/plugin');
        $path = \realpath(\sprintf('%s/components/com_comprofiler/plugin/%s/%s/%s.xml', $this->site, $type, $folder, $element));

        if (
            $tree === false
            || $path === false
            || !\str_starts_with($path, $tree . \DIRECTORY_SEPARATOR)
            || !\is_file($path)
            || !\is_readable($path)
        ) {
            return null;
        }

        $xml = \file_get_contents($path);

        return $xml === false ? null : $this->parse($xml);
    }

    /**
     * The first `<name>`, `<release>` and `<description>` under a `<cbinstall>` root.
     */
    private function parse(string $xml): ?CommunityBuilderXmlManifest
    {
        $document = new \DOMDocument();
        $previous = \libxml_use_internal_errors(true);

        try {
            $loaded = $xml !== '' && $document->loadXML($xml, \LIBXML_NONET);
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
        }

        $root = $document->documentElement;

        if (!$loaded || $root === null || $root->nodeName !== 'cbinstall') {
            return null;
        }

        return new CommunityBuilderXmlManifest(
            $this->child($root, 'name'),
            $this->child($root, 'release'),
            $this->child($root, 'description'),
        );
    }

    private function child(\DOMElement $root, string $name): string
    {
        foreach ($root->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->nodeName === $name) {
                return $node->textContent;
            }
        }

        return '';
    }
}
