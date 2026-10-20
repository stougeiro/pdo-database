<?php declare(strict_types=1);

namespace STDW\Pdo\QueryTree\Node;

use STDW\Contract\Pdo\Param\ParamInterface;
use STDW\Contract\Pdo\QueryTree\Node\ConditionNodeInterface;
use STDW\Contract\Pdo\QueryTree\Type\ConditionOperator;
use STDW\Pdo\Exception\QueryTreeException;


/**
 * A column comparison: column + neutral operator + typed value(s).
 *
 * The value arity is validated at construction: scalar operators require one
 * Param; list operators (in, not_in) a non-empty list; interval operators
 * (between, not_between) exactly two; null operators (is_null, is_not_null)
 * carry no value (value() returns null).
 */
final readonly class ConditionNode implements ConditionNodeInterface
{
    /** @var null|array<int, ParamInterface> */
    private ?array $list;


    /**
     * @param ParamInterface|array<int, ParamInterface> $value
     */
    public function __construct(
        private string $column,
        private ConditionOperator $operator,
        ParamInterface|array $value,
    ) {
        if (trim($column) === '') {
            throw QueryTreeException::invalidCondition($column, 'column cannot be empty');
        }

        $this->list = match ($operator) {
            ConditionOperator::IsNull, ConditionOperator::IsNotNull => null,
            ConditionOperator::In, ConditionOperator::NotIn => self::assertList($column, $value, 1, null),
            ConditionOperator::Between, ConditionOperator::NotBetween => self::assertList($column, $value, 2, 2),

            default => self::assertSingle($column, $value),
        };
    }


    public function column(): string
    {
        return $this->column;
    }

    public function operator(): ConditionOperator
    {
        return $this->operator;
    }

    public function value(): null|ParamInterface|array
    {
        if ($this->list === null) {
            return null;
        }

        if (count($this->list) === 1 && ! self::isListOperator($this->operator)) {
            return $this->list[0];
        }

        return $this->list;
    }


    private static function isListOperator(ConditionOperator $operator): bool
    {
        return match ($operator) {
            ConditionOperator::In, ConditionOperator::NotIn,
            ConditionOperator::Between, ConditionOperator::NotBetween => true,

            default => false,
        };
    }

    /**
     * @param ParamInterface|array<array-key, mixed> $value
     * @return array<int, ParamInterface>
     */
    private static function assertList(string $column, ParamInterface|array $value, int $min, ?int $max): array
    {
        if ( ! is_array($value) || ! array_is_list($value)) {
            throw QueryTreeException::invalidCondition($column, 'operator requires a list of typed values');
        }

        $count = count($value);

        if ($count < $min || ($max !== null && $count > $max)) {
            $expected = $max === null ? 'at least ' . $min : (string) $max;

            throw QueryTreeException::invalidCondition($column, sprintf('operator requires %s typed value(s)', $expected));
        }

        foreach ($value as $item) {
            if ( ! $item instanceof ParamInterface) {
                throw QueryTreeException::invalidCondition($column, 'every list entry must be a typed Param');
            }
        }

        /** @var array<int, ParamInterface> $value */
        return $value;
    }

    /**
     * @param ParamInterface|array<int, ParamInterface> $value
     * @return array<int, ParamInterface>
     */
    private static function assertSingle(string $column, ParamInterface|array $value): array
    {
        if ( ! $value instanceof ParamInterface) {
            throw QueryTreeException::invalidCondition($column, 'operator requires a single typed Param');
        }

        return [$value];
    }
}
