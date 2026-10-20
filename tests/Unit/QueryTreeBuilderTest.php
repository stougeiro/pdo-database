<?php declare(strict_types=1);

use STDW\Pdo\Driver\Compiler;
use STDW\Pdo\Dialect\PostgresDialect;
use STDW\Pdo\Dialect\SqliteDialect;
use STDW\Pdo\Exception\QueryTreeException;
use STDW\Pdo\QueryTree\QueryTreeBuilder;
use STDW\Pdo\Statement\Statement;
use STDW\Contract\Pdo\Statement\Type\StatementKind;

function compileQuery(STDW\Contract\Pdo\QueryTree\QueryInterface $query, string $table = 'users'): string
{
    return (new Compiler(new SqliteDialect()))
        ->compile(new Statement(StatementKind::Select, $table, $query))['sql'];
}

it('selects explicit columns and rejects a blank identifier', function () {
    $query = QueryTreeBuilder::query(columns: ['id', 'name']);

    expect(compileQuery($query))->toBe('SELECT "id", "name" FROM "users"');

    QueryTreeBuilder::columns(['  ']);
})->throws(QueryTreeException::class);

it('rejects a non-string column identifier', function () {
    QueryTreeBuilder::columns([1]);
})->throws(QueryTreeException::class);

it('renders DISTINCT, GROUP BY and HAVING together', function () {
    $query = QueryTreeBuilder::query(
        distinct: true,
        columns: ['active'],
        groupBy: ['active'],
        having: ['total gt' => 1],
        aggregates: [['alias' => 'total', 'function' => 'count', 'column' => '*']],
    );

    $sql = compileQuery($query);

    expect($sql)->toContain('SELECT DISTINCT "active", COUNT(*) AS "total"')
        ->and($sql)->toContain('GROUP BY "active"')
        ->and($sql)->toContain('HAVING "total" > ?');
});

it('rejects an invalid groupBy identifier', function () {
    QueryTreeBuilder::groupBy(['']);
})->throws(QueryTreeException::class);

it('builds every supported aggregate function', function () {
    $query = QueryTreeBuilder::query(aggregates: [
        ['alias' => 'a', 'function' => 'count', 'column' => '*'],
        ['alias' => 'b', 'function' => 'sum', 'column' => 'age'],
        ['alias' => 'c', 'function' => 'avg', 'column' => 'age'],
        ['alias' => 'd', 'function' => 'min', 'column' => 'age'],
        ['alias' => 'e', 'function' => 'max', 'column' => 'age'],
    ]);

    $sql = compileQuery($query);

    expect($sql)->toContain('COUNT(*) AS "a"')
        ->and($sql)->toContain('SUM("age") AS "b"')
        ->and($sql)->toContain('AVG("age") AS "c"')
        ->and($sql)->toContain('MIN("age") AS "d"')
        ->and($sql)->toContain('MAX("age") AS "e"');
});

it('rejects malformed aggregate entries', function () {
    QueryTreeBuilder::aggregate([['function' => 'count', 'column' => '*']]);
})->throws(QueryTreeException::class);

it('rejects an aggregate without a function', function () {
    QueryTreeBuilder::aggregate([['alias' => 'a', 'column' => '*']]);
})->throws(QueryTreeException::class);

it('rejects an aggregate without a column', function () {
    QueryTreeBuilder::aggregate([['alias' => 'a', 'function' => 'count']]);
})->throws(QueryTreeException::class);

it('rejects an unknown aggregate function', function () {
    QueryTreeBuilder::aggregate([['alias' => 'a', 'function' => 'median', 'column' => 'age']]);
})->throws(QueryTreeException::class);

it('rejects a non-array aggregate entry', function () {
    QueryTreeBuilder::aggregate(['count']);
})->throws(QueryTreeException::class);

it('collapses an empty filter to no WHERE clause', function () {
    expect(compileQuery(QueryTreeBuilder::query(filter: [])))->toBe('SELECT * FROM "users"');
});

it('lowers a HAVING group through the public having() facade', function () {
    $group = QueryTreeBuilder::having(['total gt' => 1]);

    expect($group->nodes())->toHaveCount(1);
});

it('renders RETURNING whenever the statement carries columns for every dialect', function () {
    $insert = new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => STDW\Pdo\Param\Param::str('Ada')],
        returning: ['id'],
    );

    expect((new Compiler(new SqliteDialect()))->compile($insert)['sql'])
        ->toBe('INSERT INTO "users" ("name") VALUES (?) RETURNING "id"')
        ->and((new Compiler(new PostgresDialect()))->compile($insert)['sql'])
        ->toBe('INSERT INTO "users" ("name") VALUES (?) RETURNING "id"');
});

it('renders update and delete through the compiler', function () {
    $compiler = new Compiler(new SqliteDialect());

    $update = new Statement(
        StatementKind::Update,
        'users',
        query: QueryTreeBuilder::query(filter: ['id' => 1]),
        data: ['name' => STDW\Pdo\Param\Param::str('Ada')],
    );

    $delete = new Statement(
        StatementKind::Delete,
        'users',
        query: QueryTreeBuilder::query(filter: ['id' => 1]),
    );

    $updateSql = $compiler->compile($update)['sql'];
    $deleteSql = $compiler->compile($delete)['sql'];

    expect($updateSql)->toBe('UPDATE "users" SET "name" = ? WHERE "id" = ?')
        ->and($deleteSql)->toBe('DELETE FROM "users" WHERE "id" = ?');
});
