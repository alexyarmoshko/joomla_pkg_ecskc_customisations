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

use DOMDocument;
use DOMElement;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\SchemaValidator;

/**
 * Reads the extension registry into entries, after validating it against its schema.
 *
 * The bytes are parsed a second time to build the entries rather than reusing the
 * validator's document, for the reason RuleLoader gives: SchemaValidator owns
 * libxml's global error queue for the duration of its call.
 *
 * Attribute values are read verbatim. Every one the schema allows is a whitespace-free
 * pattern or an enumeration, so there is nothing to normalise.
 */
final class RegistryLoader
{
    /**
     * `client` to `client_id`, as ApplicationHelper::getClientInfo numbers them.
     *
     * @var array<string, int>
     */
    private const CLIENT_IDS = [
        'site'          => 0,
        'administrator' => 1,
        'api'           => 3,
    ];

    /**
     * @param SchemaValidator $schemaValidator Built with the registry schema, registry.xsd.
     */
    public function __construct(private readonly SchemaValidator $schemaValidator)
    {
    }

    /**
     * Loads the registry against the schema at $schemaPath. A missing schema is a load
     * fault, as a missing registry is, rather than the exception SchemaValidator throws,
     * so a damaged install shows the Plugins screen's fault and not an error page.
     */
    public static function loadWithSchema(string $schemaPath, string $registryPath): RegistryLoadResult
    {
        // Refused for the reason load() refuses a linked registry.
        if (\is_link($schemaPath)) {
            return RegistryLoadResult::fault([\sprintf(
                '%s: is a symbolic link. The registry schema is only ever read from the installed '
                . 'package, so it has to be a file.',
                $schemaPath
            )]);
        }

        if (!\is_file($schemaPath)) {
            return RegistryLoadResult::fault(
                [\sprintf('%s: the registry schema file does not exist', $schemaPath)]
            );
        }

        return (new self(new SchemaValidator($schemaPath)))->load($registryPath);
    }

    public function load(string $registryPath): RegistryLoadResult
    {
        if (\is_link($registryPath)) {
            return RegistryLoadResult::fault([\sprintf(
                '%s: is a symbolic link. The registry is only ever read from the installed '
                . 'package, so it has to be a file.',
                $registryPath
            )]);
        }

        if (!\is_file($registryPath)) {
            return RegistryLoadResult::fault(
                [\sprintf('%s: the registry file does not exist', $registryPath)]
            );
        }

        $bytes = @\file_get_contents($registryPath);

        if ($bytes === false) {
            return RegistryLoadResult::fault(
                [\sprintf('%s: the registry file could not be read', $registryPath)]
            );
        }

        $rejection = $this->schemaValidator->validateString($bytes, $registryPath);

        if ($rejection !== []) {
            return RegistryLoadResult::fault($rejection);
        }

        $document = new DOMDocument();

        if (!$document->loadXML($bytes, \LIBXML_NONET) || !$document->documentElement instanceof DOMElement) {
            // Unreachable in practice: the schema layer parsed the same bytes a moment
            // ago. Reported rather than returned as an empty registry, which would list
            // nothing and look like a registry that names nothing.
            return RegistryLoadResult::fault(
                [\sprintf('%s: the registry file could not be parsed', $registryPath)]
            );
        }

        $entries = [];

        foreach ($document->documentElement->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $entries[] = $this->entry($child);
            }
        }

        return RegistryLoadResult::loaded($entries);
    }

    private function entry(DOMElement $element): RegistryEntry
    {
        return new RegistryEntry(
            $element->localName,
            $element->getAttribute('element'),
            $element->getAttribute('folder'),
            self::CLIENT_IDS[$element->getAttribute('client')] ?? 0,
            $element->getAttribute('type'),
            $element->getAttribute('repo'),
        );
    }
}
