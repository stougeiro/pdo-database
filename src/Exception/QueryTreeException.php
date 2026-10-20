<?php declare(strict_types=1);

namespace STDW\Pdo\Exception;

use STDW\Contract\Pdo\Exception\QueryTreeExceptionInterface;


/**
 * Invalid neutral query IR: unknown operator token, malformed value list,
 * conflicting clauses. Fail-fast at the tree edge, before the driver.
 */
final class QueryTreeException extends DatabaseException implements QueryTreeExceptionInterface
{
    public static function invalidOperator(string $token): self
    {
        return new self(sprintf('Unknown condition operator token "%s"', $token));
    }

    public static function invalidLogic(string $token): self
    {
        return new self(sprintf('Unknown logic token "%s" (expected AND, OR or NOT)', $token));
    }

    public static function invalidCondition(string $column, string $reason): self
    {
        return new self(sprintf('Invalid condition on column "%s": %s', $column, $reason));
    }

    public static function invalid(string $reason): self
    {
        return new self(sprintf('Invalid query tree: %s', $reason));
    }
}
