<?php declare(strict_types=1);

namespace STDW\Pdo\QueryTree\Node;

use STDW\Contract\Pdo\QueryTree\Node\AggregateNodeInterface;
use STDW\Contract\Pdo\QueryTree\Type\AggregateFunction;
use STDW\Pdo\Exception\QueryTreeException;


/**
 * A single aggregation projection: one function over one input column,
 * exposed under an output alias. "*" is the count(*) input column.
 */
final readonly class AggregateNode implements AggregateNodeInterface
{
    public function __construct(
        private string $alias,
        private AggregateFunction $operation,
        private string $column,
    ) {
        if (trim($alias) === '') {
            throw QueryTreeException::invalid('aggregate aliases cannot be empty');
        }

        if (trim($column) === '') {
            throw QueryTreeException::invalid('aggregate columns cannot be empty');
        }
    }


    public function alias(): string
    {
        return $this->alias;
    }

    public function operation(): AggregateFunction
    {
        return $this->operation;
    }

    public function column(): string
    {
        return $this->column;
    }
}
