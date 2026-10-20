<?php declare(strict_types=1);

namespace STDW\Pdo\Dialect;

use STDW\Contract\Pdo\QueryTree\Node\OrderNodeInterface;
use STDW\Contract\Pdo\QueryTree\Type\ConditionOperator;
use STDW\Contract\Pdo\QueryTree\Type\NullOrdering;


/**
 * PostgreSQL dialect.
 *
 * - quotes identifiers with double quotes, doubling the delimiter;
 * - ILIKE / NOT ILIKE are native;
 * - OFFSET without LIMIT is valid;
 * - NULLS FIRST/LAST is native;
 * - inserts can hand columns back through RETURNING (no sequence name needed
 *   for the generated key).
 */
final class PostgresDialect extends DialectAbstracted
{
    public function operator(ConditionOperator $operator): string
    {
        return match ($operator) {
            ConditionOperator::Ilike => 'ILIKE',
            ConditionOperator::NotIlike => 'NOT ILIKE',

            default => parent::operator($operator),
        };
    }

    public function nulls(OrderNodeInterface $order): string
    {
        return match ($order->nulls()) {
            NullOrdering::First => ' NULLS FIRST',
            NullOrdering::Last => ' NULLS LAST',
            null => '',
        };
    }

    public function supportsIlike(): bool
    {
        return true;
    }

    public function supportsReturning(): bool
    {
        return true;
    }


    protected function delimiters(): array
    {
        return ['"', '"'];
    }
}
