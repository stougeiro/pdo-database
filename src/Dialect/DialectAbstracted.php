<?php declare(strict_types=1);

namespace STDW\Pdo\Dialect;

use STDW\Contract\Pdo\Param\ParamInterface;
use STDW\Contract\Pdo\QueryTree\Node\OffsetPaginationNodeInterface;
use STDW\Contract\Pdo\QueryTree\Node\OrderNodeInterface;
use STDW\Contract\Pdo\QueryTree\Node\PaginationNodeInterface;
use STDW\Contract\Pdo\QueryTree\Type\ConditionOperator;
use STDW\Contract\Pdo\QueryTree\Type\OrderDirection;
use STDW\Pdo\Dialect\Spec\DialectSpecInterface;
use STDW\Pdo\Exception\DriverException;
use STDW\Pdo\Param\Param;


/**
 * The shared dialect base: everything SQL rendering that does not diverge
 * between engines, plus the internal spec hooks the compiler needs for the
 * parts a plain suffix cannot express (ILIKE emulation, NULL ordering
 * emulation, generated keys, keyset cursors).
 *
 * It implements DialectSpecInterface, which extends the vendor
 * DialectInterface (quote/operator/limitOffset/nulls) with the internal hooks
 * defined in Dialect\Spec. Subclasses only override what diverges.
 */
abstract class DialectAbstracted implements DialectSpecInterface
{
    /**
     * The open/close quote delimiters of this engine.
     *
     * @return array{string, string}
     */
    abstract protected function delimiters(): array;

    public function quote(string $identifier): string
    {
        $identifier = trim($identifier);

        if ($identifier === '*') {
            return '*';
        }

        [$open, $close] = $this->delimiters();

        $quoted = [];

        foreach (explode('.', $identifier) as $segment) {
            if ($segment === '*') {
                $quoted[] = '*';

                continue;
            }

            $quoted[] = $open . str_replace($close, $close . $close, $segment) . $close;
        }

        return implode('.', $quoted);
    }

    public function operator(ConditionOperator $operator): string
    {
        return match ($operator) {
            ConditionOperator::Eq => '=',
            ConditionOperator::Neq => '<>',
            ConditionOperator::Gt => '>',
            ConditionOperator::Gte => '>=',
            ConditionOperator::Lt => '<',
            ConditionOperator::Lte => '<=',
            ConditionOperator::In => 'IN',
            ConditionOperator::NotIn => 'NOT IN',
            ConditionOperator::Like => 'LIKE',
            ConditionOperator::NotLike => 'NOT LIKE',
            ConditionOperator::Between => 'BETWEEN',
            ConditionOperator::NotBetween => 'NOT BETWEEN',
            ConditionOperator::IsNull => 'IS NULL',
            ConditionOperator::IsNotNull => 'IS NOT NULL',

            ConditionOperator::Ilike, ConditionOperator::NotIlike
                => throw DriverException::unsupportedOperator($operator),
        };
    }

    public function limitOffset(?PaginationNodeInterface $pagination): string
    {
        if ($pagination === null) {
            return '';
        }

        $offset = $pagination instanceof OffsetPaginationNodeInterface ? $pagination->offset() : null;

        return $this->renderLimitOffset($pagination->limit(), $offset);
    }

    public function nulls(OrderNodeInterface $order): string
    {
        return '';
    }

    /**
     * Whether a case-insensitive LIKE is native (no LOWER() emulation).
     */
    public function supportsIlike(): bool
    {
        return false;
    }

    /**
     * A full ORDER BY item, so NULL positioning can be emulated when needed.
     */
    public function orderClause(OrderNodeInterface $order): string
    {
        return $this->quote($order->column()) .' '. $order->direction()->value . $this->nulls($order);
    }

    /**
     * Whether a write can hand columns back through RETURNING.
     */
    public function supportsReturning(): bool
    {
        return false;
    }

    /**
     * The keyset WHERE fragment for a cursor, appending its bound param.
     * Returns null when the dialect cannot express it.
     *
     * @param array<int, OrderNodeInterface> $order
     * @param array<int, ParamInterface> $params
     */
    public function cursorClause(mixed $cursor, array $order, array &$params): ?string
    {
        $first = $order[0] ?? null;

        if ($first === null) {
            throw DriverException::cursorRequiresOrder();
        }

        $params[] = Param::from($cursor);

        $operator = $first->direction() === OrderDirection::Asc ? '>' : '<';

        return $this->quote($first->column()) .' '. $operator .' ?';
    }


    /** The LIMIT/OFFSET rendering; overridable for engine quirks. */
    protected function renderLimitOffset(?int $limit, ?int $offset): string
    {
        $sql = '';

        if ($limit !== null) {
            $sql .= ' LIMIT '. $limit;
        }

        if ($offset !== null && $offset > 0) {
            $sql .= ' OFFSET '. $offset;
        }

        return $sql;
    }
}
