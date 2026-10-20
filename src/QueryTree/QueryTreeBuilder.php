<?php declare(strict_types=1);

namespace STDW\Pdo\QueryTree;

use STDW\Contract\Pdo\QueryTree\Node\AggregateNodeInterface;
use STDW\Contract\Pdo\QueryTree\Node\GroupNodeInterface;
use STDW\Contract\Pdo\QueryTree\Node\OrderNodeInterface;
use STDW\Contract\Pdo\QueryTree\Node\PaginationNodeInterface;
use STDW\Contract\Pdo\QueryTree\QueryInterface;
use STDW\Contract\Pdo\QueryTree\QueryTreeBuilderInterface;
use STDW\Contract\Pdo\QueryTree\Type\AggregateFunction;
use STDW\Pdo\Exception\QueryTreeException;
use STDW\Pdo\QueryTree\Builder\FilterBuilder;
use STDW\Pdo\QueryTree\Builder\OrderBuilder;
use STDW\Pdo\QueryTree\Builder\PaginationBuilder;
use STDW\Pdo\QueryTree\Node\AggregateNode;


/**
 * Contract facade over the internal builders: lowers the declarative array DSL
 * into the neutral query tree. The driver never sees the arrays, only the
 * nodes. Anything invalid fails fast with QueryTreeExceptionInterface.
 */
final class QueryTreeBuilder implements QueryTreeBuilderInterface
{
    /**
     * @param array<array-key, mixed> $filter
     */
    public static function filter(array $filter): GroupNodeInterface
    {
        return FilterBuilder::make($filter);
    }

    /**
     * @param array<array-key, mixed> $filter
     */
    public static function having(array $filter): GroupNodeInterface
    {
        return FilterBuilder::make($filter);
    }

    /**
     * @param array<array-key, mixed> $order
     * @return array<int, OrderNodeInterface>
     */
    public static function order(array $order): array
    {
        return OrderBuilder::make($order);
    }

    /**
     * @param array<array-key, mixed> $pagination
     */
    public static function pagination(array $pagination): PaginationNodeInterface
    {
        return PaginationBuilder::make($pagination);
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    public static function columns(array $columns): array
    {
        return self::identifiers('column', $columns);
    }

    /**
     * @param array<int, string> $groupBy
     * @return array<int, string>
     */
    public static function groupBy(array $groupBy): array
    {
        return self::identifiers('groupBy', $groupBy);
    }

    /**
     * @param array<array-key, mixed> $aggregates
     * @return array<int, AggregateNodeInterface>
     */
    public static function aggregate(array $aggregates): array
    {
        $nodes = [];

        foreach ($aggregates as $entry) {
            if ( ! is_array($entry)) {
                throw QueryTreeException::invalid('every aggregate entry must be an array');
            }

            $alias = $entry['alias'] ?? null;
            $function = $entry['function'] ?? null;
            $column = $entry['column'] ?? null;

            if ( ! is_string($alias) || trim($alias) === '') {
                throw QueryTreeException::invalid('every aggregate requires a non-empty "alias"');
            }

            if ( ! is_string($function) || trim($function) === '') {
                throw QueryTreeException::invalid(sprintf('aggregate "%s" requires a "function"', $alias));
            }

            $operation = AggregateFunction::tryFrom(strtoupper(trim($function)));

            if ($operation === null) {
                throw QueryTreeException::invalid(sprintf('unknown aggregate function "%s"', $function));
            }

            if ( ! is_string($column) || trim($column) === '') {
                throw QueryTreeException::invalid(sprintf('aggregate "%s" requires a "column"', $alias));
            }

            $nodes[] = new AggregateNode($alias, $operation, $column);
        }

        return $nodes;
    }

    /**
     * @param array<array-key, mixed> $filter
     * @param array<array-key, mixed> $order
     * @param array<array-key, mixed> $pagination
     * @param array<int, string> $groupBy
     * @param array<array-key, mixed> $aggregates
     * @param array<array-key, mixed> $having
     * @param array<int, string> $columns
     */
    public static function query(
        array $filter = [],
        array $order = [],
        array $pagination = [],
        array $groupBy = [],
        array $aggregates = [],
        array $having = [],
        bool $distinct = false,
        array $columns = [],
    ): QueryInterface {
        return new Query(
            distinct: $distinct,
            columns: self::columns($columns),
            where: self::group($filter),
            order: $order === [] ? [] : self::order($order),
            pagination: $pagination === [] ? null : self::pagination($pagination),
            groupBy: self::groupBy($groupBy),
            having: self::group($having),
            aggregates: self::aggregate($aggregates),
        );
    }


    /**
     * Lowers a filter to a group, collapsing an empty result to null so an
     * absent filter never becomes an empty WHERE.
     *
     * @param array<array-key, mixed> $filter
     */
    private static function group(array $filter): ?GroupNodeInterface
    {
        if ($filter === []) {
            return null;
        }

        $node = FilterBuilder::make($filter);

        return $node->nodes() === [] ? null : $node;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<int, string>
     */
    private static function identifiers(string $label, array $values): array
    {
        $identifiers = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw QueryTreeException::invalid(sprintf('%s identifiers must be non-empty strings', $label));
            }

            $identifiers[] = $value;
        }

        return $identifiers;
    }
}
