<?php declare(strict_types=1);

namespace STDW\Pdo\Exception;

use STDW\Contract\Pdo\Exception\DriverExceptionInterface;
use STDW\Contract\Pdo\QueryTree\Type\ConditionOperator;
use Throwable;


/**
 * Driver-level failures: connection/SQL errors at execution time and dialect
 * gaps. A dialect gap throws — never silently yields an empty result.
 */
final class DriverException extends DatabaseException implements DriverExceptionInterface
{
    public static function executionFailed(string $sql, Throwable $previous): self
    {
        return new self(
            sprintf('Query execution failed (%s): %s', substr($sql, 0, 200), $previous->getMessage()),
            (int) $previous->getCode(),
            $previous,
        );
    }

    public static function unsupportedOperator(ConditionOperator $operator): self
    {
        return new self(sprintf('The dialect cannot render operator "%s"', $operator->value));
    }

    public static function parameterRequired(string $column): self
    {
        return new self(sprintf('A typed Param value is required for column "%s"', $column));
    }

    public static function malformedCondition(string $column, string $reason): self
    {
        return new self(sprintf('Malformed condition on column "%s": %s', $column, $reason));
    }

    public static function cursorRequiresOrder(): self
    {
        return new self('Cursor/keyset pagination requires an explicit ORDER BY column for this dialect');
    }
}
