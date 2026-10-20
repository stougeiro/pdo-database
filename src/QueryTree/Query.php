<?php declare(strict_types=1);

namespace STDW\Pdo\QueryTree;

use STDW\Contract\Pdo\QueryTree\Node\AggregateNodeInterface;
use STDW\Contract\Pdo\QueryTree\Node\GroupNodeInterface;
use STDW\Contract\Pdo\QueryTree\Node\OrderNodeInterface;
use STDW\Contract\Pdo\QueryTree\Node\PaginationNodeInterface;
use STDW\Contract\Pdo\QueryTree\QueryInterface;


/**
 * The single neutral query IR: projection, filter, order, pagination and the
 * aggregation clauses in one structure. It rides inside a Statement and is
 * rendered to SQL by the driver through its dialect.
 */
final readonly class Query implements QueryInterface
{
    /**
     * @param array<int, string> $columns
     * @param array<int, OrderNodeInterface> $order
     * @param array<int, string> $groupBy
     * @param array<int, AggregateNodeInterface> $aggregates
     */
    public function __construct(
        private bool $distinct = false,
        private array $columns = [],
        private ?GroupNodeInterface $where = null,
        private array $order = [],
        private ?PaginationNodeInterface $pagination = null,
        private array $groupBy = [],
        private ?GroupNodeInterface $having = null,
        private array $aggregates = [],
    ) {
    }


    public function distinct(): bool
    {
        return $this->distinct;
    }

    public function columns(): array
    {
        return $this->columns;
    }

    public function where(): ?GroupNodeInterface
    {
        return $this->where;
    }

    public function order(): array
    {
        return $this->order;
    }

    public function pagination(): ?PaginationNodeInterface
    {
        return $this->pagination;
    }

    public function groupBy(): array
    {
        return $this->groupBy;
    }

    public function having(): ?GroupNodeInterface
    {
        return $this->having;
    }

    public function aggregates(): array
    {
        return $this->aggregates;
    }
}
