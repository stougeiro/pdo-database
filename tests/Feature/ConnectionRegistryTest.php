<?php declare(strict_types=1);

use STDW\Contract\Pdo\DriverInterface;
use STDW\Pdo\ConnectionRegistry;
use STDW\Pdo\Exception\ConnectionException;

beforeEach(function () {
    ConnectionRegistry::reset();
});

afterEach(function () {
    ConnectionRegistry::reset();
});

it('fails to resolve an unconfigured connection', function () {
    ConnectionRegistry::get('missing');
})->throws(ConnectionException::class);

it('fails on an unknown driver nickname', function () {
    ConnectionRegistry::configure([
        'default' => ['driver' => 'oracle'],
    ]);
})->throws(ConnectionException::class);

it('caches one shared connection per name', function () {
    ConnectionRegistry::configure([
        'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'other' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ]);

    expect(ConnectionRegistry::get('default'))->toBe(ConnectionRegistry::get('default'))
        ->and(ConnectionRegistry::get('default'))->not->toBe(ConnectionRegistry::get('other'));
});

it('resolves a driver over the shared connection', function () {
    ConnectionRegistry::configure([
        'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ]);

    expect(ConnectionRegistry::resolve())->toBeInstanceOf(DriverInterface::class);
});

it('connects lazily and disconnects idempotently', function () {
    ConnectionRegistry::configure([
        'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ]);

    $connection = ConnectionRegistry::get();

    expect($connection->isConnected())->toBeFalse();

    $connection->connect();
    $connection->connect();

    expect($connection->isConnected())->toBeTrue();

    $connection->disconnect();

    expect($connection->isConnected())->toBeFalse();
});

it('cannot turn off the error mode or fetch mode invariants', function () {
    ConnectionRegistry::configure([
        'default' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
            ],
        ],
    ]);

    $connection = ConnectionRegistry::get();

    $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->execute('INSERT INTO users (id, name) VALUES (1, \'Ada\')');

    $row = $connection->fetch('SELECT id, name FROM users WHERE id = 1');

    expect($row)->toBe(['id' => 1, 'name' => 'Ada']);

    expect(fn () => $connection->fetch('SELECT * FROM missing_table'))
        ->toThrow(STDW\Pdo\Exception\ConnectionException::class);
});

it('rejects an empty connection name', function () {
    ConnectionRegistry::configure(['  ' => ['driver' => 'sqlite', 'database' => ':memory:']]);
})->throws(ConnectionException::class);

it('rejects a non-string driver nickname', function () {
    ConnectionRegistry::configure(['default' => ['driver' => 123]]);
})->throws(ConnectionException::class);

it('exposes the sqlite, mysql, mariadb and postgres nicknames', function () {
    expect(ConnectionRegistry::SQLITE)->toBe('sqlite')
        ->and(ConnectionRegistry::MYSQL)->toBe('mysql')
        ->and(ConnectionRegistry::MARIADB)->toBe('mariadb')
        ->and(ConnectionRegistry::POSTGRES)->toBe('postgres');
});

it('builds a mysql connection and driver lazily without connecting', function () {
    ConnectionRegistry::configure([
        'default' => [
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'schema' => 'app',
            'charset' => 'utf8mb4',
            'username' => 'root',
            'password' => 'secret',
        ],
    ]);

    $connection = ConnectionRegistry::get();

    expect($connection)->toBeInstanceOf(STDW\Pdo\Connection\PdoConnection::class)
        ->and($connection->isConnected())->toBeFalse()
        ->and(ConnectionRegistry::resolve())->toBeInstanceOf(DriverInterface::class);
});

it('builds a mariadb connection and driver lazily without connecting', function () {
    ConnectionRegistry::configure([
        'default' => [
            'driver' => 'mariadb',
            'host' => 'localhost',
            'port' => 3306,
            'schema' => 'app',
            'charset' => 'utf8mb4',
            'username' => 'root',
            'password' => 'secret',
        ],
    ]);

    $connection = ConnectionRegistry::get();

    expect($connection)->toBeInstanceOf(STDW\Pdo\Connection\PdoConnection::class)
        ->and($connection->isConnected())->toBeFalse()
        ->and(ConnectionRegistry::resolve())->toBeInstanceOf(DriverInterface::class);
});

it('builds a postgres connection and dialect lazily without connecting', function () {
    ConnectionRegistry::configure([
        'default' => [
            'driver' => 'postgres',
            'host' => 'db',
            'port' => 5432,
            'schema' => 'app',
            'username' => 'postgres',
            'password' => 'secret',
        ],
    ]);

    expect(ConnectionRegistry::get()->isConnected())->toBeFalse()
        ->and(ConnectionRegistry::resolve())->toBeInstanceOf(DriverInterface::class);
});

it('drops the registry on reset', function () {
    ConnectionRegistry::configure([
        'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ]);

    ConnectionRegistry::get();
    ConnectionRegistry::reset();

    ConnectionRegistry::get();
})->throws(ConnectionException::class);
