<?php declare(strict_types=1);

namespace STDW\Pdo\Driver;

use STDW\Contract\Pdo\Param\ParamInterface;
use STDW\Contract\Pdo\QueryTree\Node\ConditionNodeInterface;
use STDW\Contract\Pdo\QueryTree\Node\GroupNodeInterface;
use STDW\Contract\Pdo\QueryTree\Node\LogicNodeInterface;
use STDW\Contract\Pdo\QueryTree\Type\ConditionOperator;
use STDW\Pdo\Dialect\Spec\DialectSpecInterface;
use STDW\Pdo\Exception\DriverException;


/**
 * Compiles a neutral GroupNodeInterface into a SQL fragment plus an ordered
 * list of typed params.
 *
 * Evaluation follows the contract: nodes are joined left-to-right with the
 * pending connector (default AND); a logic token applies to the operand that
 * follows it; NOT negates only the next operand; a nested group is wrapped in
 * parentheses. An empty IN list never matches ("0 = 1"); an empty NOT IN list
 * matches everything ("1 = 1").
 *
 * Identifiers are quoted through the dialect, so the compiler stays
 * dialect-neutral. ILIKE is native or emulated via LOWER() depending on the
 * dialect, but a value is always bound — never interpolated.
 */
final class Conditions
{
    /**
     * @return array{sql: string, params: array<int, ParamInterface>}
     */
    public static function compile(?GroupNodeInterface $filter, DialectSpecInterface $dialect): array
    {
        $params = [];
        $sql = $filter === null ? '' : self::group($filter, $dialect, $params);

        return ['sql' => trim($sql), 'params' => $params];
    }


    /**
     * @param array<int, ParamInterface> $params
     */
    private static function group(GroupNodeInterface $group, DialectSpecInterface $dialect, array &$params): string
    {
        $accumulated = null;
        $pending = 'AND';
        $negate = false;

        foreach ($group->nodes() as $node) {
            if ($node instanceof LogicNodeInterface) {
                $operator = strtoupper($node->operator()->value);

                if ($operator === 'NOT') {
                    $negate = true;
                } else {
                    $pending = $operator;
                }

                continue;
            }

            $fragment = $node instanceof ConditionNodeInterface
                ? self::condition($node, $dialect, $params)
                : self::group($node, $dialect, $params);

            if ($fragment === '') {
                continue;
            }

            if ($negate) {
                $fragment = 'NOT (' . $fragment . ')';
                $negate = false;
            }

            $accumulated = $accumulated === null
                ? $fragment
                : '(' . $accumulated . ') ' . $pending . ' (' . $fragment . ')';

            $pending = 'AND';
        }

        return $accumulated ?? '';
    }

    /**
     * @param array<int, ParamInterface> $params
     */
    private static function condition(ConditionNodeInterface $node, DialectSpecInterface $dialect, array &$params): string
    {
        $column = $dialect->quote($node->column());
        $operator = $node->operator();
        $value = $node->value();

        return match ($operator) {
            ConditionOperator::IsNull => $column . ' IS NULL',
            ConditionOperator::IsNotNull => $column . ' IS NOT NULL',
            ConditionOperator::In => self::in($column, $value, $params, false),
            ConditionOperator::NotIn => self::in($column, $value, $params, true),
            ConditionOperator::Between => self::between($column, $value, $params, false),
            ConditionOperator::NotBetween => self::between($column, $value, $params, true),
            ConditionOperator::Ilike => self::insensitive($column, $operator, $value, $dialect, $params),
            ConditionOperator::NotIlike => self::insensitive($column, $operator, $value, $dialect, $params),

            default => self::comparison($column, $operator, $value, $dialect, $params),
        };
    }

    /**
     * @param array<int, ParamInterface> $params
     */
    private static function comparison(
        string $column,
        ConditionOperator $operator,
        mixed $value,
        DialectSpecInterface $dialect,
        array &$params,
    ): string {
        $params[] = self::param($column, $value);

        return $column . ' ' . $dialect->operator($operator) . ' ?';
    }

    /**
     * @param array<int, ParamInterface> $params
     */
    private static function insensitive(
        string $column,
        ConditionOperator $operator,
        mixed $value,
        DialectSpecInterface $dialect,
        array &$params,
    ): string {
        $params[] = self::param($column, $value);

        if ($dialect->supportsIlike()) {
            return $column . ' ' . $dialect->operator($operator) . ' ?';
        }

        $symbol = $operator === ConditionOperator::Ilike ? 'LIKE' : 'NOT LIKE';

        return 'LOWER(' . $column . ') ' . $symbol . ' LOWER(?)';
    }

    /**
     * @param null|ParamInterface|array<int, ParamInterface> $value
     * @param array<int, ParamInterface> $params
     */
    private static function in(string $column, null|ParamInterface|array $value, array &$params, bool $negated): string
    {
        if ( ! is_array($value) || ! array_is_list($value)) {
            throw DriverException::dialectGap(sprintf('operator IN on column "%s" requires a list of typed values', $column));
        }

        if ($value === []) {
            return $negated ? '1 = 1' : '0 = 1';
        }

        $placeholders = [];

        foreach ($value as $item) {
            $params[] = self::param($column, $item);
            $placeholders[] = '?';
        }

        return $column . ($negated ? ' NOT IN (' : ' IN (') . implode(', ', $placeholders) . ')';
    }

    /**
     * @param null|ParamInterface|array<int, ParamInterface> $value
     * @param array<int, ParamInterface> $params
     */
    private static function between(string $column, null|ParamInterface|array $value, array &$params, bool $negated): string
    {
        if ( ! is_array($value) || ! array_is_list($value) || count($value) !== 2) {
            throw DriverException::dialectGap(sprintf('operator BETWEEN on column "%s" requires exactly two typed values', $column));
        }

        $params[] = self::param($column, $value[0]);
        $params[] = self::param($column, $value[1]);

        return $column . ($negated ? ' NOT BETWEEN ? AND ?' : ' BETWEEN ? AND ?');
    }

    private static function param(string $column, mixed $value): ParamInterface
    {
        if ( ! $value instanceof ParamInterface) {
            throw DriverException::parameterRequired($column);
        }

        return $value;
    }
}
