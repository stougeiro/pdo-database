<?php declare(strict_types=1);

namespace STDW\Pdo\QueryTree\Node;

use STDW\Contract\Pdo\QueryTree\Node\LogicNodeInterface;
use STDW\Contract\Pdo\QueryTree\Type\LogicOperator;


/**
 * Carries a single logical operator (and/or/not). It applies to the operand
 * that follows it; the default join of a group is AND.
 */
final readonly class LogicNode implements LogicNodeInterface
{
    public function __construct(
        private LogicOperator $operator,
    ) {
    }


    public function operator(): LogicOperator
    {
        return $this->operator;
    }
}
