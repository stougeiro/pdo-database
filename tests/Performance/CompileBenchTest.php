<?php declare(strict_types=1);

use STDW\Contract\Pdo\Statement\Type\StatementKind;
use STDW\Pdo\Dialect\SqliteDialect;
use STDW\Pdo\Driver\Compiler;
use STDW\Pdo\Param\Param;
use STDW\Pdo\QueryTree\QueryTreeBuilder;
use STDW\Pdo\Statement\Statement;

it('compiles a filtered SELECT fast enough', function () {
    $statement = new Statement(
        StatementKind::Select,
        'users',
        QueryTreeBuilder::query(
            filter: ['active' => 1, 'age gte' => 18],
            order: ['name' => 'asc'],
            pagination: ['limit' => 20],
        ),
    );


    $compiler = new Compiler(new SqliteDialect());

    $elapsed = bench('compile select(filter+order+limit)', 2000, fn () => $compiler->compile($statement));

    expect($elapsed)->toBeGreaterThan(0);
});

it('compiles an INSERT fast enough', function () {
    $statement = new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str('Ada'), 'age' => Param::int(36)],
        returning: ['id'],
    );

    $compiler = new Compiler(new SqliteDialect());

    $elapsed = bench('compile insert', 2000, fn () => $compiler->compile($statement));

    expect($elapsed)->toBeGreaterThan(0);
});
