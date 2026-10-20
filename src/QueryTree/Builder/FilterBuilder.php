<?php declare(strict_types=1);

namespace STDW\Pdo\QueryTree\Builder;

use STDW\Contract\Pdo\Param\ParamInterface;
use STDW\Contract\Pdo\QueryTree\Type\ConditionOperator;
use STDW\Contract\Pdo\QueryTree\Type\LogicOperator;
use STDW\Pdo\Exception\QueryTreeException;
use STDW\Pdo\Param\Param;
use STDW\Pdo\QueryTree\Node\ConditionNode;
use STDW\Pdo\QueryTree\Node\GroupNode;
use STDW\Pdo\QueryTree\Node\LogicNode;
use ValueError;


/**
 * Lowers the declarative filter array into a GroupNode.
 *
 * Accepted shapes:
 * - a Predicate: map of "column OP" => value (entries ANDed); OP defaults to
 *   EQ and its token is case-insensitive;
 * - a list of Clauses: Predicate nodes, the tokens "AND"/"OR"/"NOT" and
 *   nested lists as groups (parentheses).
 *
 * Raw values are auto-wrapped into typed Params (Param::from); a value that
 * cannot be typed is rejected. Null is only valid for IS_NULL/IS_NOT_NULL.
 * Anything invalid fails fast with QueryTreeExceptionInterface.
 */
final class FilterBuilder
{
    /** @var array<int, string> */
    private const LOGIC = ['AND', 'OR', 'NOT'];


    /**
     * @param array<array-key, mixed> $filter
     */
    public static function make(array $filter): GroupNode
    {
        if ($filter === []) {
            return new GroupNode([]);
        }

        return array_is_list($filter)
            ? self::fromClauses($filter)
            : self::fromPredicate($filter);
    }


    /**
     * @param array<array-key, mixed> $predicate
     */
    private static function fromPredicate(array $predicate): GroupNode
    {
        $nodes = [];

        foreach ($predicate as $key => $value) {
            if (! is_string($key)) {
                throw QueryTreeException::invalid('predicate keys must be strings');
            }

            $nodes[] = self::condition($key, $value);
        }

        return new GroupNode($nodes);
    }

    /**
     * @param array<array-key, mixed> $clauses
     */
    private static function fromClauses(array $clauses): GroupNode
    {
        self::assertSequence($clauses);

        $nodes = [];

        foreach ($clauses as $clause) {
            if (is_string($clause)) {
                $nodes[] = new LogicNode(self::logic($clause));

                continue;
            }

            if (! is_array($clause)) {
                throw QueryTreeException::invalid('a clause must be a predicate, a logic token or a nested group');
            }

            $nodes[] = self::make($clause);
        }

        return new GroupNode($nodes);
    }

    private static function condition(string $key, mixed $value): ConditionNode
    {
        [$column, $operator] = self::parseKey($key);

        $normalized = match ($operator) {
            ConditionOperator::IsNull, ConditionOperator::IsNotNull => self::nullValue($column, $value),
            ConditionOperator::In, ConditionOperator::NotIn => self::listValue($column, $value),
            ConditionOperator::Between, ConditionOperator::NotBetween => self::pairValue($column, $value),

            default => self::singleValue($column, $value),
        };

        return new ConditionNode($column, $operator, $normalized);
    }

    /**
     * @return array<int, ParamInterface>
     */
    private static function nullValue(string $column, mixed $value): array
    {
        if ($value !== null) {
            throw QueryTreeException::invalidCondition($column, 'a value is not allowed for IS_NULL / IS_NOT_NULL');
        }

        return [];
    }

    /**
     * @return array<int, ParamInterface>
     */
    private static function listValue(string $column, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw QueryTreeException::invalidCondition($column, 'operator requires a list of values');
        }

        return array_map(static fn (mixed $item): ParamInterface => self::toParam($column, $item), $value);
    }

    /**
     * @return array<int, ParamInterface>
     */
    private static function pairValue(string $column, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== 2) {
            throw QueryTreeException::invalidCondition($column, 'operator requires exactly two values');
        }

        return array_map(static fn (mixed $item): ParamInterface => self::toParam($column, $item), $value);
    }

    private static function singleValue(string $column, mixed $value): ParamInterface
    {
        if ($value === null) {
            throw QueryTreeException::invalidCondition($column, 'null is only allowed for IS_NULL / IS_NOT_NULL');
        }

        if (is_array($value)) {
            throw QueryTreeException::invalidCondition($column, 'a scalar value is required for this operator');
        }

        return self::toParam($column, $value);
    }

    private static function toParam(string $column, mixed $value): ParamInterface
    {
        if ($value instanceof ParamInterface) {
            return $value;
        }

        if ($value === null || is_array($value)) {
            throw QueryTreeException::invalidCondition($column, 'every entry must be a scalar or a typed Param');
        }

        return Param::from($value);
    }

    /**
     * Splits a "column OP" key into its column and the neutral operator.
     *
     * @return array{string, ConditionOperator}
     */
    private static function parseKey(string $key): array
    {
        $parts = explode(' ', trim($key), 2);

        if (count($parts) === 1) {
            return [$parts[0], ConditionOperator::Eq];
        }

        $token = strtolower(trim($parts[1]));

        try {
            return [$parts[0], ConditionOperator::from($token)];
        } catch (ValueError) {
            throw QueryTreeException::invalidOperator(strtoupper($token));
        }
    }

    private static function logic(string $token): LogicOperator
    {
        $normalized = strtoupper(trim($token));

        if (! in_array($normalized, self::LOGIC, true)) {
            throw QueryTreeException::invalidLogic($token);
        }

        return LogicOperator::from($normalized);
    }

    /**
     * Rejects malformed logic: a connector cannot lead a group or follow
     * another logic token, and a group cannot end with a logic token.
     *
     * @param array<array-key, mixed> $clauses
     */
    private static function assertSequence(array $clauses): void
    {
        $previous = null;

        foreach ($clauses as $clause) {
            $token = is_string($clause) ? strtoupper(trim($clause)) : 'OPERAND';

            if ($token === 'AND' || $token === 'OR') {
                if ($previous === null) {
                    throw QueryTreeException::invalid('a logical connector cannot lead a group');
                }

                if ($previous === 'AND' || $previous === 'OR' || $previous === 'NOT') {
                    throw QueryTreeException::invalid('a logical connector cannot follow another logic token');
                }
            }

            $previous = $token;
        }

        if ($previous === 'AND' || $previous === 'OR' || $previous === 'NOT') {
            throw QueryTreeException::invalid('a group cannot end with a logic token');
        }
    }
}
