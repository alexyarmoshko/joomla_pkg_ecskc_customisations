<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Support;

/**
 * A query builder that records what a reader built, so a test can read the SQL.
 *
 * It keeps the Joomla builder's shape for the methods the registry readers call:
 * `bindArray` names its placeholders `:preparedArrayN`, and `whereIn` binds its
 * values through it, as `DatabaseQuery` does.
 */
final class RecordingQuery
{
    /** @var list<array{string, mixed}> */
    public array $calls = [];

    /** @var array<string, array{mixed, string}> Value and type per placeholder. */
    public array $bound = [];

    private int $index = 0;

    public function select(mixed $columns): self
    {
        $this->calls[] = ['select', $columns];

        return $this;
    }

    public function from(string $table): self
    {
        $this->calls[] = ['from', $table];

        return $this;
    }

    public function join(string $type, string $table, string $condition): self
    {
        $this->calls[] = ['join', [$type, $table, $condition]];

        return $this;
    }

    public function where(mixed $conditions): self
    {
        $this->calls[] = ['where', $conditions];

        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $key, array $values, string $type): self
    {
        return $this->where($key . ' IN (' . \implode(',', $this->bindArray($values, $type)) . ')');
    }

    public function order(mixed $columns): self
    {
        $this->calls[] = ['order', $columns];

        return $this;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    public function bindArray(array $values, string $type): array
    {
        $names = [];

        foreach ($values as $value) {
            $name               = ':preparedArray' . (++$this->index);
            $this->bound[$name] = [$value, $type];
            $names[]            = $name;
        }

        return $names;
    }
}
