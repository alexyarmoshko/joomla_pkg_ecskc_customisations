<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core\Path;

/**
 * What path policy decided about one target, and why.
 *
 * A refusal carries its reason rather than a code, because the reason is what the
 * report shows an operator and what the build shows an author. Both need to know
 * which of confinement, the allow list or the deny list refused the path - the
 * three mean different things about what the author got wrong.
 *
 * An allowed decision carries the resolved absolute path, so nothing downstream
 * has to resolve it a second time and reach a different answer.
 */
final class PathDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $path,
        public readonly ?string $reason,
    ) {
    }

    public static function allow(string $resolvedPath): self
    {
        return new self(true, $resolvedPath, null);
    }

    public static function refuse(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
