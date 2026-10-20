<?php declare(strict_types=1);

namespace STDW\Pdo\QueryTree\Builder;

use STDW\Contract\Pdo\QueryTree\Type\OrderDirection;
use STDW\Pdo\Exception\QueryTreeException;
use STDW\Pdo\QueryTree\Node\OrderNode;


/**
 * Lowers the order DSL into ORDER BY clauses.
 *
 * Accepted shapes:
 * - a map of column => direction ("asc"/"desc"); or
 * - a list of strings: "column", "column asc" or "column desc".
 */
final class OrderBuilder
{
    /**
     * @param array<array-key, mixed> $order
     * @return array<int, OrderNode>
     */
    public static function make(array $order): array
    {
        if ($order === []) {
            return [];
        }

        return array_is_list($order)
            ? self::fromList($order)
            : self::fromMap($order);
    }


    /**
     * @param array<array-key, mixed> $order
     * @return array<int, OrderNode>
     */
    private static function fromMap(array $order): array
    {
        $nodes = [];

        foreach ($order as $column => $direction) {
            if (! is_string($column) || trim($column) === '') {
                throw QueryTreeException::invalid('order columns must be non-empty strings');
            }

            $nodes[] = new OrderNode($column, self::direction($column, $direction));
        }

        return $nodes;
    }

    /**
     * @param array<array-key, mixed> $order
     * @return array<int, OrderNode>
     */
    private static function fromList(array $order): array
    {
        $nodes = [];

        foreach ($order as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                throw QueryTreeException::invalid('order entries must be non-empty strings');
            }

            $parts = preg_split('/\s+/', trim($entry), 2) ?: [];
            $column = $parts[0];

            $nodes[] = new OrderNode($column, self::direction($column, $parts[1] ?? 'asc'));
        }

        return $nodes;
    }

    private static function direction(string $column, mixed $direction): OrderDirection
    {
        if ($direction instanceof OrderDirection) {
            return $direction;
        }

        if (is_string($direction)) {
            $resolved = OrderDirection::tryFrom(strtoupper(trim($direction)));

            if ($resolved !== null) {
                return $resolved;
            }
        }

        throw QueryTreeException::invalid(sprintf('order direction of column "%s" must be "asc" or "desc"', $column));
    }
}
