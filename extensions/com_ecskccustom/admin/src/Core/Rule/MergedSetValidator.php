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

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The second validation layer: everything only visible once the files are merged.
 *
 * The schema settles shape and vocabulary within one file. These checks cannot be
 * made there at all, because the thing being judged spans files: a chain may start
 * in one rule file and continue in another, and a duplicate path is not a duplicate
 * until both halves are in the same set.
 *
 * Every violation names the rule file and the object it came from, because an
 * author reading a build failure has the whole merged set in front of them and no
 * way to guess which of thirteen objects at a path is the wrong one.
 *
 * ## Ownership is read from permissions, not from a marker
 *
 * Nothing in the rule set says whether a target is vendor-owned or package-owned,
 * and brief 5.2 refuses to add a marker: it would be a second, weaker way of
 * describing a file. The permissions get close enough, and this uses them the same
 * way the brief does. A path whose first object may **create** the file is
 * package-owned - the package put every byte that file has ever held - so every
 * later expectation at that path must be a payload the package itself shipped, and
 * one that is not is a mis-typed link.
 *
 * A path whose first object may only replace is a customisation of a vendor file.
 * Its objects are alternatives rather than a chain, each expecting an original from
 * a different vendor build, so an expectation matching no earlier payload is
 * exactly right and is not reported here. Those are checked against
 * `data/baselines.xml` at authoring time instead (tickets 19 and 20), which is the
 * only place the vendor archives can be reached.
 *
 * ## What deliberately is not here
 *
 * Path confinement, the extension allow list and the deny list belong to path
 * policy (ticket 14). Hashing a payload's bytes to check them against `sourceHash`
 * belongs to ticket 15. This layer checks that a payload is named exactly once and
 * exists; whether its bytes agree with the declaration is a question for whatever
 * can hash them.
 *
 * **Nothing here warns.** Every check returns a violation or nothing, and a
 * violation stops the artefact. The plan's warning pass - a replace-only object
 * declaring no expected-original hash, a disabled object that is not the last at
 * its path, a regular expression matching the empty string - is the build's, and is
 * not implemented yet.
 *
 * No Joomla dependency: the core is testable on a bare PHP CLI.
 */
final class MergedSetValidator
{
    /**
     * Version 1 supports one algorithm. The algorithm is read from the patch
     * declaration rather than hardcoded at the point of use, so an unsupported
     * declaration has to be rejected here or it would reach a hasher that cannot
     * honour it.
     */
    public const SUPPORTED_HASH_TYPE = 'sha256';

    /**
     * Joomla's folder guard, present in payload/ so the directory ships and is
     * never a rule's payload.
     */
    private const NOT_A_PAYLOAD = 'index.html';

    private readonly string $payloadDirectory;

    public function __construct(string $payloadDirectory)
    {
        // Trailing separator removed on the way in. Payload paths are cut out of a
        // full pathname by length, so one extra character here shifts every one of
        // them and reports the whole payload tree as unreferenced. PackagePaths
        // never produces a trailing slash, but the build supplies its own directory.
        $this->payloadDirectory = \rtrim($payloadDirectory, '/');
    }

    /**
     * @return list<string> Human-readable violations; empty means valid.
     */
    public function validate(RuleSet $ruleSet): array
    {
        return [
            ...$this->hashTypeViolations($ruleSet),
            ...$this->permissionViolations($ruleSet),
            ...$this->pathViolations($ruleSet),
            ...$this->payloadViolations($ruleSet),
        ];
    }

    /**
     * @return list<string>
     */
    private function hashTypeViolations(RuleSet $ruleSet): array
    {
        $violations = [];

        // Read from the files' own declarations, not from the objects. A patch
        // element may legally carry no objects, and such a file still declares an
        // algorithm; taken from the objects it would pass unexamined.
        foreach ($ruleSet->hashTypes() as $sourceFile => $hashType) {
            if ($hashType === self::SUPPORTED_HASH_TYPE) {
                continue;
            }

            $violations[] = \sprintf(
                '%s: declares hashType %s; version 1 supports %s only',
                $sourceFile,
                \var_export($hashType, true),
                self::SUPPORTED_HASH_TYPE
            );
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function permissionViolations(RuleSet $ruleSet): array
    {
        $violations = [];

        foreach ($ruleSet->objects() as $object) {
            if (!$object->create && !$object->replace) {
                $violations[] = \sprintf(
                    '%s: may neither create nor replace, so it can do nothing',
                    $object->label()
                );
            }
        }

        return $violations;
    }

    /**
     * Duplicate path and version pairs, version order, the two spellings of one
     * target, and chain links.
     *
     * @return list<string>
     */
    private function pathViolations(RuleSet $ruleSet): array
    {
        $violations = [];

        foreach ($ruleSet->byTargetPath() as $targetPath => $objects) {
            $violations = [
                ...$violations,
                ...$this->spellingViolations($targetPath, $objects),
                ...$this->versionViolations($targetPath, $objects),
                ...$this->chainViolations($objects),
            ];
        }

        return $violations;
    }

    /**
     * @param list<RuleObject> $objects
     *
     * @return list<string>
     */
    private function spellingViolations(string $targetPath, array $objects): array
    {
        $absolute = [];
        $relative = [];

        foreach ($objects as $object) {
            if ($object->absoluteForm) {
                $absolute[] = $object->label();
            } else {
                $relative[] = $object->label();
            }
        }

        if ($absolute === [] || $relative === []) {
            return [];
        }

        // The absolute form would win, but a target named two ways is an authoring
        // fault and is reported as one rather than silently resolved.
        return [\sprintf(
            'target %s is named in both absolute and relative form: absolute at %s, relative at %s',
            $targetPath,
            \implode(', ', $absolute),
            \implode(', ', $relative)
        )];
    }

    /**
     * @param list<RuleObject> $objects
     *
     * @return list<string>
     */
    private function versionViolations(string $targetPath, array $objects): array
    {
        $violations = [];
        $seen       = [];
        $highest    = null;

        foreach ($objects as $object) {
            if (isset($seen[$object->version])) {
                $violations[] = \sprintf(
                    '%s: version %d appears twice at this target, first at %s. A repeated '
                    . 'version makes the walk ambiguous and the backup names collide.',
                    $object->label(),
                    $object->version,
                    $seen[$object->version]
                );
            } elseif ($highest !== null && $object->version < $highest) {
                // The engine walks objects in document order and does no version
                // comparison of its own, so the order in the merged set is the
                // semantics. This is what guarantees it matches the versions.
                $violations[] = \sprintf(
                    '%s: appears after version %d, so the objects at %s are out of version order',
                    $object->label(),
                    $highest,
                    $targetPath
                );
            }

            // ??= so a third object at one version still points at the first, not
            // the second. The author is being sent somewhere to compare.
            $seen[$object->version] ??= $object->label();
            $highest                  = $highest === null ? $object->version : \max($highest, $object->version);
        }

        return $violations;
    }

    /**
     * @param list<RuleObject> $objects
     *
     * @return list<string>
     */
    private function chainViolations(array $objects): array
    {
        $head = $objects[0];

        if (!$head->create) {
            // Vendor-owned: the objects are alternatives, each expecting an original
            // from a different vendor build. Their expectations are established
            // outside the rule set and are checked against data/baselines.xml.
            return [];
        }

        $violations = [];
        $available  = [];

        foreach ($objects as $index => $object) {
            // Tested before this object's own payloads are folded in. An object that
            // could satisfy the check with its own payload would accept the exact
            // mistake this exists to catch: expectedHash copy-pasted from the
            // sourceHash directly above it, which links to nothing.
            if (
                $index > 0
                && $object->expectedHash !== null
                && !isset($available[\strtolower($object->expectedHash)])
            ) {
                $violations[] = \sprintf(
                    '%s: expects a hash no earlier object at this target writes, so the '
                    . 'package-owned chain does not link up',
                    $object->label()
                );
            }

            // A disabled object still links the chain structurally; that it strands
            // a site not already past it is a build warning, not a broken link.
            foreach ($object->payloadHashes() as $hash) {
                $available[\strtolower($hash)] = $object->label();
            }
        }

        return $violations;
    }

    /**
     * Payloads named but absent, named more than once, or present and named by
     * nothing.
     *
     * @return list<string>
     */
    private function payloadViolations(RuleSet $ruleSet): array
    {
        $violations = [];

        /** @var array<string, list<string>> $referencedBy */
        $referencedBy = [];

        foreach ($ruleSet->objects() as $object) {
            foreach ($object->payloadSources() as $source) {
                $referencedBy[$source][] = $object->label();
            }
        }

        foreach ($referencedBy as $source => $labels) {
            // Counted per object, not per action. One object may carry several
            // actions writing the same payload under different conditions - brief
            // 5.2's conditional variation - and that is one owner, not two.
            $owners = \array_values(\array_unique($labels));

            if (\count($owners) > 1) {
                $violations[] = \sprintf(
                    'payload %s is named more than once, at %s. A payload belongs to exactly '
                    . 'one object.',
                    $source,
                    \implode(', ', $owners)
                );
            }

            if (!\is_file($this->payloadDirectory . '/' . $source)) {
                $violations[] = \sprintf('%s: names the payload %s, which does not exist', $owners[0], $source);
            }
        }

        $present = $this->presentPayloads();

        if ($present === null) {
            return [
                ...$violations,
                \sprintf('%s: the payload directory does not exist', $this->payloadDirectory),
            ];
        }

        foreach ($present as $source) {
            if (!isset($referencedBy[$source])) {
                $violations[] = \sprintf(
                    'payload %s is present but no object names it. An unreferenced payload '
                    . 'ships bytes nothing will ever write.',
                    $source
                );
            }
        }

        return $violations;
    }

    /**
     * Every payload file, as a path relative to the payload directory.
     *
     * @return list<string>|null Null when the directory does not exist.
     */
    private function presentPayloads(): ?array
    {
        if (!\is_dir($this->payloadDirectory)) {
            return null;
        }

        $found    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->payloadDirectory, FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() === self::NOT_A_PAYLOAD) {
                continue;
            }

            $found[] = \substr($file->getPathname(), \strlen($this->payloadDirectory) + 1);
        }

        \sort($found, \SORT_STRING);

        return $found;
    }
}
