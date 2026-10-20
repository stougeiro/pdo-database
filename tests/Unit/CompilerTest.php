<?php declare(strict_types=1);

use STDW\Pdo\Exception\DriverException;
use STDW\Contract\Pdo\QueryTree\Type\NullOrdering;
use STDW\Contract\Pdo\QueryTree\Type\OrderDirection;
use STDW\Contract\Pdo\Statement\Type\StatementKind;
use STDW\Pdo\Driver\Compiler;
use STDW\Pdo\Dialect\Spec\DialectSpecInterface;
use STDW\Pdo\Dialect\MysqlDialect;
use STDW\Pdo\Dialect\PostgresDialect;
use STDW\Pdo\Dialect\SqliteDialect;
use STDW\Pdo\Param\Param;
use STDW\Pdo\QueryTree\Node\OrderNode;
use STDW\Pdo\QueryTree\Query;
use STDW\Pdo\QueryTree\QueryTreeBuilder;
use STDW\Pdo\Statement\Statement;

/**
 * @return array{sql: string, params: array<int, STDW\Contract\Pdo\Param\ParamInterface>}
 */
function compileSql(DialectSpecInterface $dialect, Statement $statement): array
{
    return (new Compiler($dialect))->compile($statement);
}

it('compiles a filtered, ordered and paginated select', function () {
    $query = QueryTreeBuilder::query(
        filter: ['age gte' => 18, 'name like' => 'A%'],
        order: ['name' => 'asc'],
        pagination: ['limit' => 10, 'offset' => 5],
    );

    $compiled = compileSql(new SqliteDialect(), new Statement(StatementKind::Select, 'users', $query));

    expect($compiled['sql'])->toBe(
        'SELECT * FROM "users" WHERE (("age" >= ?) AND ("name" LIKE ?)) ORDER BY "name" ASC LIMIT 10 OFFSET 5',
    )->and($compiled['params'])->toHaveCount(2)
        ->and($compiled['params'][0]->value())->toBe(18)
        ->and($compiled['params'][1]->value())->toBe('A%');
});

it('compiles projection, distinct, group by, aggregate and having', function () {
    $query = QueryTreeBuilder::query(
        groupBy: ['active'],
        aggregates: [['alias' => 'total', 'function' => 'count', 'column' => '*']],
        having: ['total gt' => 1],
        distinct: true,
        columns: ['active'],
    );

    $compiled = compileSql(new PostgresDialect(), new Statement(StatementKind::Select, 'users', $query));

    expect($compiled['sql'])->toBe(
        'SELECT DISTINCT "active", COUNT(*) AS "total" FROM "users" GROUP BY "active" HAVING "total" > ?',
    )->and($compiled['params'])->toHaveCount(1);
});

it('compiles insert with and without returning per dialect', function () {
    $statement = new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str('Ada'), 'age' => Param::int(36)],
        returning: ['id'],
    );

    expect(compileSql(new SqliteDialect(), $statement)['sql'])
        ->toBe('INSERT INTO "users" ("name", "age") VALUES (?, ?)')
        ->and(compileSql(new PostgresDialect(), $statement)['sql'])
        ->toBe('INSERT INTO "users" ("name", "age") VALUES (?, ?) RETURNING "id"')
        ->and(compileSql(new MysqlDialect(), $statement)['sql'])
        ->toBe('INSERT INTO `users` (`name`, `age`) VALUES (?, ?)');
});

it('compiles update and delete with their filter', function () {
    $update = new Statement(
        StatementKind::Update,
        'users',
        query: QueryTreeBuilder::query(filter: ['id' => 7]),
        data: ['name' => Param::str('Grace')],
    );

    $delete = new Statement(
        StatementKind::Delete,
        'users',
        query: QueryTreeBuilder::query(filter: ['id' => 7]),
    );

    expect(compileSql(new SqliteDialect(), $update)['sql'])
        ->toBe('UPDATE "users" SET "name" = ? WHERE "id" = ?')
        ->and(compileSql(new SqliteDialect(), $delete)['sql'])
        ->toBe('DELETE FROM "users" WHERE "id" = ?');
});

it('emulates ilike on sqlite and keeps it native on postgres', function () {
    $query = QueryTreeBuilder::query(filter: ['name ilike' => 'a%']);
    $statement = new Statement(StatementKind::Select, 'users', $query);

    expect(compileSql(new SqliteDialect(), $statement)['sql'])
        ->toBe('SELECT * FROM "users" WHERE (LOWER("name") LIKE LOWER(?))')
        ->and(compileSql(new PostgresDialect(), $statement)['sql'])
        ->toBe('SELECT * FROM "users" WHERE ("name" ILIKE ?)');
});

it('renders null ordering natively or emulated', function () {
    $query = new Query(order: [new OrderNode('name', OrderDirection::Asc, NullOrdering::First)]);
    $statement = new Statement(StatementKind::Select, 'users', $query);

    expect(compileSql(new PostgresDialect(), $statement)['sql'])
        ->toBe('SELECT * FROM "users" ORDER BY "name" ASC NULLS FIRST')
        ->and(compileSql(new SqliteDialect(), $statement)['sql'])
        ->toBe('SELECT * FROM "users" ORDER BY "name" ASC NULLS FIRST')
        ->and(compileSql(new MysqlDialect(), $statement)['sql'])
        ->toBe('SELECT * FROM `users` ORDER BY `name` IS NULL ASC, `name` ASC');
});

it('renders offset-only pagination per dialect', function () {
    $query = QueryTreeBuilder::query(pagination: ['offset' => 5]);
    $statement = new Statement(StatementKind::Select, 'users', $query);

    expect(compileSql(new SqliteDialect(), $statement)['sql'])->toBe('SELECT * FROM "users" LIMIT -1 OFFSET 5')
        ->and(compileSql(new MysqlDialect(), $statement)['sql'])
        ->toBe('SELECT * FROM `users` LIMIT 18446744073709551615 OFFSET 5')
        ->and(compileSql(new PostgresDialect(), $statement)['sql'])->toBe('SELECT * FROM "users" OFFSET 5');
});

it('compiles a cursor seek with rowid on sqlite and the ordered column elsewhere', function () {
    $sqlite = new Statement(
        StatementKind::Select,
        'users',
        QueryTreeBuilder::query(pagination: ['cursor' => 10, 'limit' => 2]),
    );

    $postgres = new Statement(
        StatementKind::Select,
        'users',
        QueryTreeBuilder::query(order: ['id' => 'asc'], pagination: ['cursor' => 10, 'limit' => 2]),
    );

    expect(compileSql(new SqliteDialect(), $sqlite)['sql'])
        ->toBe('SELECT * FROM "users" WHERE (rowid > ?) LIMIT 2')
        ->and(compileSql(new PostgresDialect(), $postgres)['sql'])
        ->toBe('SELECT * FROM "users" WHERE ("id" > ?) ORDER BY "id" ASC LIMIT 2');
});

it('refuses a cursor without order when the dialect has no fallback', function () {
    $statement = new Statement(
        StatementKind::Select,
        'users',
        QueryTreeBuilder::query(pagination: ['cursor' => 10]),
    );

    compileSql(new MysqlDialect(), $statement);
})->throws(DriverException::class);
