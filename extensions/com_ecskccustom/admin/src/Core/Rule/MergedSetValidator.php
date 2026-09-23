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
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\HashAlgorithm;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * The second validation layer: everything only visible once the files are merged.
 *
 * The schema settles shape and vocabulary within one file. These checks cannot be
 * made there at all, because the thing being judged spans files: a chain may start
 * in one rule file and continue in another, and a duplicate path is not a duplicate
 * until both halves are in the same set.
 */
final class MergedSetValidator
{
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
            ...$this->digestViolations($ruleSet),
            ...$this->permissionViolations($ruleSet),
            ...$this->pathViolations($ruleSet),
            ...$this->payloadViolations($ruleSet),
        ];
    }

    /**
     * The algorithm is read from the patch declaration rather than hardcoded at the
     * point of use, so an unsupported declaration is rejected here or it reaches a
     * hasher that cannot honour it. Which algorithms those are is `HashAlgorithm`'s
     * to say, and it is asked rather than copied: two lists that had to agree would
     * eventually not.
     *
     * @return list<string>
     */
    private function hashTypeViolations(RuleSet $ruleSet): array
    {
        $violations = [];

        // Read from the files' own declarations, not from the objects. A patch
        // element may legally carry no objects, and such a file still declares an
        // algorithm; taken from the objects it would pass unexamined.
        foreach ($ruleSet->hashTypes() as $sourceFile => $hashType) {
            if (HashAlgorithm::isSupported($hashType)) {
                continue;
            }

            $violations[] = \sprintf('%s: %s', $sourceFile, HashAlgorithm::unsupportedReason($hashType));
        }

        return $violations;
    }

    /**
     * Every declared digest must be the width its file's algorithm produces.
     *
     * @return list<string>
     */
    private function digestViolations(RuleSet $ruleSet): array
    {
        $violations = [];

        foreach ($ruleSet->objects() as $object) {
            $width = HashAlgorithm::digestLength($object->hashType);

            if ($width === null) {

                continue;
            }

            $digests = $object->payloadHashes();

            if ($object->expectedHash !== null) {
                $digests[] = $object->expectedHash;
            }

            foreach ($digests as $digest) {
                if (\strlen($digest) !== $width) {
                    $violations[] = \sprintf(
                        '%s: the digest %s is %d characters; %s produces %d',
                        $object->label(),
                        $digest,
                        \strlen($digest),
                        $object->hashType,
                        $width
                    );
                }
            }
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
     * target, chain links, and no-copy removals that can never restore a predecessor.
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
                ...$this->noCopyRemovalViolations($objects),
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

                $violations[] = \sprintf(
                    '%s: appears after version %d, so the objects at %s are out of version order',
                    $object->label(),
                    $highest,
                    $targetPath
                );
            }

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

            foreach ($object->payloadHashes() as $hash) {
                $available[\strtolower($hash)] = $object->label();
            }
        }

        return $violations;
    }

    /**
     * A no-copy removal keeps no backup of what it takes away. Revert can put the
     * predecessor back only when an earlier object at this target ships those
     * bytes. Vendor-owned chains are included: a first-object vendor removal with
     * backup="false" expects an original the package never wrote, and would be
     * permanently one-way if it passed.
     *
     * @param list<RuleObject> $objects
     *
     * @return list<string>
     */
    private function noCopyRemovalViolations(array $objects): array
    {
        $violations = [];
        $available  = [];

        foreach ($objects as $object) {
            $expected = $object->expectedHash !== null ? \strtolower($object->expectedHash) : null;

            foreach ($object->actions as $action) {
                if (!$action->isRemove() || $action->backup) {
                    continue;
                }

                if ($expected !== null && isset($available[$expected])) {
                    continue;
                }

                $violations[] = $expected === null
                    ? \sprintf(
                        '%s: removes with backup="false" and names no expected original, so it may '
                        . 'take away any file it finds and leaves revert nothing to restore',
                        $object->label()
                    )
                    : \sprintf(
                        '%s: removes with backup="false" and expects a hash no earlier object at '
                        . 'this target ships, so revert can never restore the predecessor',
                        $object->label()
                    );
            }

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

            $owners = \array_values(\array_unique($labels));

            if (\count($owners) > 1) {
                $violations[] = \sprintf(
                    'payload %s is named more than once, at %s. A payload belongs to exactly '
                    . 'one object.',
                    $source,
                    \implode(', ', $owners)
                );
            }

            $resolved = $this->resolvePayload($source);

            if ($resolved === null) {
                $violations[] = \sprintf('%s: names the payload %s, which does not exist', $owners[0], $source);
            } elseif (!$this->isInsidePayloadDirectory($resolved)) {
                // Reached through a symbolic link, as a file or as a parent directory.
                // Whatever hashes and copies these bytes later would take them from
                // wherever the link points.
                $violations[] = \sprintf(
                    '%s: names the payload %s, which resolves outside the payload directory, to %s',
                    $owners[0],
                    $source,
                    $resolved
                );
            }
        }

        $scan = $this->scanPayloads();

        if ($scan === null) {
            return [
                ...$violations,
                \sprintf('%s: the payload directory does not exist', $this->payloadDirectory),
            ];
        }

        foreach ($scan['unreadable'] as $directory) {
            $violations[] = \sprintf(
                'the payload directory %s cannot be read. A directory that cannot be listed may '
                . 'hold a payload, so the set cannot be judged complete.',
                $directory
            );
        }

        foreach ($scan['links'] as $link) {
            $violations[] = \sprintf(
                'payload %s is a symbolic link. A payload is bytes the package ships, so it has '
                . 'to be a file in the payload directory.',
                $link
            );
        }

        foreach ($scan['files'] as $source) {
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

    private function resolvePayload(string $source): ?string
    {
        $resolved = \realpath($this->payloadDirectory . '/' . $source);

        return $resolved === false || !\is_file($resolved) ? null : $resolved;
    }

    private function isInsidePayloadDirectory(string $resolved): bool
    {
        $directory = \realpath($this->payloadDirectory);

        return $directory !== false && \str_starts_with($resolved, $directory . '/');
    }

    /**
     * Every payload file, and every symbolic link, relative to the payload directory.
     *
     * Links are collected rather than followed or skipped. Skipping one hides it: a
     * planted link that nothing references would never be reported, and a link
     * standing in for a directory would take the whole subtree with it.
     * RecursiveDirectoryIterator does not descend into a linked directory by default,
     * so such a link arrives here as a single entry that is not a file.
     *
     * @return array{files: list<string>, links: list<string>, unreadable: list<string>}|null
     *         Null when the directory does not exist.
     */
    private function scanPayloads(): ?array
    {
        if (!\is_dir($this->payloadDirectory)) {
            return null;
        }

        $found      = [];
        $links      = [];
        $unreadable = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->payloadDirectory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        } catch (UnexpectedValueException) {
            // The root exists - is_dir said so - but cannot be opened, so nothing
            // below it can be judged and there is nothing else to collect.
            return ['files' => [], 'links' => [], 'unreadable' => [$this->payloadDirectory]];
        }

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $relative = \substr($file->getPathname(), \strlen($this->payloadDirectory) + 1);

            if ($file->isLink()) {
                $links[] = $relative;

                continue;
            }

            if ($file->isDir()) {
                if (!$file->isReadable()) {
                    $unreadable[] = $relative;
                }

                continue;
            }

            if (!$file->isFile()) {
                continue;
            }

            // The installer writes a deny-all .htaccess into payload/, and the folder
            // ships an index.html. Neither is a payload, and reporting them as
            // unreferenced would make every installed site fail its own rule set.
            // Judged on the payload-relative path: the same name nested inside is
            // content, and content is what this check exists to look at.
            if (PackagePaths::isFolderGuard($relative)) {
                continue;
            }

            $found[] = $relative;
        }

        \sort($found, \SORT_STRING);
        \sort($links, \SORT_STRING);
        \sort($unreadable, \SORT_STRING);

        return ['files' => $found, 'links' => $links, 'unreadable' => $unreadable];
    }
}
