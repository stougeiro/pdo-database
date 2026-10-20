<?php declare(strict_types=1);

namespace STDW\Pdo\Dialect;

use STDW\Contract\Pdo\QueryTree\Node\OrderNodeInterface;
use STDW\Contract\Pdo\QueryTree\Type\NullOrdering;


/**
 * MariaDB dialect.
 *
 * MariaDB speaks the MySQL wire protocol, so it renders like MySQL:
 *
 * - quotes identifiers with backticks, doubling the delimiter;
 * - ILIKE is emulated (no native ILIKE);
 * - LIMIT: an offset without a limit needs a syntactically present limit
 *   (the engine's documented max row count);
 * - NULL positioning is emulated with "col IS NULL" as the leading key.
 *
 * The one divergence is the generated key: MariaDB (>= 10.5) supports a
 * native RETURNING clause on INSERT, so it can hand the inserted record back
 * directly. On an older server declare supportsReturning: false on the config,
 * which drops the connection to the last-insert-id fallback.
 */
final class MariaDbDialect extends DialectAbstracted
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

    public function supportsReturning(): bool
    {
        return true;
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
