<?php declare(strict_types=1);

namespace STDW\Pdo\Dialect;

use STDW\Contract\Pdo\Param\ParamInterface;
use STDW\Contract\Pdo\QueryTree\Node\OrderNodeInterface;
use STDW\Contract\Pdo\QueryTree\Type\NullOrdering;
use STDW\Pdo\Param\Param;


/**
 * SQLite dialect.
 *
 * - quotes identifiers with double quotes, doubling the delimiter;
 * - ILIKE is emulated (no native ILIKE);
 * - LIMIT: an offset without a limit still needs "LIMIT -1";
 * - the generated key comes from lastInsertId();
 * - keyset cursors fall back to the internal rowid when no ORDER BY is given;
 * - NULLS FIRST/LAST is native (modern SQLite);
 * - a native RETURNING clause is available on SQLite >= 3.35; on an older
 *   server declare supportsReturning: false on the config, which drops the
 *   connection to the last-insert-id fallback.
 */
final class SqliteDialect extends DialectAbstracted
{
    public function supportsReturning(): bool
    {
        return true;
    }

    public function nulls(OrderNodeInterface $order): string
    {
        return match ($order->nulls()) {
            NullOrdering::First => ' NULLS FIRST',
            NullOrdering::Last => ' NULLS LAST',
            null => '',
        };
    }

    /**
     * @param array<int, OrderNodeInterface> $order
     * @param array<int, ParamInterface> $params
     */
    public function cursorClause(mixed $cursor, array $order, array &$params): ?string
    {
        if ($order === []) {
            $params[] = Param::from($cursor);

            return 'rowid > ?';
        }

        return parent::cursorClause($cursor, $order, $params);
    }


    protected function delimiters(): array
    {
        return ['"', '"'];
    }

    protected function renderLimitOffset(?int $limit, ?int $offset): string
    {
        if ($limit === null && $offset !== null && $offset > 0) {
            return ' LIMIT -1 OFFSET ' . $offset;
        }

        return parent::renderLimitOffset($limit, $offset);
    }
}
