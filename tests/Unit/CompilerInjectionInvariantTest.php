<?php declare(strict_types=1);

use STDW\Contract\Pdo\Statement\Type\StatementKind;
use STDW\Contract\Pdo\DialectInterface;
use STDW\Pdo\Dialect\MysqlDialect;
use STDW\Pdo\Dialect\PostgresDialect;
use STDW\Pdo\Dialect\SqliteDialect;
use STDW\Pdo\Driver\Compiler;
use STDW\Pdo\Param\Param;
use STDW\Pdo\QueryTree\QueryTreeBuilder;
use STDW\Pdo\Statement\Statement;

/**
 * The compiler invariant behind the security claim: a value never reaches the
 * SQL text. Whatever the arbitrary, hostile value is, the compiled SQL carries
 * only "?" placeholders — the value itself lives in the params list, bound
 * later through PDO. If a value ever leaked into the SQL, these tests fail.
 */

/**
 * @return array<string, DialectInterface>
 */
function dialectMatrix(): array
{
    return [
        'sqlite' => new SqliteDialect(),
        'mysql' => new MysqlDialect(),
        'postgres' => new PostgresDialect(),
    ];
}

/**
 * @param array<int, STDW\Contract\Pdo\Param\ParamInterface> $params
 */
function assertNoLiteral(array $compiled, string $needle): void
{
    expect($compiled['sql'])->not->toContain($needle);

    $values = array_map(static fn ($param) => $param->value(), $compiled['params']);

    expect($values)->toContain($needle);
}

dataset('hostile values', [
    "Robert'); DROP TABLE users; --",
    "' OR '1'='1",
    '1 OR 1=1',
    '"; DROP TABLE users; --',
    'a"b`c\'d',
    '%_\\',
]);

it('never renders an insert value as SQL', function (string $value) {
    foreach (dialectMatrix() as $dialect) {
        $compiled = (new Compiler($dialect))->compile(new Statement(
            StatementKind::Insert,
            'users',
            data: ['name' => Param::str($value)],
        ));

        assertNoLiteral($compiled, $value);
    }
})->with('hostile values');

it('never renders an update assignment or where value as SQL', function (string $value) {
    foreach (dialectMatrix() as $dialect) {
        $compiled = (new Compiler($dialect))->compile(new Statement(
            StatementKind::Update,
            'users',
            query: QueryTreeBuilder::query(filter: ['name' => Param::str($value)]),
            data: ['name' => Param::str($value)],
        ));

        assertNoLiteral($compiled, $value);
    }
})->with('hostile values');

it('never renders a delete where value as SQL', function (string $value) {
    foreach (dialectMatrix() as $dialect) {
        $compiled = (new Compiler($dialect))->compile(new Statement(
            StatementKind::Delete,
            'users',
            query: QueryTreeBuilder::query(filter: ['name' => Param::str($value)]),
        ));

        assertNoLiteral($compiled, $value);
    }
})->with('hostile values');

it('never renders a select value as SQL across every operator', function (string $value) {
    $param = Param::str($value);

    $queries = [
        QueryTreeBuilder::query(filter: ['name' => $param]),
        QueryTreeBuilder::query(filter: ['name like' => $param]),
        QueryTreeBuilder::query(filter: ['name ilike' => $param]),
        QueryTreeBuilder::query(filter: ['name in' => [$param]]),
        QueryTreeBuilder::query(filter: ['name not_in' => [$param]]),
        QueryTreeBuilder::query(order: ['name' => 'asc']),
        QueryTreeBuilder::query(filter: ['name' => $param], order: ['name' => 'asc'], pagination: ['cursor' => 1]),
    ];

    foreach (dialectMatrix() as $dialect) {
        $compiler = new Compiler($dialect);

        foreach ($queries as $query) {
            $compiled = $compiler->compile(new Statement(StatementKind::Select, 'users', $query));

            if (in_array($value, array_map(static fn ($p) => $p->value(), $compiled['params']), true)) {
                assertNoLiteral($compiled, $value);
            } else {
                expect($compiled['sql'])->not->toContain($value);
            }
        }
    }
})->with('hostile values');

it('has one placeholder per param for a hostile mixed statement', function () {
    $value = "Robert'); DROP TABLE users; --";

    foreach (dialectMatrix() as $dialect) {
        $compiled = (new Compiler($dialect))->compile(new Statement(
            StatementKind::Select,
            'users',
            QueryTreeBuilder::query(
                filter: ['name' => Param::str($value), 'age gte' => Param::int(18)],
                pagination: ['limit' => 5, 'offset' => 2],
            ),
        ));

        $placeholders = substr_count($compiled['sql'], '?');

        expect($placeholders)->toBe(count($compiled['params']))
            ->and($placeholders)->toBe(2);
    }
});

it('keeps the hostile value out of SQL when it is also the table-independent literal', function () {
    $value = '"'; // the quote character that the dialect must escape

    foreach (dialectMatrix() as $dialect) {
        $compiled = (new Compiler($dialect))->compile(new Statement(
            StatementKind::Select,
            $value,
            QueryTreeBuilder::query(filter: ['name' => Param::str($value)]),
        ));

        // The identifier is quoted (and its delimiter escaped); the value stays
        // a placeholder. The raw payload must not appear as a bare token.
        expect(substr_count($compiled['sql'], '?'))->toBe(1)
            ->and($compiled['params'][0]->value())->toBe($value);
    }
});
