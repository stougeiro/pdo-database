<?php declare(strict_types=1);

namespace STDW\Pdo\Exception;

use STDW\Contract\Pdo\Exception\ConnectionExceptionInterface;
use Throwable;


/**
 * Lifecycle failures: opening/closing the physical link, running a
 * transaction on it and invalid/incomplete connection configuration.
 */
final class ConnectionException extends DatabaseException implements ConnectionExceptionInterface
{
    public static function notConfigured(string $name): self
    {
        return new self(sprintf('Connection "%s" is not configured', $name));
    }

    public static function unknownDriver(string $driver): self
    {
        return new self(sprintf('Unknown driver "%s"', $driver));
    }

    public static function missing(string $key): self
    {
        return new self(sprintf('Missing required configuration key "%s"', $key));
    }

    public static function invalid(string $key): self
    {
        return new self(sprintf('Invalid value for configuration key "%s"', $key));
    }

    public static function failed(string $target, Throwable $previous): self
    {
        return new self(
            sprintf('Failed to connect to "%s": %s', $target, $previous->getMessage()),
            (int) $previous->getCode(),
            $previous,
        );
    }

    public static function transactionFailed(string $operation, Throwable $previous): self
    {
        return new self(
            sprintf('Transaction %s failed: %s', $operation, $previous->getMessage()),
            (int) $previous->getCode(),
            $previous,
        );
    }

    public static function queryFailed(string $sql, Throwable $previous): self
    {
        return new self(
            sprintf('Query execution failed (%s): %s', substr($sql, 0, 200), $previous->getMessage()),
            (int) $previous->getCode(),
            $previous,
        );
    }

    public static function parameterRequired(string $bind): self
    {
        return new self(sprintf('A typed Param value is required for bind "%s"', $bind));
    }

    public static function tableNotDeclared(string $class): self
    {
        return new self(sprintf('Class "%s" must declare a non-empty table', $class));
    }
}
