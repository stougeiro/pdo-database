<?php declare(strict_types=1);

namespace STDW\Pdo\QueryTree\Builder;

use STDW\Contract\Pdo\QueryTree\Node\PaginationNodeInterface;
use STDW\Pdo\Exception\QueryTreeException;
use STDW\Pdo\QueryTree\Node\CursorPaginationNode;
use STDW\Pdo\QueryTree\Node\OffsetPaginationNode;


/**
 * Lowers the pagination DSL into exactly one strategy:
 * - offset (limit/offset) when no cursor is given; or
 * - cursor (limit + opaque marker) when a cursor is given.
 *
 * A cursor never combines with an offset. limit/offset are non-negative
 * integers; the cursor stays a bound value.
 */
final class PaginationBuilder
{
    /** @var array<int, string> */
    private const KEYS = ['limit', 'offset', 'cursor'];


    /**
     * @param array<array-key, mixed> $pagination
     */
    public static function make(array $pagination): PaginationNodeInterface
    {
        foreach (array_keys($pagination) as $key) {
            if ( ! is_string($key) || ! in_array($key, self::KEYS, true)) {
                throw QueryTreeException::invalid(sprintf('unknown pagination key "%s"', (string) $key));
            }
        }

        $limit = self::nullableInt('limit', $pagination['limit'] ?? null);
        $offset = self::nullableInt('offset', $pagination['offset'] ?? null);
        $cursor = $pagination['cursor'] ?? null;

        if ($cursor !== null) {
            if ($offset !== null) {
                throw QueryTreeException::invalid('a cursor cannot be combined with an offset');
            }

            return new CursorPaginationNode($limit, $cursor);
        }

        if ($limit === null && $offset === null) {
            throw QueryTreeException::invalid('pagination requires limit/offset or a cursor');
        }

        return new OffsetPaginationNode($limit, $offset);
    }


    private static function nullableInt(string $key, mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if ( ! is_int($value) || $value < 0) {
            throw QueryTreeException::invalid(sprintf('pagination key "%s" must be an integer >= 0', $key));
        }

        return $value;
    }
}
