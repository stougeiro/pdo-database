<?php declare(strict_types=1);

use STDW\Pdo\QueryTree\QueryTreeBuilder;

it('lowers a complex filter DSL fast enough', function () {
    $filter = [
        ['active' => 1],
        'AND',
        ['age between' => [18, 65]],
        'AND',
        [['role in' => ['admin', 'editor']], 'OR', ['name ilike' => 'a%']],
    ];

    $elapsed = bench('lower filter DSL', 2000, fn () => QueryTreeBuilder::query(filter: $filter));

    expect($elapsed)->toBeGreaterThan(0);
});

it('lowers an order and pagination DSL fast enough', function () {
    $elapsed = bench('lower order+pagination DSL', 2000, fn () => QueryTreeBuilder::query(
        order: ['name desc', 'created_at asc'],
        pagination: ['limit' => 25, 'offset' => 100],
    ));

    expect($elapsed)->toBeGreaterThan(0);
});
