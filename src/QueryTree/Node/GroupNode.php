<?php declare(strict_types=1);

namespace STDW\Pdo\QueryTree\Node;

use STDW\Contract\Pdo\QueryTree\Node\ConditionNodeInterface;
use STDW\Contract\Pdo\QueryTree\Node\GroupNodeInterface;
use STDW\Contract\Pdo\QueryTree\Node\LogicNodeInterface;
use STDW\Pdo\Exception\QueryTreeException;


/**
 * A set of nodes grouped together (parentheses). The root of the condition
 * expression: a single ConditionNode is a leaf; a GroupNode composes leaves,
 * logic tokens and nested groups. There is no separate root node.
 */
final readonly class GroupNode implements GroupNodeInterface
{
    /** @var array<int, ConditionNodeInterface|LogicNodeInterface|GroupNodeInterface> */
    private array $children;


    /**
     * @param array<int, mixed> $nodes
     */
    public function __construct(array $nodes = [])
    {
        $children = [];

        foreach ($nodes as $node) {
            if (
                   $node instanceof ConditionNodeInterface
                || $node instanceof LogicNodeInterface
                || $node instanceof GroupNodeInterface
            ) {
                $children[] = $node;

                continue;
            }

            throw QueryTreeException::invalid('group children must be condition, logic or group nodes');
        }

        $this->children = $children;
    }


    public function nodes(): array
    {
        return $this->children;
    }
}
