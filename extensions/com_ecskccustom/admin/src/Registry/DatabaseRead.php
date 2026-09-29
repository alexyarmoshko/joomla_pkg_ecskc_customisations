<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Registry;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryReadFailure;

/**
 * Runs the registry readers' queries so that every failed read is a RegistryReadFailure.
 *
 * Every exception the Joomla driver raises extends RuntimeException:
 * ConnectionFailureException, ExecutionFailureException and
 * PrepareStatementFailureException among them. Each becomes a RegistryReadFailure
 * naming the table. A LogicException or an Error is a bug and is left alone, as in
 * JoomlaOperationalCheckProvider.
 */
final class DatabaseRead
{
    private function __construct()
    {
    }

    /**
     * @template T
     *
     * @param string       $table The table named in the failure message.
     * @param \Closure(): T $read
     *
     * @return T
     *
     * @throws RegistryReadFailure
     */
    public static function guarded(string $table, \Closure $read): mixed
    {
        try {
            return $read();
        } catch (RegistryReadFailure $failure) {
            throw $failure;
        } catch (\RuntimeException $e) {
            throw new RegistryReadFailure(
                \sprintf(
                    '%s could not be read: %s',
                    $table,
                    \trim($e->getMessage()) !== '' ? $e->getMessage() : \get_class($e)
                ),
                0,
                $e
            );
        }
    }

    /**
     * The rows the query returns, as `loadObjectList()` gives them.
     *
     * @param \Joomla\Database\DatabaseDriver $db
     * @param \Joomla\Database\DatabaseQuery  $query
     * @param string                          $table The table named in a failure message.
     *
     * @return list<object>
     *
     * @throws RegistryReadFailure
     */
    public static function rows(object $db, object $query, string $table): array
    {
        $rows = $db->setQuery($query)->loadObjectList();

        if (!\is_array($rows)) {
            throw new RegistryReadFailure(\sprintf('%s: the database returned no result set', $table));
        }

        foreach ($rows as $row) {
            if (!\is_object($row)) {
                throw new RegistryReadFailure(\sprintf('%s: the database returned a row that is not an object', $table));
            }
        }

        return \array_values($rows);
    }
}
