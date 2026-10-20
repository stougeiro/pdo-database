<?php declare(strict_types=1);

use STDW\Contract\Pdo\Statement\Type\StatementKind;
use STDW\Pdo\Exception\StatementException;
use STDW\Pdo\Param\Param;
use STDW\Pdo\QueryTree\QueryTreeBuilder;
use STDW\Pdo\Statement\Statement;

it('accepts a select without data', function () {
    $statement = new Statement(StatementKind::Select, 'users');

    expect($statement->kind())->toBe(StatementKind::Select)
        ->and($statement->table())->toBe('users')
        ->and($statement->query())->toBeNull()
        ->and($statement->data())->toBe([]);
});

it('accepts an insert with data', function () {
    $statement = new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str('Ada')],
    );

    expect($statement->data())->toHaveCount(1);
});

it('rejects an insert without data', function () {
    new Statement(StatementKind::Insert, 'users');
})->throws(StatementException::class);

it('rejects an insert carrying a query', function () {
    new Statement(
        StatementKind::Insert,
        'users',
        query: QueryTreeBuilder::query(filter: ['id' => 1]),
        data: ['name' => Param::str('Ada')],
    );
})->throws(StatementException::class);

it('rejects an unfiltered update', function () {
    new Statement(
        StatementKind::Update,
        'users',
        data: ['name' => Param::str('Ada')],
    );
})->throws(StatementException::class);

it('rejects an unfiltered delete', function () {
    new Statement(StatementKind::Delete, 'users');
})->throws(StatementException::class);

it('rejects returning outside an insert', function () {
    new Statement(
        StatementKind::Update,
        'users',
        query: QueryTreeBuilder::query(filter: ['id' => 1]),
        data: ['name' => Param::str('Ada')],
        returning: ['id'],
    );
})->throws(StatementException::class);

it('rejects an empty table', function () {
    new Statement(StatementKind::Select, '   ');
})->throws(StatementException::class);

it('rejects a raw value in data before anything else runs', function () {
    new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => 'Ada'],
    );
})->throws(StatementException::class);

it('reports the offending column when data is not typed', function () {
    try {
        new Statement(StatementKind::Insert, 'users', data: ['name' => 'Ada']);
    } catch (StatementException $exception) {
        expect($exception->getMessage())->toContain('name');
    }
});

it('rejects a select carrying data', function () {
    new Statement(StatementKind::Select, 'users', data: ['name' => Param::str('Ada')]);
})->throws(StatementException::class);

it('rejects a select carrying returning columns', function () {
    new Statement(StatementKind::Select, 'users', returning: ['id']);
})->throws(StatementException::class);

it('rejects an update without data but with a filter', function () {
    new Statement(StatementKind::Update, 'users', query: QueryTreeBuilder::query(filter: ['id' => 1]));
})->throws(StatementException::class);

it('rejects a delete carrying data', function () {
    new Statement(
        StatementKind::Delete,
        'users',
        query: QueryTreeBuilder::query(filter: ['id' => 1]),
        data: ['name' => Param::str('Ada')],
    );
})->throws(StatementException::class);

it('rejects returning on a delete', function () {
    new Statement(
        StatementKind::Delete,
        'users',
        query: QueryTreeBuilder::query(filter: ['id' => 1]),
        returning: ['id'],
    );
})->throws(StatementException::class);

it('accepts an update with data and a filter', function () {
    $statement = new Statement(
        StatementKind::Update,
        'users',
        query: QueryTreeBuilder::query(filter: ['id' => 1]),
        data: ['name' => Param::str('Ada')],
    );

    expect($statement->data())->toHaveCount(1);
});

it('accepts a delete with a filter', function () {
    $statement = new Statement(
        StatementKind::Delete,
        'users',
        query: QueryTreeBuilder::query(filter: ['id' => 1]),
    );

    expect($statement->returning())->toBe([]);
});
