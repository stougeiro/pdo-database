<?php declare(strict_types=1);

namespace STDW\Pdo\Exception;

use STDW\Contract\Pdo\Exception\StatementExceptionInterface;


/**
 * Malformed executable statement: a write without data, an update/delete
 * without a WHERE, an insert carrying a query, returning outside a write.
 * Fail-fast at the statement edge.
 */
final class StatementException extends DatabaseException implements StatementExceptionInterface
{
    public static function invalid(string $reason): self
    {
        return new self(sprintf('Invalid statement: %s', $reason));
    }

    public static function insertWithQuery(): self
    {
        return self::invalid('an insert cannot carry a query (no WHERE)');
    }

    public static function writeWithoutData(string $kind): self
    {
        return self::invalid(sprintf('a %s requires at least one column to write', $kind));
    }

    public static function unfilteredWrite(string $kind): self
    {
        return self::invalid(sprintf('a %s requires a non-empty WHERE (an unfiltered write is not allowed)', $kind));
    }

    public static function returningOutsideInsert(): self
    {
        return self::invalid('returning columns are only meaningful on an insert');
    }
}
