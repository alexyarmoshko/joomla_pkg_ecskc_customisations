<?php

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule;

use DOMDocument;
use LibXMLError;
use RuntimeException;

/**
 * Validates one rule file against the rule schema.
 *
 * This is the first of two validation layers. It settles shape and vocabulary
 * within a single file. Everything judged across the merged rule set - version
 * order, chain links, payload ownership, path policy, the hash algorithm -
 * belongs to semantic validation, which runs over the merged set and never file
 * by file.
 *
 * Violations are returned rather than thrown, so the build can report every
 * problem in a rule file in one pass instead of one per run.
 *
 * **This class owns libxml's global error queue for the duration of a call.** It
 * clears the queue on entry and on exit, and restores the internal-error mode it
 * found. Any errors a caller had already queued are discarded, so do not call it
 * with unread libxml errors pending. The queue is process-global and there is no
 * way to validate without touching it; owning it explicitly is preferable to
 * leaking this validator's own errors into whatever runs next.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class SchemaValidator
{
    public function __construct(private readonly string $schemaPath)
    {
        if (!\is_file($this->schemaPath)) {
            throw new RuntimeException(
                \sprintf('The rule schema is missing at %s', $this->schemaPath)
            );
        }
    }

    /**
     * @return list<string> Human-readable violations; empty means valid.
     */
    public function validateFile(string $rulePath): array
    {
        if (!\is_file($rulePath)) {
            return [\sprintf('%s: the rule file does not exist', $rulePath)];
        }

        $bytes = \file_get_contents($rulePath);

        if ($bytes === false) {
            return [\sprintf('%s: the rule file could not be read', $rulePath)];
        }

        return $this->validateString($bytes, $rulePath);
    }

    /**
     * @return list<string> Human-readable violations; empty means valid.
     */
    public function validateString(string $xml, string $label = 'rule'): array
    {
        $previousUseErrors = \libxml_use_internal_errors(true);
        \libxml_clear_errors();

        try {
            $document = new DOMDocument();

            // LIBXML_NONET: a rule file must never reach the network to validate.
            if (!$document->loadXML($xml, \LIBXML_NONET)) {
                return $this->collect($label, 'not well-formed XML');
            }

            if (!$document->schemaValidate($this->schemaPath)) {
                return $this->collect($label, 'does not satisfy the rule schema');
            }

            return [];
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previousUseErrors);
        }
    }

    /**
     * @return list<string>
     */
    private function collect(string $label, string $summary): array
    {
        $violations = [];

        foreach (\libxml_get_errors() as $error) {
            $violations[] = \sprintf(
                '%s: %s (line %d)',
                $label,
                \trim($this->describe($error)),
                $error->line
            );
        }

        if ($violations === []) {
            $violations[] = \sprintf('%s: %s', $label, $summary);
        }

        return $violations;
    }

    private function describe(LibXMLError $error): string
    {
        return $error->message;
    }
}
