<?php declare(strict_types=1);

use STDW\Pdo\Config\SqliteConfig;
use STDW\Pdo\Connection\PdoConnection;
use STDW\Pdo\Dialect\DialectAbstracted;
use STDW\Pdo\Dialect\Spec\DialectSpecInterface;
use STDW\Pdo\Dialect\SqliteDialect;
use STDW\Pdo\Driver\PdoDriver;
use STDW\Pdo\Exception\DriverException;
use STDW\Pdo\Param\Param;
use STDW\Pdo\QueryTree\QueryTreeBuilder;
use STDW\Pdo\Result\Raw;
use STDW\Pdo\Statement\Statement;
use STDW\Contract\Pdo\Statement\Type\StatementKind;

/**
 * SQLite's own dialect reports supportsReturning() = false, so to reach the
 * RETURNING branch of the driver we run against real SQLite (which does
 * support RETURNING since 3.35) with a dialect that opts in. Same delimiters
 * and LIMIT/OFFSET, only the generated-key strategy differs.
 */
function returningSqliteDialect(): DialectSpecInterface
{
    return new class extends DialectAbstracted {
        protected function delimiters(): array
        {
            return ['"', '"'];
        }

        public function supportsReturning(): bool
        {
            return true;
        }

        protected function renderLimitOffset(?int $limit, ?int $offset): string
        {
            if ($limit === null && $offset === null) {
                return '';
            }

            if ($limit === null) {
                return ' LIMIT -1 OFFSET ' . $offset;
            }

            return ' LIMIT ' . $limit . ($offset !== null ? ' OFFSET ' . $offset : '');
        }
    };
}

function memoryConnection(): PdoConnection
{
    $connection = new PdoConnection(SqliteConfig::from(['database' => ':memory:']));
    $connection->pdo()->exec(
        'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER)'
    );

    return $connection;
}

it('selects rows through the driver', function () {
    $connection = memoryConnection();
    $connection->pdo()->exec("INSERT INTO users (name, age) VALUES ('Ada', 36), ('Grace', 45)");

    $driver = new PdoDriver($connection, new SqliteDialect());

    $raw = $driver->execute(new Statement(
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

    $driver->execute(new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str("Ada'; DROP TABLE users; --"), 'age' => Param::int(36)],
    ));

    $raw = $driver->execute(new Statement(StatementKind::Select, 'users'));

    expect($raw->rows()[0]['name'])->toBe("Ada'; DROP TABLE users; --");
});

it('returns affected count for update and delete', function () {
    $connection = memoryConnection();
    $connection->pdo()->exec("INSERT INTO users (name, age) VALUES ('Ada', 36), ('Grace', 45)");
    $driver = new PdoDriver($connection, new SqliteDialect());

    $updated = $driver->execute(new Statement(
        StatementKind::Update,
        'users',
        query: QueryTreeBuilder::query(filter: ['id' => 1]),
        data: ['name' => Param::str('Ada L.')],
    ));

    $deleted = $driver->execute(new Statement(
        StatementKind::Delete,
        'users',
        query: QueryTreeBuilder::query(filter: ['id' => 1]),
    ));

    expect($updated->affected())->toBe(1)
        ->and($deleted->affected())->toBe(1);
});

it('reads the generated key from lastInsertId when RETURNING is off', function () {
    $connection = memoryConnection();
    $driver = new PdoDriver($connection, new SqliteDialect());

    $raw = $driver->execute(new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str('Ada')],
        returning: ['id'],
    ));

    expect($raw->insertedId())->toBe(1);
});

it('reads the generated key from the RETURNING row when supported', function () {
    $connection = memoryConnection();
    $driver = new PdoDriver($connection, returningSqliteDialect());

    $raw = $driver->execute(new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str('Ada'), 'age' => Param::int(36)],
        returning: ['id'],
    ));

    expect($raw->rows())->toHaveCount(1)
        ->and($raw->insertedId())->toBe(1)
        ->and($raw->affected())->toBe(0);
});

it('falls back to the first returned column when the key column is not present', function () {
    $connection = memoryConnection();
    $driver = new PdoDriver($connection, returningSqliteDialect());

    $raw = $driver->execute(new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str('Ada')],
        returning: ['users.id'],
    ));

    expect($raw->insertedId())->toBe(1);
});

it('wraps a PDO failure into a DriverException', function () {
    $connection = memoryConnection();
    $driver = new PdoDriver($connection, new SqliteDialect());

    $driver->execute(new Statement(StatementKind::Select, 'missing_table'));
})->throws(DriverException::class);

it('returns a param as-is through Raw', function () {
    $raw = new Raw([['id' => 1]], 1, 'abc');

    expect($raw->rows())->toBe([['id' => 1]])
        ->and($raw->affected())->toBe(1)
        ->and($raw->insertedId())->toBe('abc');
});
