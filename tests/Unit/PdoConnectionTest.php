<?php declare(strict_types=1);

use STDW\Pdo\Config\SqliteConfig;
use STDW\Pdo\Connection\PdoConnection;
use STDW\Pdo\Exception\ConnectionException;
use STDW\Pdo\Param\Param;

function sqliteConnection(): PdoConnection
{
    return new PdoConnection(SqliteConfig::from(['database' => ':memory:']));
}

function seededConnection(): PdoConnection
{
    $connection = sqliteConnection();
    $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    $connection->execute("INSERT INTO users (name) VALUES ('Ada'), ('Grace')");

    return $connection;
}

it('connects lazily and is idempotent', function () {
    $connection = sqliteConnection();

    expect($connection->isConnected())->toBeFalse();

    $connection->connect();
    $connection->connect();

    expect($connection->isConnected())->toBeTrue();
});

it('reconnects after a disconnect', function () {
    $connection = sqliteConnection();
    $connection->connect();

    $connection->disconnect();

    expect($connection->isConnected())->toBeFalse();

    $connection->connect();

    expect($connection->isConnected())->toBeTrue();
});

it('wraps an unopenable dsn into a ConnectionException', function () {
    $connection = new PdoConnection(SqliteConfig::from(['database' => '/no/such/dir/db.sqlite']));

    $connection->connect();
})->throws(ConnectionException::class);

it('reports transaction state from the native handle', function () {
    $connection = sqliteConnection();

    expect($connection->inTransaction())->toBeFalse();

    $connection->begin();

    expect($connection->inTransaction())->toBeTrue();

    $connection->commit();

    expect($connection->inTransaction())->toBeFalse();
});

it('wraps a second begin while a transaction is open', function () {
    $connection = sqliteConnection();

    $connection->begin();
    $connection->begin();
})->throws(ConnectionException::class);

it('wraps a commit without an open transaction', function () {
    sqliteConnection()->commit();
})->throws(ConnectionException::class);

it('wraps a rollback without an open transaction', function () {
    sqliteConnection()->rollback();
})->throws(ConnectionException::class);

it('keeps the retained connection out of a transaction when disconnected', function () {
    $connection = sqliteConnection();
    $connection->connect();

    $connection->disconnect();

    expect($connection->inTransaction())->toBeFalse();
});

it('fetches the first row and every row through the raw surface', function () {
    $connection = seededConnection();

    $one = $connection->fetch('SELECT name FROM users WHERE id = ?', [Param::int(1)]);
    $all = $connection->fetchAll('SELECT name FROM users ORDER BY id');

    expect($one)->toBe(['name' => 'Ada'])
        ->and($all)->toBe([['name' => 'Ada'], ['name' => 'Grace']]);
});

it('returns null from fetch when there is no row', function () {
    expect(seededConnection()->fetch('SELECT name FROM users WHERE id = ?', [Param::int(99)]))->toBeNull();
});

it('runs a raw write and reports the affected count', function () {
    $connection = seededConnection();

    expect($connection->execute("UPDATE users SET name = 'X' WHERE id <= ?", [Param::int(2)]))->toBe(2);
});

it('reads the last insert id through the raw surface', function () {
    $connection = seededConnection();

    $connection->execute("INSERT INTO users (name) VALUES ('Linus')");

    expect($connection->lastInsertId())->toBe('3');
});

it('rejects a raw value that is not a typed Param', function () {
    seededConnection()->fetch('SELECT name FROM users WHERE id = ?', [1]);
})->throws(ConnectionException::class);

it('binds a named placeholder through the raw surface', function () {
    $connection = seededConnection();

    $row = $connection->fetch('SELECT name FROM users WHERE name = :name', [':name' => Param::str('Grace')]);

    expect($row)->toBe(['name' => 'Grace']);
});
