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
 * One row of the Plugins screen, every cell decided.
 *
 * Name, Version, Package and Description are text to show as it is. Type, Status,
 * Update and Update server are language keys, Update's taking `$updateVersion` as
 * its argument.
 */
final class RegistryRow
{
    public const REPOSITORY_PREFIX = 'https://github.com/';

    /**
     * @param ?int    $matchedId     The matched row's `extension_id`, or its
     *                               `#__comprofiler_plugin` id; null when not installed.
     * @param ?string $updateVersion Set for Available and Recorded only.
     * @param string  $version       '' when there is no manifest or it has no version.
     */
    public function __construct(
        public readonly RegistryEntry $entry,
        public readonly ?int $matchedId,
        public readonly string $name,
        public readonly string $typeKey,
        public readonly string $version,
        public readonly EntryStatus $status,
        public readonly UpdateStatus $update,
        public readonly ?string $updateVersion,
        public readonly UpdateServerStatus $updateServer,
        public readonly string $package,
        public readonly string $description,
    ) {
    }

    public function repositoryUrl(): string
    {
        return $this->entry->repo;
    }

    /**
     * The repository as `owner/name`. The schema requires every repo to start with
     * the prefix.
     */
    public function repositoryLabel(): string
    {
        return \substr($this->entry->repo, \strlen(self::REPOSITORY_PREFIX));
    }
}
