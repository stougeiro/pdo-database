<?php declare(strict_types=1);

use STDW\Contract\Pdo\QueryTree\Type\OrderDirection;
use STDW\Pdo\Exception\QueryTreeException;
use STDW\Pdo\Param\Param;
use STDW\Pdo\QueryTree\Builder\FilterBuilder;
use STDW\Pdo\QueryTree\Builder\OrderBuilder;
use STDW\Pdo\QueryTree\Builder\PaginationBuilder;
use STDW\Pdo\QueryTree\Node\ConditionNode;
use STDW\Pdo\QueryTree\Node\CursorPaginationNode;
use STDW\Pdo\QueryTree\Node\LogicNode;
use STDW\Pdo\QueryTree\Node\OffsetPaginationNode;

it('builds an empty group for an empty filter', function () {
    expect(FilterBuilder::make([])->nodes())->toBe([]);
});

it('rejects a predicate with a non-string key', function () {
    FilterBuilder::make([5 => 'x']);
})->throws(QueryTreeException::class);

it('rejects a clause that is neither string nor array', function () {
    FilterBuilder::make([1]);
})->throws(QueryTreeException::class);

it('rejects a value supplied to IS_NULL', function () {
    FilterBuilder::make(['a is_null' => 1]);
})->throws(QueryTreeException::class);

it('rejects a non-list value for IN', function () {
    FilterBuilder::make(['a in' => 'x']);
})->throws(QueryTreeException::class);

it('rejects an associative value for IN', function () {
    FilterBuilder::make(['a in' => ['x' => 1]]);
})->throws(QueryTreeException::class);

it('rejects a wrong arity for BETWEEN', function () {
    FilterBuilder::make(['a between' => [1, 2, 3]]);
})->throws(QueryTreeException::class);

it('rejects a null value for a scalar comparison', function () {
    FilterBuilder::make(['a' => null]);
})->throws(QueryTreeException::class);

it('rejects an array value for a scalar comparison', function () {
    FilterBuilder::make(['a' => [1, 2]]);
})->throws(QueryTreeException::class);

it('rejects a null or array entry inside a list', function () {
    FilterBuilder::make(['a in' => [null]]);
})->throws(QueryTreeException::class);

it('accepts an already-typed Param inside a list', function () {
    $group = FilterBuilder::make(['a in' => [Param::int(1), Param::int(2)]]);
    $node = $group->nodes()[0];

    expect($node)->toBeInstanceOf(ConditionNode::class)
        ->and($node->value())->toHaveCount(2);
});

it('rejects an unknown operator token', function () {
    FilterBuilder::make(['a nope' => 1]);
})->throws(QueryTreeException::class);

it('rejects an unknown logic token', function () {
    FilterBuilder::make([['a' => 1], 'XOR', ['b' => 2]]);
})->throws(QueryTreeException::class);

it('rejects a connector leading a group', function () {
    FilterBuilder::make(['AND', ['a' => 1]]);
})->throws(QueryTreeException::class);

it('rejects a connector following another token', function () {
    FilterBuilder::make([['a' => 1], 'AND', 'OR', ['b' => 2]]);
})->throws(QueryTreeException::class);

it('rejects a group ending with a logic token', function () {
    FilterBuilder::make([['a' => 1], 'AND']);
})->throws(QueryTreeException::class);

it('builds a logic node and a nested group for a mixed clause list', function () {
    $group = FilterBuilder::make([['a' => 1], 'OR', [['b' => 2], 'AND', ['c' => 3]]]);

    expect($group->nodes())->toHaveCount(3)
        ->and($group->nodes()[1])->toBeInstanceOf(LogicNode::class);
});

it('builds an empty order list', function () {
    expect(OrderBuilder::make([]))->toBe([]);
});

it('rejects an empty order column in a map', function () {
    OrderBuilder::make(['  ' => 'asc']);
})->throws(QueryTreeException::class);

it('rejects an empty order entry in a list', function () {
    OrderBuilder::make(['']);
})->throws(QueryTreeException::class);

it('rejects an invalid direction in a map', function () {
    OrderBuilder::make(['name' => 'sideways']);
})->throws(QueryTreeException::class);

it('rejects a non-string direction', function () {
    OrderBuilder::make(['name' => 1]);
})->throws(QueryTreeException::class);

it('accepts an OrderDirection instance directly', function () {
    $nodes = OrderBuilder::make(['name' => OrderDirection::Desc]);

    expect($nodes[0]->direction())->toBe(OrderDirection::Desc);
});

it('defaults a bare list entry to ascending', function () {
    $nodes = OrderBuilder::make(['name']);

    expect($nodes[0]->column())->toBe('name')
        ->and($nodes[0]->direction())->toBe(OrderDirection::Asc);
});

it('rejects an unknown pagination key', function () {
    PaginationBuilder::make(['per_page' => 10]);
})->throws(QueryTreeException::class);

it('rejects a non-integer limit', function () {
    PaginationBuilder::make(['limit' => '10']);
})->throws(QueryTreeException::class);

it('rejects pagination without limit, offset or cursor', function () {
    PaginationBuilder::make([]);
})->throws(QueryTreeException::class);

it('builds an offset node and a cursor node', function () {
    expect(PaginationBuilder::make(['limit' => 10]))->toBeInstanceOf(OffsetPaginationNode::class)
        ->and(PaginationBuilder::make(['cursor' => 5]))->toBeInstanceOf(CursorPaginationNode::class);
});
