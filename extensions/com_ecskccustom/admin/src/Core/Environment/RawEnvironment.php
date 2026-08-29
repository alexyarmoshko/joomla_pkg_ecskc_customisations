<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment;

/**
 * The raw Joomla inputs a condition is evaluated from, before any derivation.
 *
 * Decision 16 keeps derivation in the core and raw inputs in the adapter, so
 * production and temporary-tree tests share one derivation path. This object is
 * therefore deliberately dull: it carries what Joomla said, not what it means.
 *
 * Every field is nullable because "not determinable" is a real answer and has to
 * survive as far as the core. **The core faults on an undeterminable value and
 * evaluates no pattern against it** - it never substitutes an empty string, which
 * would satisfy a negative lookahead and invert the exclusion its author wrote.
 * Brief section 6 records the case where that would have applied the Community
 * Builder licence-bypass customisation on production.
 *
 * Ticket 13 owns the derivation of SITE_URL, HOST, JOOMLA_VERSION and PHP_VERSION
 * from these four fields.
 */
final class RawEnvironment
{
    public function __construct(
        public readonly ?string $liveSite,
        public readonly ?string $uriBase,
        public readonly ?string $joomlaVersion,
        public readonly ?string $phpVersion,
    ) {
    }
}
