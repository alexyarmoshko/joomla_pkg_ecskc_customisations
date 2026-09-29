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

/**
 * Loads an extension's `.sys` language file the way `InstallerModel::translate` does,
 * so its manifest name and description translate as Extensions: Manage shows them.
 *
 * The file name and the paths tried follow that method type for type
 * (`com_installer/src/Model/InstallerModel.php:147`, Joomla 5): the second path is
 * tried only when the first loads nothing. Change the two together.
 */
final class ExtensionLanguageLoader
{
    /**
     * @param string                          $site          JPATH_SITE.
     * @param string                          $administrator JPATH_ADMINISTRATOR.
     * @param string                          $plugins       JPATH_PLUGINS.
     * @param \Closure(string, string): bool $load          Joomla's `Language::load`,
     *                                                       file then base path.
     */
    public function __construct(
        private readonly string $site,
        private readonly string $administrator,
        private readonly string $plugins,
        private readonly \Closure $load,
    ) {
    }

    /**
     * Whether a file loaded from any of the paths tried.
     */
    public function load(string $type, string $element, string $folder, int $clientId): bool
    {
        foreach ($this->attempts($type, $element, $folder, $clientId) as [$file, $path]) {
            if (($this->load)($file, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The language file and base path pairs to try, in order.
     *
     * @return list<array{string, string}>
     */
    public function attempts(string $type, string $element, string $folder, int $clientId): array
    {
        // Any client but 0 is the administrator's, api included, as in the Joomla method.
        $path = $clientId !== 0 ? $this->administrator : $this->site;

        switch ($type) {
            case 'component':
                $file = $element . '.sys';

                return [[$file, $this->administrator], [$file, $this->administrator . '/components/' . $element]];

            case 'file':
                return [['files_' . $element . '.sys', $this->site]];

            case 'library':
                $parts  = \explode('/', $element);
                $vendor = isset($parts[1]) ? $parts[0] : null;
                $file   = 'lib_' . ($vendor !== null ? \implode('_', $parts) : $element) . '.sys';

                return [
                    [$file, $path],
                    [$file, $path . '/libraries/' . ($vendor !== null ? $vendor . '/' . $parts[1] : $element)],
                ];

            case 'module':
                $file = $element . '.sys';

                return [[$file, $path], [$file, $path . '/modules/' . $element]];

            case 'plugin':
                $file = 'plg_' . $folder . '_' . $element . '.sys';

                return [[$file, $this->administrator], [$file, $this->plugins . '/' . $folder . '/' . $element]];

            case 'template':
                $file = 'tpl_' . $element . '.sys';

                return [[$file, $path], [$file, $path . '/templates/' . $element]];

            default:
                // Package, language and anything else.
                return [[$element . '.sys', $this->site]];
        }
    }
}
