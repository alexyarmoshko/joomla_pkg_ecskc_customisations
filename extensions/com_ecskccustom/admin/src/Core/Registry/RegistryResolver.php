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
 * Turns the registry and what the readers return into the Plugins screen's rows.
 *
 * It decides every cell, work items 5 to 8, and reads nothing itself: the readers
 * return raw rows, and even matching a row to its entry happens here, so the whole
 * derivation runs in a test without a database.
 */
final class RegistryResolver
{
    public const TYPE_KEY_PREFIX = 'COM_ECSKCCUSTOM_PLUGINS_TYPE_';

    private const COMMUNITY_BUILDER = 'com_comprofiler';

    /** The kinds whose identity includes a client, work item 1. */
    private const CLIENT_KINDS = ['component', 'module', 'template', 'language'];

    /** @var array<int, string> */
    private const CLIENT_NAMES = [0 => 'site', 1 => 'administrator', 3 => 'api'];

    public function __construct(
        private readonly ExtensionReaderInterface $extensionReader,
        private readonly CommunityBuilderReaderInterface $communityBuilderReader,
    ) {
    }

    public function resolve(RegistryLoadResult $registry): RegistryResolution
    {
        if (!$registry->isValid()) {
            return RegistryResolution::fault($registry->violations);
        }

        try {
            return RegistryResolution::rows($this->rows($registry->entries));
        } catch (RegistryReadFailure $failure) {
            return RegistryResolution::fault([$failure->getMessage()]);
        }
    }

    /**
     * @param list<RegistryEntry> $entries
     *
     * @return list<RegistryRow>
     *
     * @throws RegistryReadFailure
     */
    private function rows(array $entries): array
    {
        // Community Builder's component is looked up with the entries, so whether its
        // tables exist is known before anything asks for them, work item 6.
        $elementsByType = ['component' => [self::COMMUNITY_BUILDER]];
        $cbEntries      = [];

        foreach ($entries as $entry) {
            if ($entry->isCommunityBuilder()) {
                $cbEntries[] = $entry;
            } else {
                $elementsByType[$entry->kind][] = $entry->element;
            }
        }

        $elementsByType = \array_map(
            static fn (array $elements): array => \array_values(\array_unique($elements)),
            $elementsByType
        );
        $candidates = $this->extensionReader->extensions($elementsByType);

        /** @var array<int, ?ExtensionRow> $matches Keyed by entry position. */
        $matches = [];

        foreach ($entries as $position => $entry) {
            if (!$entry->isCommunityBuilder()) {
                $matches[$position] = $this->matchExtension($entry, $candidates);
            }
        }

        $parents      = $this->parents($matches);
        $effectiveIds = [];

        foreach ($matches as $row) {
            if ($row !== null) {
                $effectiveIds[$this->effectiveId($row)] = true;
            }
        }

        $effectiveIds = \array_keys($effectiveIds);
        $updates      = $this->updateVersions($effectiveIds);
        $sites        = $this->updateSites($effectiveIds);

        $cbInstalled = $this->communityBuilderInstalled($candidates);
        $cbRows      = $cbInstalled && $cbEntries !== []
            ? $this->communityBuilderReader->plugins($cbEntries)
            : [];

        $rows = [];

        foreach ($entries as $position => $entry) {
            $rows[] = $entry->isCommunityBuilder()
                ? $this->communityBuilderRow($entry, $cbInstalled ? $this->matchPlugin($entry, $cbRows) : null)
                : $this->extensionRow($entry, $matches[$position], $parents, $updates, $sites);
        }

        return $rows;
    }

    /**
     * The installed row for a Joomla entry: same type, element, folder and client, and
     * `state = 0`, the rows Extensions: Manage lists. A discovered row is not an install.
     *
     * Every part compares exactly, byte for byte, because that is the identity the
     * schema's `xs:unique` enforces. Comparing without case would let two entries that
     * differ only in case both claim one row. Joomla stores mixed case (`en-GB`,
     * `CBLib`), so an entry spells its identity as the row does; the adapter's query may
     * return more under the database collation, and those extra rows match nothing here.
     * Two matches cannot happen on a sound site; the lower ID wins so the answer is stable.
     *
     * @param list<ExtensionRow> $candidates
     */
    private function matchExtension(RegistryEntry $entry, array $candidates): ?ExtensionRow
    {
        $match = null;

        foreach ($candidates as $row) {
            if (
                $row->type === $entry->kind
                && $row->element === $entry->element
                && $row->folder === $entry->folder
                && $row->clientId === $entry->clientId
                && $row->state === 0
                && ($match === null || $row->extensionId < $match->extensionId)
            ) {
                $match = $row;
            }
        }

        return $match;
    }

    /**
     * The row with the entry's CB type, folder and element, compared exactly for the
     * reason matchExtension gives.
     *
     * @param list<CommunityBuilderPluginRow> $candidates
     */
    private function matchPlugin(RegistryEntry $entry, array $candidates): ?CommunityBuilderPluginRow
    {
        $match = null;

        foreach ($candidates as $row) {
            if (
                $row->type === $entry->cbType
                && $row->folder === $entry->folder
                && $row->element === $entry->element
                && ($match === null || $row->id < $match->id)
            ) {
                $match = $row;
            }
        }

        return $match;
    }

    /**
     * @param list<ExtensionRow> $candidates
     */
    private function communityBuilderInstalled(array $candidates): bool
    {
        foreach ($candidates as $row) {
            if (
                $row->type === 'component'
                && $row->element === self::COMMUNITY_BUILDER
                && $row->state === 0
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The row of every package a matched row belongs to, registered or not.
     *
     * @param array<int, ?ExtensionRow> $matches
     *
     * @return array<int, ExtensionRow> Keyed by extension ID.
     *
     * @throws RegistryReadFailure
     */
    private function parents(array $matches): array
    {
        $ids = [];

        foreach ($matches as $row) {
            if ($row !== null && $row->packageId !== 0) {
                $ids[$row->packageId] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $parents = [];

        foreach ($this->extensionReader->extensionsById(\array_keys($ids)) as $row) {
            $parents[$row->extensionId] = $row;
        }

        return $parents;
    }

    /**
     * The versions recorded per extension. An `extension_id` 0 row belongs to no
     * installed extension and is never matched, as in Joomla's UpdateModel.
     *
     * @param list<int> $extensionIds
     *
     * @return array<int, list<string>>
     *
     * @throws RegistryReadFailure
     */
    private function updateVersions(array $extensionIds): array
    {
        if ($extensionIds === []) {
            return [];
        }

        $versions = [];

        foreach ($this->extensionReader->updates($extensionIds) as $update) {
            $version = \trim($update->version);

            if ($update->extensionId !== 0 && $version !== '') {
                $versions[$update->extensionId][] = $version;
            }
        }

        return $versions;
    }

    /**
     * The update sites per extension, counting only those with a location.
     *
     * @param list<int> $extensionIds
     *
     * @return array<int, list<UpdateSiteLink>>
     *
     * @throws RegistryReadFailure
     */
    private function updateSites(array $extensionIds): array
    {
        if ($extensionIds === []) {
            return [];
        }

        $sites = [];

        foreach ($this->extensionReader->updateSites($extensionIds) as $site) {
            if (\trim($site->location) !== '') {
                $sites[$site->extensionId][] = $site;
            }
        }

        return $sites;
    }

    /**
     * @param array<int, ExtensionRow>         $parents
     * @param array<int, list<string>>         $updates
     * @param array<int, list<UpdateSiteLink>> $sites
     */
    private function extensionRow(
        RegistryEntry $entry,
        ?ExtensionRow $row,
        array $parents,
        array $updates,
        array $sites,
    ): RegistryRow {
        if ($row === null) {
            return $this->notInstalled($entry);
        }

        $manifest = $this->manifest($row->manifestCache);
        $version  = $manifest === null ? '' : $this->text($manifest['version'] ?? null);
        $parent   = $parents[$row->packageId] ?? null;

        // The effective extension answers for the update cells, decision 52: a child
        // reports its package's, compared with the package's installed version.
        $effectiveId      = $this->effectiveId($row);
        $installedVersion = $effectiveId === $row->extensionId
            ? $version
            : ($parent === null ? '' : $this->installedVersion($parent));

        [$update, $updateVersion] = $this->update($updates[$effectiveId] ?? [], $installedVersion);

        return new RegistryRow(
            $entry,
            $row->extensionId,
            $this->firstText(
                $manifest === null ? '' : (string) $row->manifestName,
                $row->translatedName,
                $this->identity($entry)
            ),
            self::TYPE_KEY_PREFIX . \strtoupper($entry->kind),
            $version,
            match (true) {
                $manifest === null  => EntryStatus::ManifestMissing,
                $row->enabled === 1 => EntryStatus::Enabled,
                default             => EntryStatus::Disabled,
            },
            $update,
            $updateVersion,
            $this->updateServer($sites[$effectiveId] ?? []),
            $parent === null ? '' : $this->packageName($parent),
            $manifest === null ? '' : (string) $row->manifestDescription,
        );
    }

    /**
     * A Community Builder row. Version and description come from `manifest_cache` when
     * it has a `release`, else all three texts come from the XML file together,
     * work item 6. The update cells are not checked yet.
     */
    private function communityBuilderRow(RegistryEntry $entry, ?CommunityBuilderPluginRow $row): RegistryRow
    {
        if ($row === null) {
            return $this->notInstalled($entry);
        }

        $cache       = $this->manifest($row->manifestCache);
        $release     = $cache === null ? '' : $this->text($cache['release'] ?? null);
        $hasManifest = true;

        if ($cache !== null && $release !== '') {
            $name        = $this->text($cache['name'] ?? null);
            $version     = $release;
            $description = $this->text($cache['description'] ?? null);
        } elseif ($row->xmlManifest !== null) {
            $name        = \trim($row->xmlManifest->name);
            $version     = \trim($row->xmlManifest->release);
            $description = \trim($row->xmlManifest->description);
        } else {
            $hasManifest = false;
            $name        = '';
            $version     = '';
            $description = '';
        }

        return new RegistryRow(
            $entry,
            $row->id,
            $this->firstText($name, $row->name, $this->identity($entry)),
            self::TYPE_KEY_PREFIX . \strtoupper($entry->kind),
            $version,
            match (true) {
                !$hasManifest         => EntryStatus::ManifestMissing,
                $row->published === 1 => EntryStatus::Enabled,
                default               => EntryStatus::Disabled,
            },
            UpdateStatus::NotChecked,
            null,
            UpdateServerStatus::NotChecked,
            '',
            $description,
        );
    }

    private function notInstalled(RegistryEntry $entry): RegistryRow
    {
        return new RegistryRow(
            $entry,
            null,
            $this->identity($entry),
            self::TYPE_KEY_PREFIX . \strtoupper($entry->kind),
            '',
            EntryStatus::NotInstalled,
            UpdateStatus::NotApplicable,
            null,
            UpdateServerStatus::NotApplicable,
            '',
            '',
        );
    }

    /**
     * The Package cell: the parent's name by the same chain as the Name cell, ending at
     * its element because an unregistered parent has no registry identity.
     */
    private function packageName(ExtensionRow $parent): string
    {
        return $this->firstText(
            $this->manifest($parent->manifestCache) === null ? '' : (string) $parent->manifestName,
            $parent->translatedName,
            $parent->element
        );
    }

    private function effectiveId(ExtensionRow $row): int
    {
        return $row->packageId !== 0 ? $row->packageId : $row->extensionId;
    }

    /**
     * '' when the manifest is missing or names no version: the version is unknown.
     */
    private function installedVersion(ExtensionRow $row): string
    {
        $manifest = $this->manifest($row->manifestCache);

        return $manifest === null ? '' : $this->text($manifest['version'] ?? null);
    }

    /**
     * The Update cell. With the installed version unknown, nothing can be called newer,
     * so the highest stored version is shown as recorded. No row at all is not proof
     * of being current: Joomla stores one only after Find Updates saw a newer version.
     *
     * @param list<string> $versions
     *
     * @return array{UpdateStatus, ?string}
     */
    private function update(array $versions, string $installedVersion): array
    {
        if ($versions === []) {
            return [UpdateStatus::NoneRecorded, null];
        }

        if ($installedVersion === '') {
            return [UpdateStatus::Recorded, $this->highest($versions)];
        }

        $newer = \array_values(\array_filter(
            $versions,
            static fn (string $version): bool => \version_compare($version, $installedVersion, '>')
        ));

        return $newer === []
            ? [UpdateStatus::NoneRecorded, null]
            : [UpdateStatus::Available, $this->highest($newer)];
    }

    /**
     * @param non-empty-list<string> $versions
     */
    private function highest(array $versions): string
    {
        $highest = $versions[0];

        foreach ($versions as $version) {
            if (\version_compare($version, $highest, '>')) {
                $highest = $version;
            }
        }

        return $highest;
    }

    /**
     * @param list<UpdateSiteLink> $sites Only those with a location.
     */
    private function updateServer(array $sites): UpdateServerStatus
    {
        if ($sites === []) {
            return UpdateServerStatus::None;
        }

        foreach ($sites as $site) {
            if ($site->enabled === 1) {
                return UpdateServerStatus::Registered;
            }
        }

        return UpdateServerStatus::Disabled;
    }

    /**
     * The entry's identity as text, the Name of last resort: the element, then the
     * folder or client where the identity has one, or the CB type and folder.
     */
    private function identity(RegistryEntry $entry): string
    {
        $parts = match (true) {
            $entry->isCommunityBuilder()                        => [$entry->cbType, $entry->folder],
            $entry->folder !== ''                               => [$entry->folder],
            \in_array($entry->kind, self::CLIENT_KINDS, true) => [self::CLIENT_NAMES[$entry->clientId] ?? (string) $entry->clientId],
            default                                             => [],
        };

        return $parts === [] ? $entry->element : \sprintf('%s (%s)', $entry->element, \implode(', ', $parts));
    }

    /**
     * A decoded manifest, or null when there is none to read: an empty cache, invalid
     * JSON, or anything but a non-empty object.
     *
     * @return ?array<string, mixed>
     */
    private function manifest(string $cache): ?array
    {
        $decoded = \json_decode($cache, true);

        if (!\is_array($decoded) || $decoded === [] || \array_is_list($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * A manifest value as trimmed text; '' for anything that is not a string or number.
     */
    private function text(mixed $value): string
    {
        return \is_string($value) || \is_int($value) || \is_float($value) ? \trim((string) $value) : '';
    }

    private function firstText(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (\trim($candidate) !== '') {
                return \trim($candidate);
            }
        }

        return '';
    }
}
