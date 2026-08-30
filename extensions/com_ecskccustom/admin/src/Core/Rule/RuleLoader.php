<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule;

use DOMDocument;
use DOMElement;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition\ConditionGroup;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition\RuleCondition;

/**
 * Reads the rule directory and merges every rule file into one rule set.
 *
 * Files load in **byte-order alphabetical** order, and objects keep their document
 * order within each file. That order is the semantics: the engine walks objects as
 * it finds them and does no version comparison of its own.
 *
 * ## Why the load order has to be unambiguous
 *
 * Byte order and a case-insensitive order disagree whenever two filenames differ
 * in case: byte order puts every capital ahead of every lower-case letter, so
 * `Kunena.xml` loads before `helix.xml` while a human reading the directory expects
 * the reverse. An author who cannot predict the order cannot author a chain that
 * spans two files. Rather than pick one order and hope, this refuses any directory
 * whose two orders disagree, which is a rename away from fixed.
 *
 * ## Schema first, then parse
 *
 * Each file is schema-validated before it is parsed, and a file that fails
 * contributes no objects - its shape cannot be trusted enough to read. The bytes
 * are parsed a second time to build the objects rather than reusing the validator's
 * document, because SchemaValidator owns libxml's global error queue for the
 * duration of its call and handing its internals out would leak that ownership.
 *
 * Attribute defaults are applied here, explicitly, rather than read back from the
 * schema. libxml can fill defaults during validation, but relying on it would make
 * the parsed meaning of an object depend on whether validation had run first.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class RuleLoader
{
    public const EXTENSION = 'xml';

    public function __construct(private readonly SchemaValidator $schemaValidator)
    {
    }

    public function load(string $rulesDirectory): RuleLoadResult
    {
        if (!\is_dir($rulesDirectory)) {
            return new RuleLoadResult(
                new RuleSet([], []),
                [\sprintf('%s: the rule directory does not exist', $rulesDirectory)]
            );
        }

        $names = $this->ruleFileNames($rulesDirectory);

        if ($names === null) {
            return new RuleLoadResult(
                new RuleSet([], []),
                [\sprintf('%s: the rule directory could not be read', $rulesDirectory)]
            );
        }

        $violations = $this->ambiguousOrderViolations($names);
        $objects    = [];
        $hashTypes  = [];

        foreach ($names as $name) {
            // Brief 5.4: "The rule set is only ever read from the installed package.
            // Rules are not accepted from an upload, an arbitrary location, or the
            // database." A symbolic link is an arbitrary location wearing the
            // package's own name, and is_file() follows one without saying so.
            if (\is_link($rulesDirectory . '/' . $name)) {
                $violations[] = \sprintf(
                    '%s: is a symbolic link. The rule set is only ever read from the installed '
                    . 'package, so a rule file has to be one.',
                    $name
                );

                continue;
            }

            $bytes = @\file_get_contents($rulesDirectory . '/' . $name);

            if ($bytes === false) {
                $violations[] = \sprintf('%s: the rule file could not be read', $name);

                continue;
            }

            // Validated from the bytes rather than the path so every message is
            // labelled with the rule file's own name. A violation carrying an
            // absolute path would differ between the build host and an installed
            // site, and the author needs the name they typed.
            $rejection = $this->schemaValidator->validateString($bytes, $name);

            if ($rejection !== []) {
                foreach ($rejection as $message) {
                    $violations[] = $message;
                }

                continue;
            }

            $document = new DOMDocument();

            if (!$document->loadXML($bytes, \LIBXML_NONET)) {
                // Unreachable in practice: the schema layer parsed the same bytes a
                // moment ago. Reported rather than ignored, because the alternative
                // is a rule file that silently contributes nothing.
                $violations[] = \sprintf('%s: the rule file could not be parsed', $name);

                continue;
            }

            $patch = $document->documentElement;

            if ($patch !== null) {
                // Recorded even when the file carries no objects. The declaration is
                // the file's, not the objects', and a file with none still makes one.
                $hashTypes[$name] = $this->collapse($patch->getAttribute('hashType'));
            }

            foreach ($this->parseFile($document, $name) as $object) {
                $objects[] = $object;
            }
        }

        return new RuleLoadResult(new RuleSet($objects, $hashTypes), $violations);
    }

    /**
     * Rule file names in byte-order alphabetical order.
     *
     * @return list<string>|null Null when the directory could not be read.
     */
    private function ruleFileNames(string $rulesDirectory): ?array
    {
        $entries = @\scandir($rulesDirectory);

        if ($entries === false) {
            return null;
        }

        $names = [];

        foreach ($entries as $entry) {
            if (!\is_file($rulesDirectory . '/' . $entry)) {
                continue;
            }

            // index.html is the folder's own guard, not a rule.
            if (\strtolower(\pathinfo($entry, \PATHINFO_EXTENSION)) !== self::EXTENSION) {
                continue;
            }

            $names[] = $entry;
        }

        \sort($names, \SORT_STRING);

        return $names;
    }

    /**
     * @param list<string> $names Already in byte order.
     *
     * @return list<string>
     */
    private function ambiguousOrderViolations(array $names): array
    {
        $violations = [];
        $count      = \count($names);

        for ($i = 1; $i < $count; $i++) {
            $previous   = $names[$i - 1];
            $current    = $names[$i];
            $comparison = \strcasecmp($previous, $current);

            if ($comparison === 0) {
                $violations[] = \sprintf(
                    'rules: %s and %s differ only in case, so which loads first depends on '
                    . 'the collation. Rename one.',
                    $previous,
                    $current
                );

                continue;
            }

            if ($comparison > 0) {
                $violations[] = \sprintf(
                    'rules: the load order of %s and %s is ambiguous. Byte order puts %s '
                    . 'first, a case-insensitive order puts %s first. Rename one so both '
                    . 'orders agree.',
                    $previous,
                    $current,
                    $previous,
                    $current
                );
            }
        }

        return $violations;
    }

    /**
     * @return list<RuleObject>
     */
    private function parseFile(DOMDocument $document, string $sourceFile): array
    {
        $patch = $document->documentElement;

        if (!$patch instanceof DOMElement) {
            return [];
        }

        $hashType = $this->collapse($patch->getAttribute('hashType'));
        $objects  = [];

        foreach ($patch->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            if ($child->localName === 'object') {
                $objects[] = $this->parseObject($child, $sourceFile, $hashType, '');

                continue;
            }

            if ($child->localName === 'root') {
                $rootPath = $this->collapse($child->getAttribute('path'));

                foreach ($this->childElements($child, 'object') as $objectElement) {
                    $objects[] = $this->parseObject($objectElement, $sourceFile, $hashType, $rootPath);
                }
            }
        }

        return $objects;
    }

    private function parseObject(
        DOMElement $element,
        string $sourceFile,
        string $hashType,
        string $rootPath,
    ): RuleObject {
        $id   = $this->childElements($element, 'id')[0] ?? null;
        $dest = $id === null ? '' : $this->childText($id, 'destPathFileName');

        $absoluteForm = \str_starts_with($dest, '/');

        // No normalisation: the schema refuses backslashes, empty segments, and "."
        // or ".." segments, so root and destination can only be concatenated. An
        // absolute destination is measured from the web root and ignores the root.
        if ($absoluteForm) {
            $targetPath = \ltrim($dest, '/');
        } elseif ($rootPath === '') {
            $targetPath = $dest;
        } else {
            $targetPath = $rootPath . '/' . $dest;
        }

        $actions = [];

        foreach ($this->childElements($element, 'action') as $actionElement) {
            $action = $this->parseAction($actionElement);

            if ($action !== null) {
                $actions[] = $action;
            }
        }

        return new RuleObject(
            $sourceFile,
            $hashType,
            $rootPath,
            $dest,
            $targetPath,
            $absoluteForm,
            (int) $element->getAttribute('version'),
            $this->boolAttribute($element, 'create', false),
            $this->boolAttribute($element, 'replace', true),
            $this->boolAttribute($element, 'enable', true),
            $id === null ? null : $this->optionalChildText($id, 'expectedHash'),
            $actions,
        );
    }

    private function parseAction(DOMElement $element): ?RuleAction
    {
        $operations = $this->childElements($element, 'operations')[0] ?? null;

        if ($operations === null) {
            return null;
        }

        $group      = $this->childElements($element, 'conditions')[0] ?? null;
        $conditions = $group === null ? null : $this->parseConditionGroup($group);

        $copy = $this->childElements($operations, 'copy')[0] ?? null;

        if ($copy !== null) {
            return RuleAction::copy(
                $this->childText($copy, 'srcPathFileName'),
                $this->childText($copy, 'sourceHash'),
                $conditions
            );
        }

        $remove = $this->childElements($operations, 'remove')[0] ?? null;

        if ($remove !== null) {
            return RuleAction::remove($this->boolAttribute($remove, 'backup', true), $conditions);
        }

        return null;
    }

    /**
     * Groups nest, so this recurses. Nothing here judges what it reads: an unknown
     * type or an attribute outside the vocabulary is the schema's to refuse and the
     * evaluator's to fault on, and the loader inventing a third opinion is how the
     * two layers come to disagree.
     */
    private function parseConditionGroup(DOMElement $element): ConditionGroup
    {
        $members = [];

        foreach ($element->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            if ($child->localName === 'conditions') {
                $members[] = $this->parseConditionGroup($child);

                continue;
            }

            if ($child->localName === 'condition') {
                $members[] = new RuleCondition(
                    $child->hasAttribute('type')
                        ? $this->collapse($child->getAttribute('type'))
                        : RuleCondition::LITERAL,
                    $this->collapse($child->getAttribute('attribute')),
                    $this->operand($child),
                    $child->hasAttribute('description')
                        ? $this->collapse($child->getAttribute('description'))
                        : null
                );
            }
        }

        return new ConditionGroup($this->collapse($element->getAttribute('match')), $members);
    }

    /**
     * The text a condition matches against: trimmed, and otherwise untouched.
     *
     * Trimmed because leading and trailing whitespace here can only have come from
     * pretty-printing, and none of the four values it is compared against carries
     * any: an indented literal would silently match nothing, and the customisation
     * would quietly never apply.
     *
     * Not collapsed, unlike a token. Collapsing rewrites the inside of a regular
     * expression, where a run of literal spaces is as significant as any other
     * character, and rewriting an author's expression without saying so is the same
     * fault as rewriting their path.
     */
    private function operand(DOMElement $element): string
    {
        return \trim($element->textContent);
    }

    /**
     * @return list<DOMElement>
     */
    private function childElements(DOMElement $parent, string $name): array
    {
        $found = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                $found[] = $child;
            }
        }

        return $found;
    }

    private function childText(DOMElement $parent, string $name): string
    {
        return $this->optionalChildText($parent, $name) ?? '';
    }

    private function optionalChildText(DOMElement $parent, string $name): ?string
    {
        $element = $this->childElements($parent, $name)[0] ?? null;

        return $element === null ? null : $this->collapse($element->textContent);
    }

    /**
     * The whitespace normalisation XSD applies to xs:token, applied here too.
     *
     * `hashType` is the one xs:token in the schema, so it is the one value where a
     * padded declaration is valid and has to be read as the token it collapses to.
     *
     * Every path type refuses whitespace outright rather than collapsing it, so for
     * those this is a no-op on anything that got past the schema. It stays applied
     * to them all the same: the two layers would otherwise disagree about what a
     * value is the moment the schema were loosened, and that disagreement is what
     * made a pretty-printed path silently name a different file.
     */
    private function collapse(string $value): string
    {
        return \trim((string) \preg_replace('/\s+/', ' ', $value));
    }

    /**
     * xs:boolean is any of "true", "false", "1" and "0"; an author may write either
     * spelling and the schema accepts both.
     */
    private function boolAttribute(DOMElement $element, string $name, bool $default): bool
    {
        if (!$element->hasAttribute($name)) {
            return $default;
        }

        return \in_array(\trim($element->getAttribute($name)), ['true', '1'], true);
    }
}
