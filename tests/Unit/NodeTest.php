<?php declare(strict_types=1);

use STDW\Contract\Pdo\Param\ParamInterface;
use STDW\Contract\Pdo\QueryTree\Node\ConditionNodeInterface;
use STDW\Contract\Pdo\QueryTree\Type\AggregateFunction;
use STDW\Contract\Pdo\QueryTree\Type\ConditionOperator;
use STDW\Contract\Pdo\QueryTree\Type\NullOrdering;
use STDW\Contract\Pdo\QueryTree\Type\OrderDirection;
use STDW\Pdo\Exception\QueryTreeException;
use STDW\Pdo\Param\Param;
use STDW\Pdo\QueryTree\Node\AggregateNode;
use STDW\Pdo\QueryTree\Node\ConditionNode;
use STDW\Pdo\QueryTree\Node\CursorPaginationNode;
use STDW\Pdo\QueryTree\Node\GroupNode;
use STDW\Pdo\QueryTree\Node\LogicNode;
use STDW\Pdo\QueryTree\Node\OffsetPaginationNode;
use STDW\Pdo\QueryTree\Node\OrderNode;
use STDW\Contract\Pdo\QueryTree\Type\LogicOperator;

it('exposes the aggregate parts and rejects blank alias/column', function () {
    $node = new AggregateNode('total', AggregateFunction::Count, '*');

    expect($node->alias())->toBe('total')
        ->and($node->operation())->toBe(AggregateFunction::Count)
        ->and($node->column())->toBe('*');

    expect(fn () => new AggregateNode('  ', AggregateFunction::Count, '*'))
        ->toThrow(QueryTreeException::class)
        ->and(fn () => new AggregateNode('a', AggregateFunction::Count, ' '))
        ->toThrow(QueryTreeException::class);
});

it('returns a scalar value for a single-value operator', function () {
    $node = new ConditionNode('a', ConditionOperator::Eq, Param::int(1));

    expect($node->value())->toBeInstanceOf(ParamInterface::class)
        ->and($node->value()->value())->toBe(1);
});

it('returns null for a null operator and the list for list operators', function () {
    expect((new ConditionNode('a', ConditionOperator::IsNull, []))->value())->toBeNull()
        ->and((new ConditionNode('a', ConditionOperator::In, [Param::int(1), Param::int(2)]))->value())
        ->toHaveCount(2);
});

it('keeps a single-element list as a list for list operators', function () {
    $node = new ConditionNode('a', ConditionOperator::NotIn, [Param::int(1)]);

    expect($node->value())->toBeArray()->toHaveCount(1);
});

it('rejects a blank condition column', function () {
    new ConditionNode(' ', ConditionOperator::Eq, Param::int(1));
})->throws(QueryTreeException::class);

it('rejects a non-list value for a list operator', function () {
    new ConditionNode('a', ConditionOperator::In, Param::int(1));
})->throws(QueryTreeException::class);

it('rejects a wrong list arity', function () {
    new ConditionNode('a', ConditionOperator::Between, [Param::int(1)]);
})->throws(QueryTreeException::class);

it('rejects a non-param entry inside a list', function () {
    new ConditionNode('a', ConditionOperator::In, [1]);
})->throws(QueryTreeException::class);

it('rejects a list value for a single-value operator', function () {
    new ConditionNode('a', ConditionOperator::Eq, [Param::int(1)]);
})->throws(QueryTreeException::class);

it('exposes the cursor parts and rejects a negative limit', function () {
    $node = new CursorPaginationNode(10, 'opaque');

    expect($node->limit())->toBe(10)
        ->and($node->cursor())->toBe('opaque');

    expect(fn () => new CursorPaginationNode(-1))
        ->toThrow(QueryTreeException::class);
});

it('exposes the offset parts and rejects negative values', function () {
    $node = new OffsetPaginationNode(10, 5);

    expect($node->limit())->toBe(10)
        ->and($node->offset())->toBe(5);

    expect(fn () => new OffsetPaginationNode(-1))
        ->toThrow(QueryTreeException::class)
        ->and(fn () => new OffsetPaginationNode(null, -1))
        ->toThrow(QueryTreeException::class);
});

it('exposes the order parts and rejects a blank column', function () {
    $node = new OrderNode('name', OrderDirection::Desc, NullOrdering::First);

    expect($node->column())->toBe('name')
        ->and($node->direction())->toBe(OrderDirection::Desc)
        ->and($node->nulls())->toBe(NullOrdering::First);

    expect(fn () => new OrderNode('  '))
        ->toThrow(QueryTreeException::class);
});

it('rejects a foreign child inside a group', function () {
    new GroupNode(['not-a-node']);
})->throws(QueryTreeException::class);

it('keeps only valid children and reports them', function () {
    $condition = new ConditionNode('a', ConditionOperator::Eq, Param::int(1));
    $logic = new LogicNode(LogicOperator::And);
    $nested = new GroupNode([$condition]);

    expect((new GroupNode([$condition, $logic, $nested]))->nodes())->toBe([$condition, $logic, $nested]);
});
