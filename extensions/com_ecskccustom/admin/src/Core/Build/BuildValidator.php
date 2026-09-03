<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Build;

use RuntimeException;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition\ConditionGroup;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Condition\RuleCondition;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentResolver;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentValues;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Hash\Hasher;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path\PathPolicy;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\MergedSetValidator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleLoader;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleObject;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleSet;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\SchemaValidator;

/**
 * The build's validation pass over the authored rule set and payload tree.
 *
 * Schema, load order and the merged-set checks already live on their own classes.
 * This layer is what they cannot do: hash each payload against `sourceHash`, run
 * path policy without a live site, and warn without stopping the artefact.
 *
 * Load failures are returned alone. A file the schema rejected contributes no
 * objects, so a merged-set pass over what remains would report the author's
 * second mistake as if it were the first. The same gate applies after
 * merged-set validation: hashing a payload the set already said was missing
 * adds nothing an author can act on.
 */
final class BuildValidator
{
    /**
     * Tried in the same order as ConditionEvaluator. Kept here because the
     * warning pass has to compile an expression against the empty string, which
     * the evaluator will not do: an undeterminable value faults before any
     * pattern is evaluated.
     *
     * @var list<string>
     */
    private const DELIMITERS = ['/', '~', '#', '%', '!', '@', ';', ',', '|', '+', '=', ':'];

    private readonly RuleLoader $loader;

    private readonly Hasher $hasher;

    public function __construct(string $schemaPath)
    {
        $this->loader = new RuleLoader(new SchemaValidator($schemaPath));
        $this->hasher = new Hasher();
    }

    public function check(string $rulesDirectory, string $payloadDirectory): BuildCheck
    {
        $loaded = $this->loader->load($rulesDirectory);

        if (!$loaded->isValid()) {
            return new BuildCheck($loaded->violations, []);
        }

        $merged = (new MergedSetValidator($payloadDirectory))->validate($loaded->ruleSet);

        if ($merged !== []) {
            return new BuildCheck($merged, []);
        }

        $errors = [
            ...$this->sourceHashViolations($loaded->ruleSet, $payloadDirectory),
            ...$this->pathPolicyViolations($loaded->ruleSet),
        ];

        return new BuildCheck($errors, $this->warnings($loaded->ruleSet));
    }

    /**
     * @return list<string>
     */
    private function sourceHashViolations(RuleSet $ruleSet, string $payloadDirectory): array
    {
        $violations = [];
        $payloadDirectory = \rtrim($payloadDirectory, '/');

        foreach ($ruleSet->objects() as $object) {
            foreach ($object->actions as $action) {
                if ($action->source === null || $action->sourceHash === null) {
                    continue;
                }

                $path   = $payloadDirectory . '/' . $action->source;
                $result = $this->hasher->hashFile($object->hashType, $path);

                if (!$result->isAvailable()) {
                    $violations[] = \sprintf(
                        '%s: payload %s could not be hashed: %s',
                        $object->label(),
                        $action->source,
                        $result->describe()
                    );

                    continue;
                }

                if ($result->matches($action->sourceHash)) {
                    continue;
                }

                $violations[] = \sprintf(
                    '%s: payload %s hashes to %s, not the declared sourceHash %s',
                    $object->label(),
                    $action->source,
                    $result->describe(),
                    $action->sourceHash
                );
            }
        }

        return $violations;
    }

    /**
     * Path policy needs a site root that exists. The build has none, so it uses
     * an empty temporary directory and the installed-package path the deny list
     * names, which PathPolicy accepts even when that path is not on disk.
     *
     * @return list<string>
     */
    private function pathPolicyViolations(RuleSet $ruleSet): array
    {
        $siteRoot = \sys_get_temp_dir() . '/ecskc-build-site-' . \bin2hex(\random_bytes(8));

        if (!@\mkdir($siteRoot, 0o755) && !\is_dir($siteRoot)) {
            throw new RuntimeException(\sprintf('Could not create a temporary site root at %s', $siteRoot));
        }

        try {
            $policy = new PathPolicy(
                $siteRoot,
                $siteRoot . '/administrator/components/com_ecskccustom'
            );
            $violations = [];

            foreach ($ruleSet->objects() as $object) {
                $decision = $policy->decide($object->targetPath);

                if ($decision->allowed) {
                    continue;
                }

                $violations[] = \sprintf('%s: %s', $object->label(), (string) $decision->reason);
            }

            return $violations;
        } finally {
            @\rmdir($siteRoot);
        }
    }

    /**
     * @return list<string>
     */
    private function warnings(RuleSet $ruleSet): array
    {
        $warnings = [];

        foreach ($ruleSet->byTargetPath() as $objects) {
            $warnings = [...$warnings, ...$this->targetWarnings($objects)];
        }

        foreach ($ruleSet->objects() as $object) {
            $warnings = [...$warnings, ...$this->conditionWarnings($object)];
        }

        return $warnings;
    }

    /**
     * @param list<RuleObject> $objects
     *
     * @return list<string>
     */
    private function targetWarnings(array $objects): array
    {
        $warnings = [];
        $first    = $objects[0];
        $lastKey  = \array_key_last($objects);

        foreach ($objects as $index => $object) {
            if (!$object->create && $object->expectedHash === null) {
                $warnings[] = \sprintf(
                    '%s: may replace but not create, and declares no expectedHash. A vendor '
                    . 'file without a hash is overwritten whatever it currently holds.',
                    $object->label()
                );
            }

            if (!$object->enable && $index !== $lastKey) {
                $warnings[] = \sprintf(
                    '%s: enable="false" and a later object at this path follows it. Every '
                    . 'site not already past this object, fresh installs included, is stranded.',
                    $object->label()
                );
            }

            if ($index > 0 && $first->create && $object->expectedHash === null) {
                $warnings[] = \sprintf(
                    '%s: is after the first object at a package-owned path and declares no '
                    . 'expectedHash. It advances on path alone and writes over whatever it finds.',
                    $object->label()
                );
            }
        }

        if (!$first->create && $this->isAChain($objects)) {
            $warnings[] = \sprintf(
                '%s: this chain cannot advance from a missing file, because the first '
                . 'object may not create it',
                $first->label()
            );
        }

        return $warnings;
    }

    /**
     * Several objects at one path are a chain when a later one expects a payload
     * an earlier one writes. Vendor alternatives expect originals the package
     * never shipped, and are not this shape.
     *
     * @param list<RuleObject> $objects
     */
    private function isAChain(array $objects): bool
    {
        $written = [];

        foreach ($objects as $object) {
            if ($object->expectedHash !== null) {
                $want = \strtolower($object->expectedHash);

                if (\in_array($want, $written, true)) {
                    return true;
                }
            }

            foreach ($object->payloadHashes() as $hash) {
                $written[] = \strtolower($hash);
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function conditionWarnings(RuleObject $object): array
    {
        $warnings = [];

        foreach ($this->conditionsOf($object) as $condition) {
            $operand = $this->collapse($condition->operand);

            if ($condition->type === RuleCondition::LITERAL
                && $condition->attribute === EnvironmentValues::HOST
                && $operand !== EnvironmentResolver::canonicalHost($operand)
            ) {
                $warnings[] = \sprintf(
                    '%s: literal HOST "%s" is not canonical. EnvironmentResolver lower-cases '
                    . 'HOST and strips a trailing root dot, so this condition never matches.',
                    $object->label(),
                    $condition->operand
                );
            }

            if ($condition->type !== RuleCondition::REGEX) {
                continue;
            }

            if (!$this->isAnchored($operand)) {
                $warnings[] = \sprintf(
                    '%s: the regular expression for %s is unanchored. An excluding pattern '
                    . 'that is not anchored matches the value it was written to exclude.',
                    $object->label(),
                    $condition->attribute
                );
            }

            if ($this->matchesEmptyString($operand)) {
                $warnings[] = \sprintf(
                    '%s: the regular expression %s for %s matches the empty string. An '
                    . 'undetermined value faults before any pattern is evaluated, so this '
                    . 'almost always means the author misunderstood the value\'s shape.',
                    $object->label(),
                    \var_export($operand, true),
                    $condition->attribute
                );
            }

            if ($condition->attribute === EnvironmentValues::SITE_URL && $this->looksLikeAHost($operand)) {
                $warnings[] = \sprintf(
                    '%s: the regular expression for SITE_URL appears to describe a host. '
                    . 'SITE_URL is a URL (https://ecskc.eu) and HOST is the host on its own '
                    . '(ecskc.eu); a pattern written for one fails silently against the other.',
                    $object->label()
                );
            }
        }

        return $warnings;
    }

    /**
     * @return list<RuleCondition>
     */
    private function conditionsOf(RuleObject $object): array
    {
        $conditions = [];

        foreach ($object->actions as $action) {
            $this->collectConditions($action->conditions, $conditions);
        }

        return $conditions;
    }

    /**
     * @param list<RuleCondition> $into
     */
    private function collectConditions(?ConditionGroup $group, array &$into): void
    {
        if ($group === null) {
            return;
        }

        foreach ($group->members as $member) {
            if ($member instanceof RuleCondition) {
                $into[] = $member;

                continue;
            }

            $this->collectConditions($member, $into);
        }
    }

    private function collapse(string $value): string
    {
        return \trim((string) \preg_replace('/\s+/', ' ', $value));
    }

    private function isAnchored(string $pattern): bool
    {
        return \str_starts_with($pattern, '^') && \str_ends_with($pattern, '$');
    }

    /**
     * A SITE_URL pattern that names a host without a scheme. The usual mistake is
     * writing `^ecskc\.eu$` (or the exclusion of that host) against SITE_URL,
     * which holds `https://ecskc.eu`.
     */
    private function looksLikeAHost(string $pattern): bool
    {
        if (\str_contains($pattern, '://')) {
            return false;
        }

        return \preg_match('/[A-Za-z0-9-]+(?:\\\\)?\.[A-Za-z]{2,}/', $pattern) === 1;
    }

    private function matchesEmptyString(string $pattern): bool
    {
        if ($pattern === '') {
            return true;
        }

        $delimiter = null;

        foreach (self::DELIMITERS as $candidate) {
            if (!\str_contains($pattern, $candidate)) {
                $delimiter = $candidate;

                break;
            }
        }

        if ($delimiter === null) {
            return false;
        }

        $result = @\preg_match($delimiter . $pattern . $delimiter . 'D', '');

        return $result === 1;
    }
}
