<?php declare(strict_types=1);

use STDW\Pdo\Config\SqliteConfig;
use STDW\Pdo\Connection\PdoConnection;
use STDW\Pdo\Exception\ConnectionException;

function sqliteConnection(): PdoConnection
{
    return new PdoConnection(SqliteConfig::from(['database' => ':memory:']));
}

it('exposes the config it was built with', function () {
    $config = SqliteConfig::from(['database' => ':memory:']);

    expect((new PdoConnection($config))->config())->toBe($config);
});

it('connects lazily and reuses the same handle', function () {
    $connection = sqliteConnection();

    expect($connection->isConnected())->toBeFalse();

    $pdo = $connection->pdo();

    expect($pdo)->toBeInstanceOf(PDO::class)
        ->and($connection->isConnected())->toBeTrue()
        ->and($connection->pdo())->toBe($pdo);
});

it('reconnects after a disconnect', function () {
    $connection = sqliteConnection();
    $first = $connection->pdo();

    $connection->disconnect();

    expect($connection->isConnected())->toBeFalse()
        ->and($connection->pdo())->not->toBe($first);
});

it('wraps an unopenable dsn into a ConnectionException', function () {
    $connection = new PdoConnection(SqliteConfig::from(['database' => '/no/such/dir/db.sqlite']));

    $connection->pdo();
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
    $connection->pdo();

    $connection->disconnect();

    expect($connection->inTransaction())->toBeFalse();
});
