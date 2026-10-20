<?php declare(strict_types=1);

namespace STDW\Pdo\Dialect;

use STDW\Contract\Pdo\QueryTree\Node\OrderNodeInterface;
use STDW\Contract\Pdo\QueryTree\Type\NullOrdering;


/**
 * MySQL dialect.
 *
 * - quotes identifiers with backticks, doubling the delimiter;
 * - ILIKE is emulated (no native ILIKE);
 * - LIMIT: an offset without a limit needs a syntactically present limit
 *   (the engine's documented max row count);
 * - NULL positioning is emulated with "col IS NULL" as the leading key;
 * - the generated key comes from lastInsertId().
 */
final class MysqlDialect extends DialectAbstracted
{
    public function orderClause(OrderNodeInterface $order): string
    {
        $column = $this->quote($order->column());
        $clause = $column .' '. $order->direction()->value;
        $nulls = $order->nulls();

        if ($nulls === null) {
            return $clause;
        }

        $flag = $nulls === NullOrdering::First ? 'ASC' : 'DESC';

        return $column .' IS NULL '. $flag .', '. $clause;
    }


    protected function delimiters(): array
    {
        return ['`', '`'];
    }

    protected function renderLimitOffset(?int $limit, ?int $offset): string
    {
        if ($limit === null && $offset !== null && $offset > 0) {
            return ' LIMIT 18446744073709551615 OFFSET ' . $offset;
        }

        return parent::renderLimitOffset($limit, $offset);
    }
}
