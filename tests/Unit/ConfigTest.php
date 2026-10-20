<?php declare(strict_types=1);

use STDW\Pdo\Config\MysqlConfig;
use STDW\Pdo\Config\PostgresConfig;
use STDW\Pdo\Config\SqliteConfig;
use STDW\Pdo\Exception\ConnectionException;

it('builds the sqlite dsn and exposes no credentials', function () {
    $config = SqliteConfig::from(['database' => ':memory:']);

    expect($config->dsn())->toBe('sqlite::memory:')
        ->and($config->database())->toBe(':memory:')
        ->and($config->username())->toBeNull()
        ->and($config->password())->toBeNull()
        ->and($config->options())->toBe([]);
});

it('builds the mysql dsn from every required key', function () {
    $config = MysqlConfig::from([
        'host' => 'localhost',
        'port' => 3306,
        'schema' => 'app',
        'charset' => 'utf8mb4',
        'username' => 'root',
        'password' => 'secret',
        'options' => [PDO::ATTR_EMULATE_PREPARES => false],
    ]);

    expect($config->dsn())->toBe('mysql:host=localhost;port=3306;dbname=app;charset=utf8mb4')
        ->and($config->host())->toBe('localhost')
        ->and($config->port())->toBe(3306)
        ->and($config->schema())->toBe('app')
        ->and($config->charset())->toBe('utf8mb4')
        ->and($config->username())->toBe('root')
        ->and($config->password())->toBe('secret')
        ->and($config->options())->toBe([PDO::ATTR_EMULATE_PREPARES => false]);
});

it('builds the postgres dsn from every required key', function () {
    $config = PostgresConfig::from([
        'host' => 'db',
        'port' => '5432',
        'schema' => 'app',
        'username' => 'postgres',
        'password' => 'secret',
    ]);

    expect($config->dsn())->toBe('pgsql:host=db;port=5432;dbname=app')
        ->and($config->port())->toBe(5432)
        ->and($config->username())->toBe('postgres');
});

it('fails fast on a missing required key', function () {
    SqliteConfig::from([]);
})->throws(ConnectionException::class);

it('fails fast on a malformed port', function () {
    MysqlConfig::from([
        'host' => 'localhost',
        'port' => 'not-a-port',
        'schema' => 'app',
        'charset' => 'utf8mb4',
        'username' => 'root',
        'password' => 'secret',
    ]);
})->throws(ConnectionException::class);

it('fails fast on an empty required value', function () {
    SqliteConfig::from(['database' => '   ']);
})->throws(ConnectionException::class);

it('rejects non-string configuration keys', function () {
    SqliteConfig::from([':memory:']);
})->throws(ConnectionException::class);
