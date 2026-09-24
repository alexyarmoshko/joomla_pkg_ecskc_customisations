<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Model;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\Reverter;

\defined('_JEXEC') or die;

/**
 * A write run's report, kept in the session between the task and the page that shows it.
 *
 * Only strings, integers, and arrays of them: the session lives in the database, and
 * a serialized core object would stop reading back once an update changed its class.
 * `fromArray()` returns null for anything that is not the shape `toArray()` writes,
 * and the page then runs a fresh check instead.
 *
 * @phpstan-type Row array{
 *     path: string,
 *     status: string,
 *     state: string,
 *     reason: string,
 *     required: array<string, string>,
 *     backup: string,
 *     objects: list<string>,
 *     plan: list<string>,
 *     outcome: string|null
 * }
 */
final class WriteResult
{
    /** The user-state key the task writes and the view takes once. */
    public const STATE_KEY = 'com_ecskccustom.targets.result';

    /** The field only the whole-set dry run's "Apply this plan" button sends. */
    public const CONFIRM_FIELD = 'apply_confirmed';

    public const APPLY_ALL = 'applyall';
    public const APPLY     = 'apply';
    public const REVERT    = 'revert';
    public const REDO      = 'redo';
    public const FREEZE    = 'freeze';
    public const UNFREEZE  = 'unfreeze';

    public const OPERATIONS = [
        self::APPLY_ALL,
        self::APPLY,
        self::REVERT,
        self::REDO,
        self::FREEZE,
        self::UNFREEZE,
    ];

    /**
     * @param list<string>          $faults
     * @param array<string, int>    $counts
     * @param list<Row>             $targets
     */
    private function __construct(
        public readonly string $operation,
        public readonly ?string $path,
        public readonly array $faults,
        public readonly ?string $interruption,
        public readonly array $counts,
        public readonly int $total,
        public readonly array $targets,
        public readonly ?string $warning,
    ) {
    }

    /**
     * @param string      $operation One of self::OPERATIONS.
     * @param string|null $path      The path the task wrote, or null for the set.
     */
    public static function fromReport(string $operation, ?string $path, RunReport $report): self
    {
        $targets = [];
        $warning = null;

        foreach ($report->targets() as $target) {
            $row       = self::row($target);
            $targets[] = $row;

            // The revert warning is the core's sentence inside the outcome. It is
            // lifted out so the page can put it where it cannot be missed.
            if ($operation === self::REVERT && $row['path'] === $path && $row['outcome'] !== null) {
                $sentence = \sprintf(Reverter::REAPPLY_WARNING, $path);

                if (\str_contains($row['outcome'], $sentence)) {
                    $warning = $sentence;
                }
            }
        }

        return new self(
            $operation,
            $path,
            $report->faults(),
            $report->wasInterrupted() ? $report->interruption->describe() : null,
            $report->counts->byStatus,
            $report->counts->targets,
            $targets,
            $warning
        );
    }

    /**
     * @return Row
     */
    private static function row(TargetReport $target): array
    {
        $objects = [];

        foreach ($target->objectOutcomes() as $outcome) {
            $objects[] = $outcome->describe();
        }

        $plan = [];

        foreach ($target->plan() as $operation) {
            $plan[] = $operation->describe();
        }

        return [
            'path'     => $target->targetPath(),
            'status'   => $target->status(),
            'state'    => $target->describe(),
            'reason'   => $target->reason(),
            'required' => $target->requiredFields(),
            'backup'   => $target->backup->describe(),
            'objects'  => $objects,
            'plan'     => $plan,
            'outcome'  => $target->outcome?->describe(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'operation'    => $this->operation,
            'path'         => $this->path,
            'faults'       => $this->faults,
            'interruption' => $this->interruption,
            'counts'       => $this->counts,
            'total'        => $this->total,
            'targets'      => $this->targets,
            'warning'      => $this->warning,
        ];
    }

    /**
     * What `toArray()` wrote, or null for anything else.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (
            !\is_array($data)
            || !\in_array($data['operation'] ?? null, self::OPERATIONS, true)
            || !self::isNullableString($data, 'path')
            || !self::isStringList($data['faults'] ?? null)
            || !self::isNullableString($data, 'interruption')
            || !\is_array($data['counts'] ?? null)
            || !\is_int($data['total'] ?? null)
            || !\is_array($data['targets'] ?? null)
            || !\array_is_list($data['targets'])
            || !self::isNullableString($data, 'warning')
        ) {
            return null;
        }

        foreach ($data['counts'] as $status => $count) {
            if (!\is_string($status) || !\is_int($count)) {
                return null;
            }
        }

        foreach ($data['targets'] as $row) {
            if (!self::isRow($row)) {
                return null;
            }
        }

        return new self(
            $data['operation'],
            $data['path'],
            $data['faults'],
            $data['interruption'],
            $data['counts'],
            $data['total'],
            $data['targets'],
            $data['warning']
        );
    }

    private static function isRow(mixed $row): bool
    {
        if (!\is_array($row) || !self::isNullableString($row, 'outcome') || !\is_array($row['required'] ?? null)) {
            return false;
        }

        foreach (['path', 'status', 'state', 'reason', 'backup'] as $key) {
            if (!\is_string($row[$key] ?? null)) {
                return false;
            }
        }

        foreach ($row['required'] as $name => $value) {
            if (!\is_string($name) || !\is_string($value)) {
                return false;
            }
        }

        return self::isStringList($row['objects'] ?? null) && self::isStringList($row['plan'] ?? null);
    }

    /**
     * @param array<mixed> $data
     */
    private static function isNullableString(array $data, string $key): bool
    {
        return \array_key_exists($key, $data) && ($data[$key] === null || \is_string($data[$key]));
    }

    private static function isStringList(mixed $value): bool
    {
        if (!\is_array($value) || !\array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!\is_string($item)) {
                return false;
            }
        }

        return true;
    }
}
