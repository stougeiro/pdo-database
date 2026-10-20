<?php declare(strict_types=1);

use STDW\Pdo\Config\SqliteConfig;
use STDW\Pdo\Connection\PdoConnection;
use STDW\Pdo\Dialect\SqliteDialect;
use STDW\Pdo\Driver\PdoDriver;
use STDW\Pdo\Exception\DriverException;
use STDW\Pdo\Param\Param;
use STDW\Pdo\QueryTree\QueryTreeBuilder;
use STDW\Pdo\Result\Raw;
use STDW\Pdo\Statement\Statement;
use STDW\Contract\Pdo\Statement\Type\StatementKind;

/**
 * SQLite supports RETURNING since 3.35, so SqliteDialect opts in and the
 * driver takes the native branch by default. The last-insert-id and emulation
 * branches are reached by turning the config flag off (supportsReturning:
 * false), which is how an older server would be declared. The two switches
 * are independent: supportsReturning wins, emulateReturning is consulted only
 * when it is off.
 */
function memoryConnection(): PdoConnection
{
    $connection = new PdoConnection(SqliteConfig::from(['database' => ':memory:']));
    $connection->execute(
        'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER)'
    );

    return $connection;
}

it('selects rows through the driver', function () {
    $connection = memoryConnection();
    $connection->execute("INSERT INTO users (name, age) VALUES ('Ada', 36), ('Grace', 45)");

    $driver = new PdoDriver($connection, new SqliteDialect());

    $raw = $driver->translate(new Statement(
        StatementKind::Select,
        'users',
        QueryTreeBuilder::query(order: ['id' => 'asc']),
    ));

    expect($raw->rows())->toHaveCount(2)
        ->and($raw->rows()[0]['name'])->toBe('Ada')
        ->and($raw->affected())->toBe(0);
});

it('binds typed params instead of interpolating them', function () {
    $connection = memoryConnection();
    $driver = new PdoDriver($connection, new SqliteDialect());

    $driver->translate(new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str("Ada'; DROP TABLE users; --"), 'age' => Param::int(36)],
    ));

    $raw = $driver->translate(new Statement(StatementKind::Select, 'users'));

    expect($raw->rows()[0]['name'])->toBe("Ada'; DROP TABLE users; --");
});

it('returns affected count for update and delete', function () {
    $connection = memoryConnection();
    $connection->execute("INSERT INTO users (name, age) VALUES ('Ada', 36), ('Grace', 45)");
    $driver = new PdoDriver($connection, new SqliteDialect());

    $updated = $driver->translate(new Statement(
        StatementKind::Update,
        'users',
        query: QueryTreeBuilder::query(filter: ['id' => 1]),
        data: ['name' => Param::str('Ada L.')],
    ));

    $deleted = $driver->translate(new Statement(
        StatementKind::Delete,
        'users',
        query: QueryTreeBuilder::query(filter: ['id' => 1]),
    ));

    expect($updated->affected())->toBe(1)
        ->and($deleted->affected())->toBe(1);
});

it('reads the generated key from lastInsertId when RETURNING is off', function () {
    $connection = memoryConnection();
    $driver = new PdoDriver($connection, new SqliteDialect(), supportsReturning: false, emulateReturning: false);

    $raw = $driver->translate(new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str('Ada')],
        returning: ['id'],
    ));

    expect($raw->insertedId())->toBe(1)
        ->and($raw->rows())->toBe([]);
});

it('reads the generated key from the RETURNING row when supported', function () {
    $connection = memoryConnection();
    $driver = new PdoDriver($connection, new SqliteDialect());

    $raw = $driver->translate(new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str('Ada'), 'age' => Param::int(36)],
        returning: ['id'],
    ));

    expect($raw->rows())->toHaveCount(1)
        ->and($raw->insertedId())->toBe(1)
        ->and($raw->affected())->toBe(1);
});

it('falls back to the first returned column when the key column is not present', function () {
    $connection = memoryConnection();
    $driver = new PdoDriver($connection, new SqliteDialect());

    $raw = $driver->translate(new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str('Ada')],
        returning: ['users.id'],
    ));

    expect($raw->insertedId())->toBe(1);
});

it('prefers the native RETURNING over emulateReturning when both are on', function () {
    $connection = memoryConnection();
    $driver = new PdoDriver($connection, new SqliteDialect(), supportsReturning: true, emulateReturning: true);

    $raw = $driver->translate(new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str('Ada')],
        returning: ['id'],
    ));

    expect($raw->rows())->toHaveCount(1)
        ->and($raw->insertedId())->toBe(1);
});

it('emulates RETURNING by reading the stored record back through the key', function () {
    $connection = memoryConnection();
    $driver = new PdoDriver($connection, new SqliteDialect(), supportsReturning: false);

    $raw = $driver->translate(new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str('Ada'), 'age' => Param::int(36)],
        returning: ['*'],
        key: 'id',
    ));

    expect($raw->insertedId())->toBe(1)
        ->and($raw->affected())->toBe(1)
        ->and($raw->rows())->toHaveCount(1)
        ->and($raw->rows()[0]['name'])->toBe('Ada')
        ->and($raw->rows()[0]['age'])->toBe(36)
        ->and($raw->rows()[0]['id'])->toBe(1);
});

it('skips the emulation select when emulateReturning is off', function () {
    $connection = memoryConnection();
    $driver = new PdoDriver($connection, new SqliteDialect(), supportsReturning: false, emulateReturning: false);

    $raw = $driver->translate(new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str('Ada')],
        returning: ['*'],
        key: 'id',
    ));

    expect($raw->insertedId())->toBe(1)
        ->and($raw->affected())->toBe(1)
        ->and($raw->rows())->toBe([]);
});

it('wraps a PDO failure into a DriverException', function () {
    $connection = memoryConnection();
    $driver = new PdoDriver($connection, new SqliteDialect());

    $driver->translate(new Statement(StatementKind::Select, 'missing_table'));
})->throws(DriverException::class);

it('wraps a failed write into a DriverException', function () {
    $connection = memoryConnection();
    $driver = new PdoDriver($connection, new SqliteDialect());

    $driver->translate(new Statement(
        StatementKind::Update,
        'missing_table',
        query: QueryTreeBuilder::query(filter: ['id' => 1]),
        data: ['name' => Param::str('Ada')],
    ));
})->throws(DriverException::class);

it('returns a param as-is through Raw', function () {
    $raw = new Raw([['id' => 1]], 1, 'abc');

    expect($raw->rows())->toBe([['id' => 1]])
        ->and($raw->affected())->toBe(1)
        ->and($raw->insertedId())->toBe('abc');
});
