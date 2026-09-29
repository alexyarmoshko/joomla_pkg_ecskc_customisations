<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Support;

use Joomla\Database\DatabaseInterface;

/**
 * A driver that answers each query in turn from a script, and quotes names as MySQL
 * does so a test can read the query.
 *
 * Each answer is a list of rows, or a Throwable to throw from `loadObjectList()`.
 */
final class RecordingDriver implements DatabaseInterface
{
    /** @var list<RecordingQuery> */
    public array $queries = [];

    /**
     * @param list<list<object>|\Throwable|mixed> $answers
     */
    public function __construct(private array $answers = [])
    {
    }

    public function createQuery(): RecordingQuery
    {
        return new RecordingQuery();
    }

    /**
     * @param string|list<string> $name
     *
     * @return string|list<string>
     */
    public function quoteName(string|array $name, ?string $as = null): string|array
    {
        if (\is_array($name)) {
            return \array_map(fn (string $one): string => $this->quoteName($one), $name);
        }

        $quoted = \implode('.', \array_map(static fn (string $part): string => '`' . $part . '`', \explode('.', $name)));

        return $as === null ? $quoted : $quoted . ' AS `' . $as . '`';
    }

    public function setQuery(RecordingQuery $query): self
    {
        $this->queries[] = $query;

        return $this;
    }

    public function loadObjectList(): mixed
    {
        if ($this->answers === []) {
            throw new \LogicException('The driver was asked one query more than the test expected');
        }

        $answer = \array_shift($this->answers);

        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }
}
