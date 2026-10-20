<?php declare(strict_types=1);

namespace STDW\Pdo\QueryTree\Node;

use STDW\Contract\Pdo\QueryTree\Node\OrderNodeInterface;
use STDW\Contract\Pdo\QueryTree\Type\NullOrdering;
use STDW\Contract\Pdo\QueryTree\Type\OrderDirection;
use STDW\Pdo\Exception\QueryTreeException;


/**
 * A single ORDER BY clause: column + neutral direction + optional NULL
 * positioning (rendered by the dialect).
 */
final readonly class OrderNode implements OrderNodeInterface
{
    public function __construct(
        private string $column,
        private OrderDirection $direction = OrderDirection::Asc,
        private ?NullOrdering $nulls = null,
    ) {
        if (trim($column) === '') {
            throw QueryTreeException::invalid('order columns cannot be empty');
        }
    }


    public function column(): string
    {
        return $this->column;
    }

    public function direction(): OrderDirection
    {
        return $this->direction;
    }

    public function nulls(): ?NullOrdering
    {
        return $this->nulls;
    }
}
