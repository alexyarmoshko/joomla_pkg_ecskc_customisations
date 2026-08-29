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

/**
 * What loading the rule directory produced: the merged set, and what was wrong.
 *
 * Both are returned together because loading reports every problem in one pass
 * rather than stopping at the first, the same bargain SchemaValidator makes.
 *
 * **A set that did not load cleanly must not be validated as a merged set.** A
 * file rejected by the schema contributes no objects, so its payloads look
 * unreferenced and its half of a chain looks broken - findings about the author's
 * second mistake that are really artefacts of the first. Gate on isValid() and
 * report the load violations alone.
 */
final class RuleLoadResult
{
    /**
     * @param list<string> $violations
     */
    public function __construct(
        public readonly RuleSet $ruleSet,
        public readonly array $violations,
    ) {
    }

    public function isValid(): bool
    {
        return $this->violations === [];
    }
}
